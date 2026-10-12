<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Paths;
use App\Repositories\DocumentRepository;
use App\Services\Documents\ImageFetcher;
use App\Services\Documents\MarkdownRenderer;
use App\Services\Documents\PdfRenderer;
use App\Services\Documents\Variant;
use RuntimeException;
use Throwable;

/**
 * Generated documents. Each one is derived from exactly one cache version and recorded with
 * its options, checksum and size. An existing document is reused when its version and
 * options match and its file still passes the checksum; otherwise it is regenerated.
 * Generation never contacts the exam provider and never modifies the dataset.
 */
final class DocumentStore
{
    /** Bump when rendering output changes, so stale documents are regenerated. */
    public const RENDERER_VERSION = 1;
    public const FORMATS = ['md' => 'text/markdown; charset=utf-8', 'pdf' => 'application/pdf'];

    public function __construct(
        private Config $config,
        private Paths $paths,
        private Logger $log,
        private DocumentRepository $docs,
        private CacheService $cache,
    ) {
    }

    public function imagesAvailable(): bool
    {
        return (bool) $this->config->get('documents.pdf_images', false);
    }

    /**
     * A ready document for (version, format, variant), generated if necessary.
     *
     * @param array<string,mixed> $version row from CacheVersionRepository::find
     * @return array<string,mixed> documents row
     */
    public function obtain(array $version, string $format, string $variantKey, bool $images = false): array
    {
        if (!isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException('Unsupported format.');
        }
        $variant = Variant::get($variantKey, $format === 'pdf' && $images && $this->imagesAvailable());
        $options = $variant->options() + ['format' => $format, 'renderer' => self::RENDERER_VERSION];
        $hash = hash('sha256', (string) json_encode($options));

        $existing = $this->docs->findByKey((int) $version['id'], $format, $variant->key, $hash);
        if ($existing !== null && $existing['status'] === 'ready' && $this->verifiedPath($existing) !== null) {
            return $existing;
        }

        $dir = $this->paths->documentDir((string) $version['provider_slug'], (string) $version['exam_code'], (string) $version['version']);
        $file = $variant->key . ($variant->images ? '-images' : '') . '.' . $format;
        $rel = substr($dir . '/' . $file, strlen($this->paths->documentsRoot()) + 1);
        $record = [
            'cache_version_id' => (int) $version['id'], 'format' => $format, 'variant' => $variant->key,
            'options_hash' => $hash, 'options' => $options, 'rel_path' => $rel,
            'filename' => $this->downloadName($version, $variant, $format),
        ];

        // A shared lock on the dataset file: deleting the version needs an exclusive lock,
        // so it cannot happen while this document is being generated.
        $datasetPath = $this->cache->datasetPath($version);
        $lock = @fopen($datasetPath, 'rb');
        if ($lock === false || !flock($lock, LOCK_SH)) {
            throw new RuntimeException('The cached data file for this version is missing.');
        }
        $started = microtime(true);
        try {
            @set_time_limit((int) $this->config->get('documents.pdf_time_limit', 600));
            if ($format === 'pdf') {
                @ini_set('memory_limit', (string) $this->config->get('documents.pdf_memory_limit', '1024M'));
            }
            $dataset = $this->cache->load($version, true);
            $meta = $this->meta($version, $dataset);
            $content = $format === 'md'
                ? (new MarkdownRenderer())->render($dataset, $meta, $variant)
                : $this->pdfRenderer($variant)->render($dataset, $meta, $variant);
            Paths::atomicWrite($dir . '/' . $file, $content);
            $id = $this->docs->save($record + [
                'status' => 'ready', 'size_bytes' => strlen($content), 'checksum' => hash('sha256', $content), 'error' => null,
            ]);
            $this->log->info('documents', 'Document generated', [
                'document_id' => $id, 'version' => $version['version'], 'exam' => $version['exam_code'],
                'variant' => $variant->key, 'format' => $format, 'bytes' => strlen($content),
                'seconds' => round(microtime(true) - $started, 2),
            ]);
            return (array) $this->docs->find($id);
        } catch (Throwable $e) {
            $this->docs->save($record + ['status' => 'failed', 'size_bytes' => null, 'checksum' => null,
                'error' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->log->error('documents', 'Document generation failed', [
                'version' => $version['version'], 'variant' => $variant->key, 'format' => $format, 'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Document generation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The document's file path if it is inside the documents root and matches its recorded
     * checksum; null otherwise (missing, tampered or corrupted).
     *
     * @param array<string,mixed> $doc
     */
    public function verifiedPath(array $doc): ?string
    {
        if (($doc['status'] ?? '') !== 'ready' || !is_string($doc['rel_path'] ?? null)) {
            return null;
        }
        $path = Paths::resolveInside($this->paths->documentsRoot(), $doc['rel_path']);
        if ($path === null || !is_file($path) || filesize($path) !== (int) $doc['size_bytes']) {
            return null;
        }
        return hash_equals((string) $doc['checksum'], (string) hash_file('sha256', $path)) ? $path : null;
    }

    /** @param array<string,mixed> $doc */
    public function delete(array $doc): void
    {
        if (is_string($doc['rel_path'] ?? null)) {
            $path = Paths::resolveInside($this->paths->documentsRoot(), $doc['rel_path']);
            if ($path !== null) {
                @unlink($path);
            }
        }
        $this->docs->delete((int) $doc['id']);
        $this->log->info('documents', 'Document deleted', ['document_id' => (int) $doc['id']]);
    }

    public function deleteForVersion(int $versionId): void
    {
        foreach ($this->docs->forVersion($versionId) as $doc) {
            $this->delete($doc);
        }
    }

    /**
     * Safe download name, e.g. Huawei_H12-811_Questions_Answers_2026-10-12.pdf
     * @param array<string,mixed> $version
     */
    public function downloadName(array $version, Variant|string $variant, string $ext): string
    {
        $provider = (\App\Repositories\ProviderRepository::splitName((string) $version['provider_name'])[0] ?: ucfirst((string) $version['provider_slug']));
        $exam = strtoupper((string) $version['exam_code']);
        $label = $variant instanceof Variant ? $variant->fileLabel() : $variant;
        $date = substr((string) $version['collected_at'], 0, 10);
        $name = implode('_', array_filter([$provider, $exam, $label, $date])) . '.' . $ext;
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    }

    /**
     * @param array<string,mixed> $version
     * @param array<string,mixed> $dataset
     * @return array{provider:string,exam:string,exam_code:string,version:string,collected_at:string,status:string}
     */
    public function meta(array $version, array $dataset): array
    {
        $examName = trim((string) ($version['exam_name'] ?? '')) ?: trim((string) ($dataset['exam_name'] ?? ''));
        return [
            'provider' => (\App\Repositories\ProviderRepository::splitName((string) $version['provider_name'])[0] ?: ucfirst((string) $version['provider_slug'])),
            'exam' => $examName !== '' ? $examName : strtoupper((string) $version['exam_code']),
            'exam_code' => (string) $version['exam_code'],
            'version' => (string) $version['version'],
            'collected_at' => gmdate('Y-m-d H:i', (int) strtotime($version['collected_at'] . ' UTC')) . ' UTC',
            'status' => (string) $version['status'],
        ];
    }

    private function pdfRenderer(Variant $variant): PdfRenderer
    {
        $fetcher = $variant->images ? new ImageFetcher(
            array_map('strtolower', (array) $this->config->get('documents.image_hosts', [])),
            (int) $this->config->get('documents.image_max_bytes', 2097152),
            (int) $this->config->get('documents.image_max_count', 300),
            (int) $this->config->get('documents.image_total_bytes', 52428800),
            (int) $this->config->get('documents.image_timeout', 10),
        ) : null;
        $tmp = $this->paths->storage() . '/tmp/dompdf';
        Paths::ensureDir($tmp);
        return new PdfRenderer($fetcher, $tmp);
    }
}
