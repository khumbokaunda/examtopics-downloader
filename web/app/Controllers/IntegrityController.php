<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Services\Editions;
use App\Services\IntegrityChecks;

/**
 * One page for everything in the library that needs checking: answers that look doubtful,
 * questions not collected, images not saved and documents to rebuild, across every exam, each
 * with a link to where it is fixed.
 */
final class IntegrityController extends Controller
{
    /** What the list can show; "attention" is every exam with at least one problem. */
    public const FILTERS = ['attention' => 'Needs attention', 'answers' => 'Answers to check', 'missing' => 'Questions missing',
        'images' => 'Images not saved', 'documents' => 'Documents to rebuild', 'partial' => 'Partly collected',
        'unreadable' => 'Data unreadable', 'all' => 'All exams'];
    /** Filters offered only while some exam has the problem: it is rare, and a chip at 0 is noise. */
    public const RARE = ['partial', 'unreadable'];

    public function index(): Response
    {
        $checks = $this->s->integrity();
        $rows = $checks->all();
        $show = (string) $this->request->query('show', 'attention');
        if (!isset(self::FILTERS[$show])) {
            $show = 'attention';
        }
        $counts = ['all' => count($rows), 'attention' => 0] + array_fill_keys(array_keys(IntegrityChecks::ISSUES), 0);
        $totals = ['to_check' => 0, 'no_answer' => 0, 'missing' => 0, 'images' => 0, 'documents' => 0, 'corrections' => 0, 'pending' => 0, 'updating' => 0, 'found' => 0];
        foreach ($rows as $r) {
            // Listed under "Needs attention" too while it is being checked, as nothing is known yet.
            $counts['attention'] += $r['issues'] !== [] || $r['state'] === 'pending' ? 1 : 0;
            $totals['found'] += $r['issues'] !== [] ? 1 : 0;
            foreach ($r['issues'] as $issue) {
                $counts[$issue]++;
            }
            $totals['to_check'] += $r['to_check'];
            $totals['no_answer'] += $r['no_answer'];
            $totals['missing'] += $r['missing'];
            $totals['images'] += $r['images_to_fetch'];
            $totals['documents'] += $r['stale_documents'];
            $totals['corrections'] += $r['corrected'] + $r['checked'] + $r['edited'];
            $totals['pending'] += $r['state'] === 'pending' ? 1 : 0;
            $totals['updating'] += $r['updating'] ? 1 : 0;
        }
        // Exams whose figures are out of date are worked out in the background; the page shows
        // what it has and reloads when they are done.
        $waiting = $totals['pending'] + $totals['updating'];
        $started = $waiting === 0 || $checks->start();
        $shown = array_values(array_filter($rows, static fn(array $r): bool => match ($show) {
            'all' => true,
            'attention' => $r['issues'] !== [] || $r['state'] === 'pending',
            default => in_array($show, $r['issues'], true),
        }));
        // The exams with the most to do first.
        usort($shown, static fn(array $a, array $b): int => [count($b['issues']), $b['to_check'], $b['missing']] <=> [count($a['issues']), $a['to_check'], $a['missing']]
            ?: strcmp(Editions::title($a['exam']), Editions::title($b['exam'])));
        return $this->render('integrity/index', [
            'title' => 'Integrity',
            'nav' => 'integrity',
            'rows' => $shown,
            'show' => $show,
            'counts' => $counts,
            'totals' => $totals,
            'waiting' => $waiting,
            'started' => $started,
        ]);
    }

    /**
     * For the page while exams are being checked: how many are left, so it can reload when
     * none are. Starts the check again if it stopped with exams left (corrections made since).
     */
    public function status(): Response
    {
        $checks = $this->s->integrity();
        $waiting = $checks->waiting();
        $running = $waiting > 0 && $checks->start();
        return Response::json(['waiting' => $waiting, 'running' => $running, 'line' => self::waitingLine($waiting)]);
    }

    public static function waitingLine(int $waiting): string
    {
        return 'Checking ' . number_format($waiting) . ' ' . ($waiting === 1 ? 'exam' : 'exams')
            . ' in the background. This page updates when they are done.';
    }
}
