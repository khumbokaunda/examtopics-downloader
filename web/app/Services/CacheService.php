<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Paths;
use App\Repositories\CacheVersionRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ProviderRepository;
use RuntimeException;

/**
 * The exam library's canonical store: validated JSON datasets, one immutable file per
 * version, each with a manifest. MySQL only indexes them (see rebuildIndex()).
 */
final class CacheService
{
    public const MAX_DATASET_BYTES = 256 * 1024 * 1024;

    public function __construct(
        private Database $db,
        private Paths $paths,
        private Logger $log,
        private ProviderRepository $providers,
        private ExamRepository $exams,
        private CacheVersionRepository $versions,
        private DatasetValidator $validator,
    ) {
    }

    /**
     * Validate $datasetFile and publish it as a new version. The previous versions are never
     * touched; a partial dataset is published as a partial version and never becomes the
     * exam's latest complete version.
     *
     * @param array<string,mixed> $provider providers row
     * @param array<string,mixed> $exam     exams row
     * @param array<string,mixed> $options  collection options recorded in the manifest
     * @return array{version_id:int,version:string,status:string,warnings:list<string>,collected:int}
     * @throws PublishException when the dataset is invalid
     */
    public function publish(array $provider, array $exam, string $datasetFile, ?int $jobId, array $options): array
    {
        $size = @filesize($datasetFile);
        if ($size === false || $size === 0) {
            throw new PublishException(['The tool produced no output file.']);
        }
        if ($size > self::MAX_DATASET_BYTES) {
            throw new PublishException(['The output file is larger than the ' . (self::MAX_DATASET_BYTES >> 20) . ' MB limit.']);
        }
        $raw = (string) file_get_contents($datasetFile);
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PublishException(['The output is not valid JSON: ' . $e->getMessage()]);
        }
        $reported = $exam['reported_count'] === null ? null : (int) $exam['reported_count'];
        $result = $this->validator->validate($data, (string) $provider['slug'], (string) $exam['code'], $reported);
        if ($result['errors'] !== []) {
            throw new PublishException($result['errors']);
        }

        $version = Ids::newVersion();
        $slug = (string) $provider['slug'];
        $code = (string) $exam['code'];
        $checksum = hash('sha256', $raw);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $collectedAt = $this->toUtc((string) ($data['collected_at'] ?? '')) ?? gmdate('Y-m-d H:i:s');

        $manifest = [
            'schema_version' => 1,
            'kind' => 'manifest',
            'provider' => $slug,
            'exam' => $code,
            'exam_name' => (string) ($data['exam_name'] ?? ''),
            'version' => $version,
            'file' => $version . '.json',
            'status' => $data['status'],
            'source' => $data['source'],
            'collected_at' => (string) ($data['collected_at'] ?? ''),
            'published_at' => $now,
            'reported_count' => $reported,
            'collected_count' => count($data['questions']),
            'counts' => $data['counts'] ?? [],
            'options' => $options,
            'tool' => $data['tool'] ?? null,
            'checksum' => ['algorithm' => 'sha256', 'value' => $checksum],
            'size' => strlen($raw),
            'dataset_schema_version' => $data['schema_version'],
            'job_id' => $jobId,
            'warnings' => $result['warnings'],
        ];

        // Dataset first, then manifest, then the index row. A crash in between leaves at
        // worst an unindexed version that rebuildIndex() recovers; never a row pointing at a
        // missing file.
        Paths::atomicWrite($this->paths->versionFile($slug, $code, $version), $raw);
        Paths::atomicWrite(
            $this->paths->manifestFile($slug, $code, $version),
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $versionId = $this->db->transaction(function () use ($exam, $version, $data, $collectedAt, $reported, $checksum, $raw, $result, $jobId): int {
            $id = $this->versions->insert([
                'exam_id' => (int) $exam['id'],
                'version' => $version,
                'status' => $data['status'],
                'source' => $data['source'],
                'collected_at' => $collectedAt,
                'published_at' => Database::now(),
                'reported_count' => $reported,
                'collected_count' => count($data['questions']),
                'checksum' => $checksum,
                'size_bytes' => strlen($raw),
                'schema_version' => $data['schema_version'],
                'tool_revision' => is_array($data['tool'] ?? null) ? (string) ($data['tool']['revision'] ?? '') : null,
                'warnings' => $result['warnings'],
                'job_id' => $jobId,
            ]);
            if ($data['status'] === 'complete') {
                $this->exams->setLatestVersion((int) $exam['id'], $id);
            }
            $this->exams->setName((int) $exam['id'], (string) ($data['exam_name'] ?? ''));
            return $id;
        });

        $this->log->info('jobs', 'Published cache version', [
            'job_id' => $jobId, 'exam' => $slug . '/' . $code, 'version' => $version,
            'status' => $data['status'], 'questions' => count($data['questions']),
        ]);
        return [
            'version_id' => $versionId,
            'version' => $version,
            'status' => (string) $data['status'],
            'warnings' => $result['warnings'],
            'collected' => count($data['questions']),
        ];
    }

    /** @param array<string,mixed> $versionRow row from CacheVersionRepository::find */
    public function datasetPath(array $versionRow): string
    {
        return $this->paths->versionFile((string) $versionRow['provider_slug'], (string) $versionRow['exam_code'], (string) $versionRow['version']);
    }

