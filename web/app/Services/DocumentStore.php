<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Paths;
use App\Repositories\DocumentRepository;
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
    public const RENDERER_VERSION = 4;
    public const FORMATS = ['md' => 'text/markdown; charset=utf-8', 'pdf' => 'application/pdf'];

    public function __construct(
        private Config $config,
        private Paths $paths,
        private Logger $log,
        private DocumentRepository $docs,
        private CacheService $cache,
        private MediaStore $media,
        /** Starts bin/build-document.php for a document UUID; null builds in the request. */
        private ?\Closure $launcher = null,
    ) {
    }

    /**
     * A ready document for (version, format, variant), generated now if necessary.
     *
     * @param array<string,mixed> $version row from CacheVersionRepository::find
     * @return array<string,mixed> documents row
     */
    public function obtain(array $version, string $format, string $variantKey): array
    {
        $p = $this->prepare($version, $format, $variantKey);
        $existing = $this->docs->findByKey((int) $version['id'], $format, $p['variant']->key, $p['hash']);
        if ($existing !== null && $existing['status'] === 'ready' && $this->verifiedPath($existing) !== null) {
            return $existing;
        }
        return $this->generate($version, $p, null);
    }

    /**
     * A document for the library page: an existing one, Markdown made now, or a PDF started
     * in a background process (status "building", with progress) so a large exam never
     * holds a web request open until a proxy gives up on it.
     *
     * @param array<string,mixed> $version
     * @return array<string,mixed> documents row
     */
    public function request(array $version, string $format, string $variantKey): array
    {
        $p = $this->prepare($version, $format, $variantKey);
        $existing = $this->docs->findByKey((int) $version['id'], $format, $p['variant']->key, $p['hash']);
        if ($existing !== null) {
            $existing = $this->refresh($existing);
            if (($existing['status'] === 'ready' && $this->verifiedPath($existing) !== null) || $existing['status'] === 'building') {
                return $existing;
            }
        }
        if ($format !== 'pdf' || $this->launcher === null || !(bool) $this->config->get('documents.background', true)) {
            return $this->generate($version, $p, null);
        }
        // One attempt at a time is shown: earlier failures of this document are cleared.
        $this->docs->deleteFailed((int) $version['id'], $format, $p['variant']->key);
        $id = $this->docs->save($p['record'] + ['status' => 'building', 'size_bytes' => null, 'checksum' => null, 'error' => null]);
        $doc = (array) $this->docs->find($id);
        if (!($this->launcher)((string) $doc['uuid'])) {
            $this->docs->markFailed($id, 'The background build could not be started. See storage/logs/app.log.');
            return (array) $this->docs->find($id);
        }
        $this->log->info('documents', 'Document build started', ['document_id' => $id, 'variant' => $p['variant']->key, 'format' => $format]);
        return $doc;
    }

    /** Run a build that request() started (bin/build-document.php, in its own process). */
    public function build(string $uuid): void
    {
        $doc = $this->docs->findByUuid($uuid);
        if ($doc === null || $doc['status'] !== 'building') {
            return;
        }
        $id = (int) $doc['id'];
        $this->docs->setBuilder($id, getmypid() ?: 0);
        // Running out of memory or time ends PHP with a fatal error that no catch sees; say
        // which limit it was, so the page shows something better than "stopped".
        $docs = $this->docs;
        $config = $this->config;
        register_shutdown_function(static function () use ($docs, $id, $config): void {
            $e = error_get_last();
            $row = $docs->find($id);
            if ($e === null || $row === null || $row['status'] !== 'building' || !in_array($e['type'], [E_ERROR, E_CORE_ERROR], true)) {
                return;
            }
            $msg = str_contains($e['message'], 'Allowed memory size')
                ? 'The PDF needed more memory than the limit of ' . ini_get('memory_limit') . '. Raise documents.pdf_memory_limit in config.local.php, or use a variant without discussions.'
                : (str_contains($e['message'], 'Maximum execution time')
                    ? 'The PDF took longer than ' . (int) $config->get('documents.pdf_time_limit', 1800) . ' seconds. Raise documents.pdf_time_limit in config.local.php.'
                    : 'The build stopped: ' . $e['message']);
            $docs->markFailed($id, $msg);
        });
        try {
            $version = $this->cache->versionRow((int) $doc['cache_version_id']) ?? throw new RuntimeException('The version no longer exists.');
            $p = $this->prepare($version, (string) $doc['format'], (string) $doc['variant']);
            $last = 0.0;
            $this->generate($version, $p, function (int $done, int $total) use ($id, &$last): void {
                if (microtime(true) - $last >= 1.0 || $done === $total) {
                    $this->docs->progress($id, $done, $total);
                    $last = microtime(true);
                }
            }, $id);
        } catch (Throwable $e) {
            $this->docs->markFailed($id, $e->getMessage());
        }
    }

    /**
     * A building document whose process has gone (a restart, or out of memory) is failed,
     * so it can be started again.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public function refresh(array $doc): array
    {
        if ($doc['status'] !== 'building') {
            return $doc;
        }
        $pid = (int) ($doc['builder_pid'] ?? 0);
        $age = time() - (int) strtotime($doc['generated_at'] . ' UTC');
        $alive = $pid > 0 ? (is_dir('/proc') ? is_dir('/proc/' . $pid) : (function_exists('posix_kill') && posix_kill($pid, 0))) : $age < 60;
        if ($alive) {
            return $doc;
        }
        $this->docs->markFailed((int) $doc['id'], 'The build stopped before it finished (the server restarted, or it ran out of time or memory). Try again.');
        return (array) $this->docs->find((int) $doc['id']);
    }

    /**
     * @param array<string,mixed> $version
     * @return array{variant:Variant,hash:string,record:array<string,mixed>,imageUrls:list<string>,dir:string,file:string}
     */
    private function prepare(array $version, string $format, string $variantKey): array
    {
        if (!isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException('Unsupported format.');
        }
        $variant = Variant::get($variantKey);
        $options = $variant->options() + ['format' => $format, 'renderer' => self::RENDERER_VERSION];
        $imageUrls = [];
        if ($format === 'pdf') {
            // PDFs embed the images saved on this server. Saving more of them (a later image
            // download) changes the options, so the PDF is generated again with them.
            $imageUrls = MediaStore::urlsOf($this->cache->load($version));
            $options['images'] = count($this->media->savedHashes($imageUrls));
        }
        $hash = hash('sha256', (string) json_encode($options));
        $dir = $this->paths->documentDir((string) $version['provider_slug'], (string) $version['exam_code'], (string) $version['version']);
        $file = $variant->key . '.' . $format;
        return [
            'variant' => $variant, 'hash' => $hash, 'imageUrls' => $imageUrls, 'dir' => $dir, 'file' => $file,
            'record' => [
                'cache_version_id' => (int) $version['id'], 'format' => $format, 'variant' => $variant->key,
                'options_hash' => $hash, 'options' => $options,
                'rel_path' => substr($dir . '/' . $file, strlen($this->paths->documentsRoot()) + 1),
                'filename' => $this->downloadName($version, $variant, $format),
            ],
        ];
    }

    /**
     * Render and store one document.
     *
     * @param array<string,mixed> $version
     * @param array{variant:Variant,hash:string,record:array<string,mixed>,imageUrls:list<string>,dir:string,file:string} $p
     * @param (callable(int,int):void)|null $progress
     * @return array<string,mixed>
     */
    private function generate(array $version, array $p, ?callable $progress, ?int $buildingId = null): array
    {
        $variant = $p['variant'];
        $format = (string) $p['record']['format'];
        $record = $p['record'];
        // A shared lock on the dataset file: deleting the version needs an exclusive lock,
        // so it cannot happen while this document is being generated.
        $lock = @fopen($this->cache->datasetPath($version), 'rb');
        if ($lock === false || !flock($lock, LOCK_SH)) {
            throw new RuntimeException('The cached data file for this version is missing.');
        }
        $started = microtime(true);
        try {
            @set_time_limit((int) $this->config->get('documents.pdf_time_limit', 1800));
            if ($format === 'pdf') {
                @ini_set('memory_limit', (string) $this->config->get('documents.pdf_memory_limit', '2048M'));
            }
            $dataset = $this->cache->load($version, true);
            $dataset['questions'] = array_map([\App\Services\Documents\QuestionView::class, 'normalize'],
                array_values(array_filter((array) ($dataset['questions'] ?? []), 'is_array')));
            $meta = $this->meta($version, $dataset);
            $content = $format === 'md'
                ? (new MarkdownRenderer())->render($dataset, $meta, $variant)
                : $this->pdfRenderer($p['imageUrls'])->render($dataset, $meta, $variant, $progress);
            Paths::atomicWrite($p['dir'] . '/' . $p['file'], $content);
            $id = $this->docs->save($record + [
                'status' => 'ready', 'size_bytes' => strlen($content), 'checksum' => hash('sha256', $content), 'error' => null,
            ]);
            $this->docs->deleteSuperseded((int) $version['id'], $format, $variant->key, $p['hash']);
            $this->log->info('documents', 'Document generated', [
                'document_id' => $id, 'version' => $version['version'], 'exam' => $version['exam_code'],
                'variant' => $variant->key, 'format' => $format, 'bytes' => strlen($content),
                'seconds' => round(microtime(true) - $started, 2),
            ]);
            return (array) $this->docs->find($id);
        } catch (Throwable $e) {
            $this->docs->save($record + ['status' => 'failed', 'size_bytes' => null, 'checksum' => null,
                'error' => mb_substr($e->getMessage(), 0, 1000)]);
            if ($buildingId !== null) {
                $this->docs->markFailed($buildingId, $e->getMessage());
            }
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

    /** @param list<string> $imageUrls */
    private function pdfRenderer(array $imageUrls): PdfRenderer
    {
        $images = $this->media->imageSource($imageUrls, (int) $this->config->get('documents.image_total_bytes', 100 * 1024 * 1024));
        $tmp = $this->paths->storage() . '/tmp/dompdf';
        Paths::ensureDir($tmp);
        return new PdfRenderer($images, $tmp);
    }
}
