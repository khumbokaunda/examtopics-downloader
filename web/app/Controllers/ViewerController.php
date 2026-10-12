<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Documents\QuestionView;
use RuntimeException;

/**
 * Read-only, paginated question viewer. In practice mode the page contains no answers,
 * explanations, votes or comments, not even hidden in the HTML or in script data. Each is
 * fetched for one question at a time from reveal(), only when the user asks.
 */
final class ViewerController extends Controller
{
    public function show(string $provider, string $exam): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $this->request->query('version'));
        $mode = $this->request->query('mode') === 'practice' ? 'practice' : 'study';
        $q = mb_substr(trim((string) $this->request->query('q', '')), 0, 100);
        $perPage = max(1, min(50, (int) $this->app->config()->get('viewer.per_page', 10)));

        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $questions = array_values(array_filter((array) ($data['questions'] ?? []), 'is_array'));
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $questions = array_values(array_filter($questions, static function (array $item) use ($needle): bool {
                $hay = (string) ($item['text'] ?? '');
                foreach ((array) ($item['choices'] ?? []) as $c) {
                    $hay .= ' ' . (string) ($c['text'] ?? '');
                }
                return str_contains(mb_strtolower($hay), $needle);
            }));
        }
        $total = count($questions);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) $this->request->query('page', '1')));
        $jump = (int) $this->request->query('jump', '0');
        if ($jump > 0 && $q === '') {
            $page = max(1, min($pages, (int) ceil($jump / $perPage)));
        }
        $slice = array_slice($questions, ($page - 1) * $perPage, $perPage);
        if ($mode === 'practice') {
            $slice = array_map([self::class, 'practiceView'], $slice);
        }
        return $this->render('viewer/show', [
            'title' => strtoupper((string) $e['code']) . ($mode === 'practice' ? ' practice' : ' study'),
            'nav' => 'library',
            'provider' => $p,
            'exam' => $e,
            'version' => $v,
            'questions' => $slice,
            'mode' => $mode,
            'q' => $q,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'all' => count((array) ($data['questions'] ?? [])),
        ]);
    }

    /** Answer details for one question (practice mode reveal). */
    public function reveal(string $provider, string $exam, string $version, string $number): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        if (!ctype_digit($number)) {
            throw HttpException::notFound('Question');
        }
        try {
            $data = $this->s->cache()->load($v);
        } catch (RuntimeException $ex) {
            throw new HttpException(409, $ex->getMessage());
        }
        $q = null;
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['number'] ?? 0) === (int) $number) {
                $q = $item;
                break;
            }
        }
        if ($q === null) {
            throw HttpException::notFound('Question');
        }
        return Response::json([
            'number' => (int) $q['number'],
            'answer' => QuestionView::answerLine($q),
            'votes' => QuestionView::votesLine($q),
            'explanation' => (string) ($q['explanation'] ?? ''),
            'answer_images' => array_values(array_filter(array_map(
                static fn($u) => QuestionView::safeImageUrl((string) $u), (array) ($q['answer_images'] ?? []))
            )),
            'comments' => QuestionView::comments($q),
        ]);
    }

    /**
     * Strip everything answer-revealing from a question before it reaches the template.
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    public static function practiceView(array $q): array
    {
        return [
            'number' => $q['number'] ?? null,
            'topic' => $q['topic'] ?? null,
            'question_number' => $q['question_number'] ?? null,
            'text' => $q['text'] ?? '',
            'choices' => $q['choices'] ?? [],
            'question_images' => $q['question_images'] ?? [],
            'source_url' => $q['source_url'] ?? '',
        ];
    }
}
