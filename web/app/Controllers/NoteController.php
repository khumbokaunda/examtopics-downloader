<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\Documents\QuestionView;

/** A person's own notes and bookmarks on questions, from the reader. Nobody else sees them. */
final class NoteController extends Controller
{
    private const MAX_NOTE = 5000;

    public function bookmark(string $provider, string $exam): Response
    {
        [$p, $e, $key] = $this->target($provider, $exam);
        $on = $this->s->notes()->toggleBookmark((int) ($this->auth->user()['id'] ?? 0), (int) $e['id'], $key);
        if ($this->request->wantsJson()) {
            return Response::json(['saved' => $on]);
        }
        $n = (int) $this->request->input('number', '0');
        $this->flash('success', "Question $n " . ($on ? 'is bookmarked.' : 'is no longer bookmarked.'));
        return $this->backToReader($p, $e, $this->findVersion($e, $this->request->input('version')), $n);
    }

    public function save(string $provider, string $exam): Response
    {
        [$p, $e, $key] = $this->target($provider, $exam);
        $note = str_replace(["\r\n", "\r"], "\n", mb_substr((string) $this->request->input('note', ''), 0, self::MAX_NOTE));
        $this->s->notes()->setNote((int) ($this->auth->user()['id'] ?? 0), (int) $e['id'], $key, $note);
        $n = (int) $this->request->input('number', '0');
        $this->flash('success', "Question $n: " . (trim($note) === '' ? 'your note was removed.' : 'your note was saved. Only you can see it.'));
        return $this->backToReader($p, $e, $this->findVersion($e, $this->request->input('version')), $n);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:string} */
    private function target(string $provider, string $exam): array
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $key = (string) $this->request->input('key', '');
        if (preg_match(QuestionView::KEY_PATTERN, $key) !== 1) {
            throw HttpException::notFound('Question');
        }
        return [$p, $e, $key];
    }
}
