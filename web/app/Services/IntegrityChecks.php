<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\CacheVersionRepository;
use App\Repositories\DocumentRepository;
use App\Services\Documents\QuestionView;
use RuntimeException;

/**
 * What needs checking in each exam of the library, for the integrity page: answers that look
 * doubtful, questions ExamTopics lists that were not collected, images not saved, and
 * documents made before the latest corrections or images.
 *
 * Reading an exam's questions is the slow part, so what it gives is kept in exam_checks and
 * worked out again only when the exam's best version or its corrections change. Images and
 * documents are looked up on every visit, since a download or a build changes them.
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
    ) {
    }

    /**
     * Every exam with cached data. Exams not worked out yet are worked out until $budget
     * seconds have passed; the rest come back as pending, for the next visit.
     *
     * @return list<array<string,mixed>>
     */
    public function all(float $budget = 10.0): array
    {
        $started = microtime(true);
        $out = [];
        foreach ($this->versions->library() as $exam) {
            if (!is_array($exam['best'] ?? null)) {
                continue;
            }
            $out[] = $this->forExam($exam, microtime(true) - $started < $budget);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $exam a library row (with its best version)
     * @return array<string,mixed>
     */
    public function forExam(array $exam, bool $mayWorkOut = true): array
    {
        $version = (array) $exam['best'];
        $examId = (int) $exam['exam_id'];
        $row = [
            'exam' => $exam, 'version' => $version, 'state' => 'ok', 'error' => null,
            'questions' => (int) $version['collected_count'], 'reported' => $exam['reported_count'] === null ? null : (int) $exam['reported_count'],
            'to_check' => 0, 'no_answer' => 0, 'corrected' => 0, 'checked' => 0, 'edited' => 0,
            'images' => null, 'images_to_fetch' => 0, 'stale_documents' => 0,
            'partial' => $version['status'] === 'partial', 'issues' => [],
        ];
        // ExamTopics' count can be lower than what was collected when the inventory is old; only
        // a shortfall counts.
        $row['missing'] = $row['reported'] !== null ? max(0, $row['reported'] - $row['questions']) : 0;

        $fingerprint = $this->corrections->fingerprint($examId);
        $check = $this->db->one('SELECT * FROM exam_checks WHERE exam_id = ?', [$examId]);
        if ($check === null || (int) $check['version_id'] !== (int) $version['id'] || (string) $check['fingerprint'] !== (string) $fingerprint) {
            if (!$mayWorkOut) {
                $row['state'] = 'pending';
                return $this->withIssues($row);
            }
            try {
                $check = $this->workOut($examId, $version, (string) $fingerprint);
            } catch (RuntimeException $e) {
                $row['state'] = 'error';
                $row['error'] = $e->getMessage();
                return $this->withIssues($row);
            }
        }
        foreach (['questions', 'to_check', 'no_answer', 'corrected', 'checked', 'edited'] as $k) {
            $row[$k] = (int) $check[$k];
        }
        $urls = json_decode((string) $check['image_urls'], true);
        $images = $this->media->summary(is_array($urls) ? array_values(array_map('strval', $urls)) : []);
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
            $row['stale_documents'] += DocumentStore::staleReason($docs, $format, $fingerprint, $images['saved']) !== null ? 1 : 0;
        }
        return $this->withIssues($row);
    }

    /**
     * Read the version's questions with the exam's corrections, count what needs checking, and
     * keep the counts.
     *
     * @param array<string,mixed> $version
     * @return array<string,mixed> the exam_checks row
     */
    private function workOut(int $examId, array $version, string $fingerprint): array
    {
        $data = $this->cache->load($version);
        $questions = $this->corrections->apply($examId, (array) ($data['questions'] ?? []));
        $row = ['exam_id' => $examId, 'version_id' => (int) $version['id'], 'fingerprint' => $fingerprint,
            'questions' => count($questions), 'to_check' => 0, 'no_answer' => 0, 'corrected' => 0, 'checked' => 0, 'edited' => 0];
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
        $row['computed_at'] = Database::now();
        $this->db->run(
            'INSERT INTO exam_checks (exam_id, version_id, fingerprint, questions, to_check, no_answer, corrected, checked, edited, image_urls, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE version_id = VALUES(version_id), fingerprint = VALUES(fingerprint), questions = VALUES(questions),
                 to_check = VALUES(to_check), no_answer = VALUES(no_answer), corrected = VALUES(corrected), checked = VALUES(checked),
                 edited = VALUES(edited), image_urls = VALUES(image_urls), computed_at = VALUES(computed_at)',
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
