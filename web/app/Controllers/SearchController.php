<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Services\SearchIndex;

/** Search every exam in the library for a question, by words in its text or choices. */
final class SearchController extends Controller
{
    public function index(): Response
    {
        $q = mb_substr(trim((string) $this->request->query('q', '')), 0, 200);
        // Exams whose questions changed (or were never indexed) are indexed by the background check.
        $checks = $this->s->integrity();
        $waiting = $checks->waiting();
        if ($waiting > 0) {
            if ($checks->inBackground()) {
                $checks->start();
            } else {
                $checks->workOutAll();
                $waiting = 0;
            }
        }
        $found = $q !== '' ? $this->s->searchIndex()->search($q) : ['rows' => [], 'more' => false];
        $groups = [];
        foreach ($found['rows'] as $row) {
            $groups[(int) $row['exam_id']][] = $row;
        }
        return $this->render('search/index', [
            'title' => $q !== '' ? 'Search: ' . $q : 'Search questions',
            'nav' => 'search',
            'q' => $q,
            'words' => SearchIndex::words($q),
            'groups' => $groups,
            'count' => count($found['rows']),
            'more' => $found['more'],
            'waiting' => $waiting,
        ]);
    }
}
