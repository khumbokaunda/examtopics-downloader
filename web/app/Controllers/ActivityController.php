<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Services\Access;
use App\Services\AuditLog;
use App\Services\AuditText;

/**
 * The activity history (activity.view): who did what and when, in plain English, filtered by
 * dates, person and kind of action, with every matching entry as a CSV download and a check
 * that nothing in the history was changed afterwards.
 */
final class ActivityController extends Controller
{
    public function index(): Response
    {
        $filters = $this->filters();
        $result = $this->s->audit()->search($filters, 1000);
        $names = $this->names();
        $rows = array_map(static fn(array $r): array => $r + AuditText::describe($r, $names), $result['rows']);
        return $this->render('activity/index', [
            'title' => 'Activity',
            'nav' => 'activity',
            'rows' => $rows,
            'more' => $result['more'],
            'filters' => $filters,
            'people' => $this->s->users()->all(),
            'groups' => AuditLog::GROUPS,
            'names' => $names,
            'query' => self::query($filters),
        ]);
    }

    /** Every matching entry, oldest first, as CSV. */
    public function export(): Response
    {
        $filters = $this->filters();
        $names = $this->names();
        $tmp = $this->app->paths()->storage() . '/tmp/activity-' . bin2hex(random_bytes(6)) . '.csv';
        \App\Core\Paths::ensureDir(dirname($tmp));
        $out = fopen($tmp, 'wb');
        fputcsv($out, ['Time (UTC)', 'Who', 'Account', 'What', 'Action', 'Address', 'Details'], ',', '"', '');
        foreach ($this->s->audit()->each($filters) as $r) {
            fputcsv($out, [$r['created_at'], AuditText::who($r, $names), (string) $r['actor'], AuditText::describe($r, $names)['text'],
                $r['action'], (string) ($r['ip'] ?? ''), (string) ($r['details'] ?? '')], ',', '"', '');
        }
        fclose($out);
        register_shutdown_function(static fn() => @unlink($tmp));
        $this->s->audit()->record('activity.exported', array_filter($filters));
        return Response::download($tmp, 'activity-' . gmdate('Ymd-His') . '.csv', 'text/csv; charset=utf-8');
    }

    /** Walk the whole history and say whether every entry is as it was written. */
    public function verify(): Response
    {
        @set_time_limit(300);
        $check = $this->s->audit()->verify();
        if ($check['ok']) {
            $this->flash('success', 'The history is intact: all ' . number_format($check['checked']) . ' entries are as they were written.');
        } else {
            $this->app->logger()->warning('security', 'Activity history check failed', ['broken_at' => $check['broken_at']]);
            $this->flash('danger', 'The history was changed: entry #' . $check['broken_at'] . ' does not match what was written after the '
                . number_format($check['checked']) . ' entries before it. Compare with a backup, or run php bin/verify-audit.php on the server.');
        }
        return $this->redirect('/activity', self::query($this->filters()));
    }

    /**
     * The filters as a query string for links. "All time" is from=all, since an empty value
     * is dropped from links and would bring back the thirty-day default.
     *
     * @param array{from:string,to:string,who:string,group:string} $filters
     * @return array<string,string>
     */
    private static function query(array $filters): array
    {
        return ['from' => $filters['from'] === '' ? 'all' : $filters['from']] + array_filter($filters);
    }

    /** @return array{from:string,to:string,who:string,group:string} */
    private function filters(): array
    {
        $date = static fn(?string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1 ? (string) $v : '';
        $from = $this->request->query('from');
        return [
            // Thirty days unless the page asks for something else; an empty "from" means all.
            'from' => $from === null ? gmdate('Y-m-d', time() - 30 * 86400) : $date($from),
            'to' => $date($this->request->query('to')),
            'who' => preg_match('/^(u\d+|system|visitor)$/', (string) $this->request->query('who', '')) === 1 ? (string) $this->request->query('who') : '',
            'group' => isset(AuditLog::GROUPS[(string) $this->request->query('group', '')]) ? (string) $this->request->query('group') : '',
        ];
    }

    /** @return array<int,string> display names of current accounts, by id */
    private function names(): array
    {
        $names = [];
        foreach ($this->s->users()->all() as $u) {
            $names[(int) $u['id']] = Access::displayName($u);
        }
        return $names;
    }
}