    /**
     * Load a version's dataset. With $verify, the SHA-256 must match the recorded checksum.
     *
     * @param array<string,mixed> $versionRow
     * @return array<string,mixed>
     */
    public function load(array $versionRow, bool $verify = false): array
    {
        $path = $this->datasetPath($versionRow);
        if (!is_file($path)) {
            throw new RuntimeException('The cached data file for this version is missing.');
        }
        if (filesize($path) !== (int) $versionRow['size_bytes']) {
            throw new RuntimeException('The cached data file for this version has the wrong size (corrupted?).');
        }
        if ($verify && !hash_equals((string) $versionRow['checksum'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('The cached data file for this version failed its checksum (corrupted?).');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('The cached data file for this version is not valid JSON.');
        }
        return is_array($data) ? $data : throw new RuntimeException('Unexpected cached data format.');
    }

    /**
     * Delete one version: its documents, manifest, dataset and index rows. Refuses the exam's
     * latest complete version, and any version while a collection for the exam is active or a
     * document is being generated from it.
     *
     * @param array<string,mixed> $versionRow
     */
    public function deleteVersion(array $versionRow, DocumentStore $documents): void
    {
        $exam = $this->exams->findById((int) $versionRow['exam_id']);
        if ($exam !== null && (int) $exam['latest_version_id'] === (int) $versionRow['id']) {
            throw new RuntimeException('This is the latest complete version; it cannot be deleted.');
        }
        $active = $this->db->value(
            "SELECT id FROM jobs WHERE type = 'collect' AND status IN ('queued','running') AND provider_id = ? AND exam_code = ?",
            [(int) ($exam['provider_id'] ?? 0), (string) $versionRow['exam_code']]
        );
        if ($active !== null) {
            throw new RuntimeException('A collection job for this exam is active; try again when it has finished.');
        }
        $path = $this->datasetPath($versionRow);
        $fh = @fopen($path, 'rb');
        if ($fh !== false && !flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new RuntimeException('A document is being generated from this version; try again shortly.');
        }
        try {
            $documents->deleteForVersion((int) $versionRow['id']);
            @unlink($this->paths->manifestFile((string) $versionRow['provider_slug'], (string) $versionRow['exam_code'], (string) $versionRow['version']));
            @unlink($path);
            $this->versions->delete((int) $versionRow['id']);
        } finally {
            if ($fh !== false) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
        $this->log->info('app', 'Deleted cache version', ['version' => $versionRow['version'], 'exam' => $versionRow['exam_code']]);
    }

    /**
     * Rebuild the version index from manifests on disk (after database loss). Existing rows
     * are kept; missing ones are recreated; corrupt files are reported, not indexed.
     *
     * @return array{indexed:int,existing:int,skipped:list<string>}
     */
    public function rebuildIndex(): array
    {
        $report = ['indexed' => 0, 'existing' => 0, 'skipped' => []];
        foreach (glob($this->paths->cacheRoot() . '/*/*/*.manifest.json') ?: [] as $file) {
            $m = json_decode((string) file_get_contents($file), true);
            $label = substr($file, strlen($this->paths->cacheRoot()) + 1);
            if (!is_array($m) || ($m['kind'] ?? null) !== 'manifest'
                || !Ids::isProvider($m['provider'] ?? null) || !Ids::isExam($m['exam'] ?? null) || !Ids::isVersion($m['version'] ?? null)) {
                $report['skipped'][] = $label . ': unreadable manifest';
                continue;
            }
            $data = $this->paths->versionFile($m['provider'], $m['exam'], $m['version']);
            if (!is_file($data) || !hash_equals((string) ($m['checksum']['value'] ?? ''), (string) hash_file('sha256', $data))) {
                $report['skipped'][] = $label . ': dataset missing or checksum mismatch';
                continue;
            }
            $providerId = $this->providers->upsert($m['provider'], null, 'manual');
            $examId = $this->exams->ensure($providerId, $m['exam'], (string) ($m['exam_name'] ?? ''));
            if ($this->versions->findByVersion($examId, $m['version']) !== null) {
                $report['existing']++;
                continue;
            }
            $this->versions->insert([
                'exam_id' => $examId,
                'version' => $m['version'],
                'status' => $m['status'],
                'source' => $m['source'],
                'collected_at' => $this->toUtc((string) $m['collected_at']) ?? gmdate('Y-m-d H:i:s'),
                'published_at' => $this->toUtc((string) $m['published_at']) ?? gmdate('Y-m-d H:i:s'),
                'reported_count' => $m['reported_count'],
                'collected_count' => (int) $m['collected_count'],
                'checksum' => (string) $m['checksum']['value'],
                'size_bytes' => (int) filesize($data),
                'schema_version' => (int) $m['dataset_schema_version'],
                'tool_revision' => is_array($m['tool'] ?? null) ? (string) ($m['tool']['revision'] ?? '') : null,
                'warnings' => $m['warnings'] ?? [],
                'job_id' => null,
            ]);
            $this->exams->setLatestVersion($examId, $this->versions->latestCompleteId($examId));
            $report['indexed']++;
        }
        return $report;
    }

    private function toUtc(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }
        $t = strtotime($iso);
        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }
}
