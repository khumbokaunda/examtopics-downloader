<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\CacheVersionRepository;
use App\Repositories\DocumentRepository;
use App\Services\Documents\QuestionView;
use Throwable;

/**
 * What needs checking in each exam of the library, for the integrity page: answers that look
 * doubtful, questions ExamTopics lists that were not collected, images not saved, and
 * documents made before the latest corrections or images.
 *
 * Reading an exam's questions is the slow part (a quarter of a second or more for a large
 * exam), so it never happens while the page waits. What it gives is kept in exam_checks, and a
 * background process (bin/integrity.php, started by the page) works it out again when the
 * exam's best version or its corrections change. Until then the page shows the figures it has,
 * marked as updating. Images and documents are looked up on every visit, since a download or a
 * build changes them.
 */
final class IntegrityChecks
{
    /** Kinds of problem, in the order the page lists them. */
    public const ISSUES = [
        'answers' => 'Answers to check',
        'missing' => 'Questions missing',
        'images' => 'Images not saved',
        'documents' => 'Documents to rebuild',
        'partial' => 'Partly collected',
        'unreadable' => 'Data unreadable',
    ];

    public function __construct(
        private Database $db,
        private CacheVersionRepository $versions,
        private CacheService $cache,
        private Corrections $corrections,
        private MediaStore $media,
        private DocumentRepository $documents,
        /** Held while bin/integrity.php works, so only one runs at a time. */
        private string $lockPath,
        /** Starts bin/integrity.php; null works exams out in the request instead. */
        private ?\Closure $launcher = null,
    ) {
    }

    /**
     * Every exam with cached data and what needs checking in it. An exam whose figures are out
     * of date comes back with the figures kept for it and "updating" set, or as "pending" when
     * none were kept yet; without a background process it is worked out now instead.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $kept = $this->kept();
        $mark = $this->media->mark();
        $out = [];
        foreach ($this->versions->library() as $exam) {
            if (is_array($exam['best'] ?? null)) {
                $out[] = $this->forExam($exam, $kept[(int) $exam['exam_id']] ?? null, $mark);
            }
        }
        return $out;
    }

    /** How many exams have figures to work out: a quick count, without the images and documents. */
    public function waiting(): int
    {
        $kept = $this->kept();
        $n = 0;
        foreach ($this->versions->library() as $exam) {
            if (is_array($exam['best'] ?? null)) {
                $n += $this->outdated($kept[(int) $exam['exam_id']] ?? null, (array) $exam['best'], $this->fingerprint((int) $exam['exam_id'])) ? 1 : 0;
            }
        }
        return $n;
    }

    /** Whether figures are worked out in a background process rather than in the request. */
    public function inBackground(): bool
    {
        return $this->launcher !== null;
    }

    /** Start the background process, unless it is already running. False when it could not be started. */
    public function start(): bool
    {
        return $this->launcher === null || $this->running() || (bool) ($this->launcher)();
    }

    public function running(): bool
    {
        return (new WorkerLock($this->lockPath))->isHeld();
    }

