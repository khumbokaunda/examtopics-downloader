<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Documents\QuestionView;
use App\Services\Editions;
use RuntimeException;

/**
 * An exam's objectives, and which questions cover each one. For the people who correct
 * questions (answers.edit). Questions are put under an objective by their numbers in the
 * version shown, such as "1-20, 25".
 */
final class ObjectiveController extends Controller
{
    public function index(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->query('version'));
        $objectives = $this->s->objectives()->all((int) $e['id']);
        $map = $this->s->objectives()->map((int) $e['id']);
        $questions = $this->questions($v);
        $editing = null;
        foreach ($objectives as $o) {
            if ($o['uuid'] === $this->request->query('edit')) {
                $editing = $o;
            }
        }
        $names = array_column($objectives, 'name', 'id');
        $rows = array_map(static fn(array $q): array => [
            'number' => (int) ($q['number'] ?? 0),
            'text' => (string) ($q['text'] ?? ''),
            'objective' => isset($map[QuestionView::key($q)]) ? (string) ($names[$map[QuestionView::key($q)]] ?? '') : null,
        ], $questions);
        return $this->render('library/objectives', [
            'title' => Editions::shortCode((string) $e['code']) . ' objectives',
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'objectives' => $objectives,
            'editing' => $editing,
            'rows' => $rows,
            'without' => count(array_filter($rows, static fn(array $r) => $r['objective'] === null)),
        ]);
    }

    public function save(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->input('version'));
        $svc = $this->s->objectives();
        $user = $this->auth->user() ?? [];
        $back = '/library/' . rawurlencode((string) $p['slug']) . '/' . rawurlencode((string) $e['code']) . '/objectives';
        $query = ['version' => (string) $v['version']];
        $find = function (string $field) use ($svc, $e): ?array {
            $uuid = (string) $this->request->input($field, '');
            if ($uuid === '') {
                return null;
            }
            return $svc->find((int) $e['id'], $uuid) ?? throw HttpException::notFound('Objective');
        };
        try {
            switch ((string) $this->request->input('action', '')) {
                case 'save':
                    $o = $find('objective');
                    $svc->save($e, $o, (string) $this->request->input('code', ''), (string) $this->request->input('name', ''));
                    $this->flash('success', $o === null ? 'Objective added.' : 'Objective saved.');
                    break;
                case 'remove':
                    $o = $find('objective') ?? throw HttpException::notFound('Objective');
                    $svc->remove($e, $o);
                    $this->flash('success', 'Objective removed. Its questions have no objective now.');
                    break;
                case 'topics':
                    $n = $svc->fromTopics($e, $this->questions($v), $user);
                    $this->flash('success', $n . ' ' . ($n === 1 ? 'objective' : 'objectives') . ' made from the topics. Rename them to match the exam guide.');
                    break;
                case 'assign':
                    $o = $find('objective');
                    $numbers = self::numbers((string) $this->request->input('numbers', ''));
                    if ($numbers === []) {
                        throw new RuntimeException('Give question numbers, such as 1-20, 25.');
                    }
                    $keys = [];
                    foreach ($this->questions($v) as $q) {
                        if (isset($numbers[(int) ($q['number'] ?? 0)])) {
                            $keys[] = QuestionView::key($q);
                        }
                    }
                    if ($keys === []) {
                        throw new RuntimeException('None of those questions are in this version.');
                    }
                    $n = $svc->assign($e, $keys, $o, $user);
                    $this->flash('success', $o === null ? $n . ' ' . ($n === 1 ? 'question has' : 'questions have') . ' no objective now.'
                        : $n . ' ' . ($n === 1 ? 'question' : 'questions') . ' put under ' . $o['name'] . '.');
                    break;
                default:
                    throw HttpException::notFound('Action');
            }
        } catch (RuntimeException $ex) {
            $this->flash('danger', $ex->getMessage());
        }
        return $this->redirect($back, $query);
    }

    /**
     * Question numbers from "1-20, 25 30". At most 5,000, so a typo cannot ask for millions.
     *
     * @return array<int,true>
     */
    public static function numbers(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text)) ?: [] as $part) {
            if (preg_match('/^(\d{1,5})(?:\s*-\s*(\d{1,5}))?$/', $part, $m) !== 1) {
                continue;
            }
            $from = (int) $m[1];
            $to = isset($m[2]) ? (int) $m[2] : $from;
            for ($i = min($from, $to); $i <= max($from, $to) && count($out) < 5000; $i++) {
                if ($i > 0) {
                    $out[$i] = true;
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $v @return list<array<string,mixed>> */
    private function questions(array $v): array
    {
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $out = [];
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (is_array($item)) {
                $out[] = QuestionView::normalize($item);
            }
        }
        usort($out, static fn(array $a, array $b): int => (int) ($a['number'] ?? 0) <=> (int) ($b['number'] ?? 0));
        return $out;
    }
}
