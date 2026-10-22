<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Services\Access;
use App\Services\StudyProgress;

/**
 * Study progress pages: a person's own (every exam they practised or read, with how they did),
 * and for those with progress.view_all, everyone's at a glance and each person's in full.
 */
final class ProgressController extends Controller
{
    public function mine(): Response
    {
        $user = (array) $this->auth->user();
        return $this->render('progress/index', [
            'title' => 'My progress',
            'nav' => 'progress',
            'person' => $user,
            'own' => true,
        ] + $this->report((int) $user['id']));
    }

    public function everyone(): Response
    {
        return $this->render('progress/people', [
            'title' => 'Everyone\'s progress',
            'nav' => 'progress',
            'people' => $this->s->progress()->people(),
        ]);
    }

    public function person(string $ref): Response
    {
        $person = $this->findAccount($ref);
        if ((int) $person['id'] === (int) ($this->auth->user()['id'] ?? 0)) {
            return $this->redirect('/progress');
        }
        if ($ref !== $person['uuid']) {
            return $this->redirect(self::accountPath($person, '/progress/people/')); // an old link by username
        }
        return $this->render('progress/index', [
            'title' => Access::displayName($person) . '\'s progress',
            'nav' => 'progress',
            'person' => $person,
            'own' => false,
        ] + $this->report((int) $person['id']));
    }

    /**
     * One person's progress: a row per exam they answered questions in or opened, most recent
     * first, and their totals.
     *
     * @return array{rows:list<array<string,mixed>>,totals:array{exams:int,answered:int,correct:int,mistakes:int,percent:?int,last_at:?string}}
     */
    private function report(int $userId): array
    {
        $library = [];
        foreach ($this->s->versions()->library() as $r) {
            $library[(int) $r['exam_id']] = $r;
        }
        $answers = $this->s->progress()->byExam($userId);
        $places = [];
        foreach ($this->s->progress()->recent($userId, 50) as $p) {
            $places[$p['exam_id']] = $p;
        }
        $rows = [];
        foreach (array_unique(array_merge(array_keys($answers), array_keys($places))) as $id) {
            if (!isset($library[$id])) {
                continue; // no longer in the library
            }
            $t = $answers[$id] ?? ['answered' => 0, 'correct' => 0, 'mistakes' => 0, 'last_at' => null];
            $place = $places[$id] ?? null;
            $rows[] = $library[$id] + [
                'progress' => $t,
                'place' => $place,
                'last' => max((string) ($t['last_at'] ?? ''), (string) ($place['updated_at'] ?? '')),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($b['last'], $a['last']));
        $answered = array_sum(array_map(static fn(array $r): int => $r['progress']['answered'], $rows));
        $correct = array_sum(array_map(static fn(array $r): int => $r['progress']['correct'], $rows));
        return [
            'rows' => $rows,
            'totals' => [
                'exams' => count($rows),
                'answered' => $answered,
                'correct' => $correct,
                'mistakes' => $answered - $correct,
                'percent' => StudyProgress::percent($correct, $answered),
                'last_at' => $rows === [] ? null : $rows[0]['last'],
            ],
        ];
    }
}