    /**
     * Work out every exam whose figures are missing or out of date (bin/integrity.php). Passes
     * are repeated while exams changed during the last one, a few times at most.
     *
     * @return int|null how many exams were worked out; null when another process is doing it
     */
    public function workOutAll(): ?int
    {
        $lock = new WorkerLock($this->lockPath);
        if (!$lock->acquire()) {
            return null;
        }
        try {
            $done = 0;
            for ($pass = 0; $pass < 3; $pass++) {
                $kept = $this->kept();
                $now = 0;
                foreach ($this->versions->library() as $exam) {
                    $examId = (int) $exam['exam_id'];
                    if (!is_array($exam['best'] ?? null)) {
                        continue;
                    }
                    $fingerprint = $this->fingerprint($examId);
                    if ($this->outdated($kept[$examId] ?? null, (array) $exam['best'], $fingerprint)) {
                        $this->workOut($examId, (array) $exam['best'], $fingerprint);
                        $now++;
                    }
                }
                $done += $now;
                if ($now === 0) {
                    break;
                }
            }
            return $done;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string,mixed> $exam a library row (with its best version)
     * @param array<string,mixed>|null $check the figures kept for it
     * @param string $mark MediaStore::mark() now: image counts kept under it are still right
     * @return array<string,mixed>
     */
    private function forExam(array $exam, ?array $check, string $mark): array
    {
        $version = (array) $exam['best'];
        $examId = (int) $exam['exam_id'];
        $row = [
            'exam' => $exam, 'version' => $version, 'state' => 'ok', 'error' => null, 'updating' => false,
            'questions' => (int) $version['collected_count'], 'reported' => $exam['reported_count'] === null ? null : (int) $exam['reported_count'],
            'to_check' => 0, 'no_answer' => 0, 'corrected' => 0, 'checked' => 0, 'edited' => 0,
            'images' => null, 'images_to_fetch' => 0, 'stale_documents' => 0,
            'partial' => $version['status'] === 'partial', 'issues' => [],
        ];
        // ExamTopics' count can be lower than what was collected when the inventory is old; only
        // a shortfall counts.
        $row['missing'] = $row['reported'] !== null ? max(0, $row['reported'] - $row['questions']) : 0;

        $fingerprint = $this->fingerprint($examId);
        if ($this->outdated($check, $version, $fingerprint)) {
            if ($this->launcher === null) {
                $check = $this->workOut($examId, $version, $fingerprint);
            } elseif ($check === null) {
                $row['state'] = 'pending';
                return $this->withIssues($row);
            } else {
                $row['updating'] = true;
            }
        }
        // A data file removed since (by hand, or a failed restore) shows at once: a quick look.
        $error = $check['error'] ?? (is_file($this->cache->datasetPath($version)) ? null : 'The cached data file for this version is missing.');
        if ($error !== null) {
            $row['state'] = 'error';
            $row['error'] = (string) $error;
            return $this->withIssues($row);
        }
        foreach (['questions', 'to_check', 'no_answer', 'corrected', 'checked', 'edited'] as $k) {
            $row[$k] = (int) $check[$k];
        }
        $urls = json_decode((string) $check['image_urls'], true);
        $urls = is_array($urls) ? array_values(array_map('strval', $urls)) : [];
        // Counting images is the slow part of a visit, so the counts are kept until an image
        // download (or anything else) changes the media table.
        $counts = ($check['media_mark'] ?? null) === $mark ? json_decode((string) $check['images_known'], true) : null;
        if (!is_array($counts)) {
            $counts = $this->media->statusCounts($urls);
            $this->db->run('UPDATE exam_checks SET images_known = ?, media_mark = ? WHERE exam_id = ? AND version_id = ? AND fingerprint = ?',
                [json_encode($counts), $mark, $examId, (int) $check['version_id'], (string) $check['fingerprint']]);
        }
        $images = $this->media->summary($urls, array_map('intval', $counts));
        $row['images'] = $images;
        $row['images_to_fetch'] = $images['failed'] + $images['pending'];

        $ready = [];
        foreach ($this->documents->forVersion((int) $version['id']) as $d) {
            if ($d['status'] === 'ready') {
                $ready[$d['variant'] . '/' . $d['format']][] = $d;
            }
        }
        foreach ($ready as $group => $docs) {
            $format = substr((string) $group, (int) strrpos((string) $group, '/') + 1);
            $row['stale_documents'] += DocumentStore::staleReason($docs, $format, $fingerprint === '' ? null : $fingerprint, $images['saved']) !== null ? 1 : 0;
        }
        return $this->withIssues($row);
    }

    /** @return array<int,array<string,mixed>> every exam's kept figures, by exam id */
    private function kept(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM exam_checks') as $row) {
            $out[(int) $row['exam_id']] = $row;
        }
        return $out;
    }

    private function fingerprint(int $examId): string
    {
        return (string) $this->corrections->fingerprint($examId);
    }

    /**
     * Whether an exam's kept figures no longer fit it: none yet, another version, or other
     * corrections. Figures that record unreadable data are tried again once the data file
     * changes (a restore, for example).
     *
     * @param array<string,mixed>|null $check @param array<string,mixed> $version
     */
    private function outdated(?array $check, array $version, string $fingerprint): bool
    {
        if ($check === null || (int) $check['version_id'] !== (int) $version['id'] || (string) $check['fingerprint'] !== $fingerprint) {
            return true;
        }
        if ($check['error'] === null) {
            return false;
        }
        $changed = @filemtime($this->cache->datasetPath($version));
        return $changed !== false && $changed > (int) strtotime($check['computed_at'] . ' UTC');
    }

    /**
     * Read the version's questions with the exam's corrections, count what needs checking, and
     * keep the counts; when the data cannot be read, keep why.
     *
     * @param array<string,mixed> $version
     * @return array<string,mixed> the exam_checks row
     */
    private function workOut(int $examId, array $version, string $fingerprint): array
    {
        $row = ['exam_id' => $examId, 'version_id' => (int) $version['id'], 'fingerprint' => $fingerprint,
            'questions' => 0, 'to_check' => 0, 'no_answer' => 0, 'corrected' => 0, 'checked' => 0, 'edited' => 0, 'image_urls' => '[]', 'error' => null];
        try {
            $data = $this->cache->load($version);
            $questions = $this->corrections->apply($examId, (array) ($data['questions'] ?? []));
            unset($data);
            $row['questions'] = count($questions);
            foreach ($questions as $q) {
                if (QuestionView::doubts($q) !== []) {
                    $row['to_check']++;
                    $row['no_answer'] += QuestionView::letters((string) ($q['answer'] ?? '')) === '' ? 1 : 0;
                }
                $kind = (string) ($q['edit']['kind'] ?? '');
                if ($kind === 'corrected' || $kind === 'checked') {
                    $row[$kind]++;
                }
                $row['edited'] += isset($q['question_edit']) ? 1 : 0;
            }
            // The pictures the corrected questions show: a removed one no longer counts, an added one does.
            $row['image_urls'] = (string) json_encode(MediaStore::urlsOf(['questions' => $questions]), JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            $row['error'] = mb_substr($e->getMessage(), 0, 1000);
        }
        $row['computed_at'] = Database::now();
        $this->db->run(
            'INSERT INTO exam_checks (exam_id, version_id, fingerprint, questions, to_check, no_answer, corrected, checked, edited, image_urls, error, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE version_id = VALUES(version_id), fingerprint = VALUES(fingerprint), questions = VALUES(questions),
                 to_check = VALUES(to_check), no_answer = VALUES(no_answer), corrected = VALUES(corrected), checked = VALUES(checked),
                 edited = VALUES(edited), image_urls = VALUES(image_urls), error = VALUES(error), computed_at = VALUES(computed_at),
                 images_known = NULL, media_mark = NULL',
            array_values($row)
        );
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function withIssues(array $row): array
    {
        $row['issues'] = array_keys(array_filter([
            'answers' => $row['to_check'] > 0,
            'missing' => $row['missing'] > 0,
            'images' => $row['images_to_fetch'] > 0,
            'documents' => $row['stale_documents'] > 0,
            'partial' => $row['partial'],
            'unreadable' => $row['state'] === 'error',
        ]));
        return $row;
    }
}
