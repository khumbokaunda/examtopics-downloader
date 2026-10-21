<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Services\Access;
use App\Services\AuditText;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The dashboard is the person's own study page first: where to continue, their streak and
 * week, mistakes to go over, saved exams, what is new in the library and their requests.
 * Those who run the system get a section below it: jobs, documents, members' study and the
 * activity history.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $collector = $this->auth->can('exams.collect');
        if ($collector) {
            $this->s->supervisor()->ensure();
        }
        $user = (array) $this->auth->user();
        $userId = (int) $user['id'];
        $zone = new DateTimeZone($this->timezone());
        $now = new DateTimeImmutable('now', $zone);
        $today = $now->format('Y-m-d');
        $progress = $this->s->progress();

        $days = $progress->days($userId, $now->modify('-13 days')->format('Y-m-d'), $today);
        $week = ['answered' => 0, 'correct' => 0];
        foreach (array_slice($days, -7, 7, true) as $d) {
            $week['answered'] += $d['answered'];
            $week['correct'] += $d['correct'];
        }
        $totals = $progress->byExam($userId);
        $mistakes = [];
        foreach ($progress->mistakeCounts($userId, 4) as $examId => $n) {
            if (($card = $this->examCard($examId)) !== null) {
                $mistakes[] = $card + ['mistakes' => $n, 'progress' => $totals[$examId] ?? null];
            }
        }
        $saved = [];
        foreach (array_slice(array_keys($progress->saved($userId)), 0, 5) as $examId) {
            if (($card = $this->examCard($examId)) !== null) {
                $saved[] = $card + ['progress' => $totals[$examId] ?? null];
            }
        }
        $studying = $this->studying($userId, $totals);
        $jobs = $this->s->jobs();
        $system = $collector || $this->auth->can('activity.view') || $this->auth->can('progress.view_all');
        $since = $now->modify('-6 days')->format('Y-m-d');

        return $this->render('dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'greeting' => match (true) { (int) $now->format('G') < 12 => 'Good morning', (int) $now->format('G') < 17 => 'Good afternoon', default => 'Good evening' },
            'firstName' => explode(' ', trim(Access::displayName($user)))[0],
            'today' => $today,
            'studying' => $studying,
            'suggestions' => $studying === [] ? $this->suggestions() : [],
            'days' => $days,
            'week' => $week + ['mistakes' => array_sum(array_column($totals, 'mistakes'))],
            'streak' => $progress->streak($userId, $today),
            'mistakes' => $mistakes,
            'saved' => $saved,
            'news' => $this->news($zone, $now, $progress->providerSlugs($userId)),
            'requests' => $this->requests($userId),
            // Below: for those who run the system.
            'system' => $system,
            'totals' => $collector ? $this->s->versions()->totals() : null,
            'providerCount' => $collector ? $this->s->providers()->count() : 0,
            'active' => $collector ? $jobs->active() : [],
            'recentCompleted' => $collector ? $jobs->list(['status' => 'completed'], 5) : [],
            'recentFailed' => $collector ? $jobs->list(['status' => 'failed'], 5) : [],
            'recentDocuments' => $collector ? $this->s->documentRepo()->recent(6) : [],
            'jobCounts' => $collector ? $jobs->countsByStatus() : [],
            'workerRunning' => $collector && $this->s->supervisor()->workerRunning(),
            'members' => $this->auth->can('progress.view_all')
                ? ['people' => $progress->activeSince($since, 5), 'count' => $progress->activeCount($since)] : null,
            'activity' => $this->auth->can('activity.view') ? $this->activity() : null,
        ]);
    }

    /**
     * An exam with its provider and best version, for a row on the dashboard; null when it is
     * no longer in the library.
     *
     * @return array{exam:array<string,mixed>,provider:array<string,mixed>,questions:int}|null
     */
    private function examCard(int $examId): ?array
    {
        $exam = $this->s->exams()->findById($examId);
        $provider = $exam === null ? null : $this->s->providers()->find((int) $exam['provider_id']);
        $best = $exam === null ? null : $this->s->versions()->bestForExam($examId);
        return $provider === null || $best === null ? null
            : ['exam' => $exam, 'provider' => $provider, 'questions' => (int) $best['collected_count']];
    }

    /**
     * The exams this person studied most recently, with where they were and how they are doing.
     *
     * @param array<int,array<string,mixed>> $totals
     * @return list<array<string,mixed>>
     */
    private function studying(int $userId, array $totals): array
    {
        $out = [];
        foreach ($this->s->progress()->recent($userId, 4) as $place) {
            if (($card = $this->examCard($place['exam_id'])) !== null) {
                $out[] = $card + ['place' => $place,
                    'progress' => $totals[$place['exam_id']] ?? ['answered' => 0, 'correct' => 0, 'mistakes' => 0, 'last_at' => null]];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> a few exams to start with: the newest in the library */
    private function suggestions(): array
    {
        $out = [];
        foreach ($this->s->versions()->recent(12) as $v) {
            if (count($out) < 3 && !isset($out[(int) $v['exam_id']]) && ($card = $this->examCard((int) $v['exam_id'])) !== null) {
                $out[(int) $v['exam_id']] = $card;
            }
        }
        return array_values($out);
    }

    /**
     * What is new in the library over the last 14 days, by provider and day: which exams were
     * added and which got a new version. Providers this person studies or saved are marked.
     *
     * @param array<string,true> $mine provider slugs
     * @return list<array<string,mixed>>
     */
    private function news(DateTimeZone $zone, DateTimeImmutable $now, array $mine): array
    {
        $since = gmdate('Y-m-d H:i:s', $now->getTimestamp() - 14 * 86400);
        $groups = [];
        foreach ($this->s->versions()->publishedSince($since) as $r) {
            $local = (new DateTimeImmutable((string) $r['published_at'], new DateTimeZone('UTC')))->setTimezone($zone);
            $key = $r['provider_slug'] . '|' . $local->format('Y-m-d');
            $groups[$key] ??= [
                'slug' => (string) $r['provider_slug'],
                'name' => provider_name($r, 'provider_name', 'provider_slug'),
                'when' => self::dayLabel($local, $now),
                'mine' => isset($mine[(string) $r['provider_slug']]),
                'added' => [],
                'updated' => [],
            ];
            $groups[$key][(string) $r['first_at'] >= $since ? 'added' : 'updated'][] = $r;
        }
        return array_slice(array_values($groups), 0, 5);
    }

    private static function dayLabel(DateTimeImmutable $day, DateTimeImmutable $now): string
    {
        $diff = (int) (new DateTimeImmutable($now->format('Y-m-d')))->diff(new DateTimeImmutable($day->format('Y-m-d')))->format('%r%a');
        return match (true) {
            $diff === 0 => 'Today',
            $diff === -1 => 'Yesterday',
            $diff > -7 => $day->format('l'),
            default => $day->format('j M'),
        };
    }

    /**
     * Requests to show: everyone's open ones for those who answer them, else this person's own.
     *
     * @return array{manager:bool,rows:list<array<string,mixed>>,open:int}|null
     */
    private function requests(int $userId): ?array
    {
        $manager = $this->auth->can('requests.manage');
        if (!$manager && !$this->auth->can('exams.request')) {
            return null;
        }
        $requests = $this->s->requests();
        return [
            'manager' => $manager,
            'rows' => array_slice($manager ? $requests->list(null, 'open') : $requests->list($userId, ''), 0, 5),
            'open' => $manager ? $requests->openCount() : 0,
        ];
    }

    /**
     * The newest entries of the activity history in plain English. Sign-ins are counted on a
     * line each rather than listed: a public site sees many failed ones, and the successful
     * ones would push everything else off the card.
     *
     * @return array{rows:list<array<string,mixed>>,failed:int,signIns:array{count:int,people:int}}
     */
    private function activity(): array
    {
        $names = [];
        foreach ($this->s->users()->all() as $u) {
            $names[(int) $u['id']] = Access::displayName($u);
        }
        $audit = $this->s->audit();
        return [
            'rows' => array_map(static fn(array $r): array => $r + AuditText::describe($r, $names), $audit->recent(8)),
            'failed' => $audit->failedSignIns(86400),
            'signIns' => $audit->signIns(86400),
        ];
    }
}
