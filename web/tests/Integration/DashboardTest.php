<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Access;
use App\Services\StudyProgress;
use Tests\Support\HttpTestCase;

/** The dashboard: a person's own studying first (streak, week, continue, news, requests), the system after. */
final class DashboardTest extends HttpTestCase
{
    private const PW = 'member password long';
    private array $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->newSession();
        $this->login();
        $this->s->jobService()->enqueueInventory($this->provider('huawei'));
        $this->drain();
        $id = $this->s->jobService()->enqueueCollect($this->provider('huawei'), 'h12-811', ['source_mode' => 'live', 'rps' => 50.0]);
        $this->drain();
        $this->version = $this->s->versions()->find((int) $this->s->jobs()->find($id)['cache_version_id']);
        $this->s->users()->create('member', self::PW, Access::MEMBER, ['display_name' => 'Mercy Banda']);
        $this->newSession();
        $this->login('member', self::PW);
    }

    private function memberId(): int
    {
        return (int) $this->s->users()->findByUsername('member')['id'];
    }

    public function testTheStreakCountsDaysInARow(): void
    {
        $count = static fn(array $days): array => StudyProgress::countStreak($days, '2026-10-21');
        self::assertSame(['current' => 0, 'best' => 0, 'today' => false], $count([]));
        self::assertSame(['current' => 1, 'best' => 1, 'today' => true], $count(['2026-10-21']));
        self::assertSame(['current' => 2, 'best' => 2, 'today' => false], $count(['2026-10-19', '2026-10-20']), 'alive until today ends');
        self::assertSame(['current' => 0, 'best' => 2, 'today' => false], $count(['2026-10-18', '2026-10-19']), 'a whole day missed breaks it');
        self::assertSame(['current' => 3, 'best' => 4, 'today' => true],
            $count(['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-19', '2026-10-20', '2026-10-21']), 'across a month end too');
    }

    public function testANewMemberIsShownWhereToStart(): void
    {
        $dash = $this->get('/')->body;
        self::assertStringContainsString('Good ', $dash);
        self::assertStringContainsString(', Mercy</h1>', $dash, 'by first name');
        self::assertStringContainsString('Start studying', $dash);
        self::assertStringContainsString('HCIA-Datacom V1.0', $dash, 'an exam to try');
        self::assertStringContainsString('Study today to start a streak', $dash);
        self::assertStringContainsString('Answers per day, last 14 days: none yet', $dash);
        // Nothing about running the system.
        foreach (['Running the library', 'Recently generated documents', 'Active jobs', 'Cached', 'Studying this week'] as $hidden) {
            self::assertStringNotContainsString($hidden, $dash);
        }
    }

    public function testStudyingFillsTheWeekTheStreakAndTheShelves(): void
    {
        $this->get('/library/huawei/h12-811/view', ['mode' => 'practice']);
        $this->post('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/3/attempt', ['picks' => 'BE']);
        $day = $this->app->db()->one('SELECT * FROM study_days WHERE user_id = ?', [$this->memberId()]);
        self::assertSame([1, 1, 1], [(int) $day['answered'], (int) $day['correct'], (int) $day['pages']]);

        $dash = $this->get('/')->body;
        self::assertStringContainsString('This week: 1 answer checked, 100% right.', $dash);
        self::assertMatchesRegularExpression('#streak-value">1</span>\s*<span class="streak-unit">day in a row#', $dash);
        self::assertStringContainsString('Done for today.', $dash);
        self::assertStringContainsString('Continue studying', $dash);
        self::assertStringContainsString('href="/library/huawei/h12-811/view?mode=practice&amp;page=1"', $dash);
        self::assertStringNotContainsString('none yet', $dash);
        self::assertStringContainsString('Today: 1 answer, 1 right', $dash, 'the chart says each day');
        self::assertStringContainsString('You study this', $dash, 'new exams from a provider you study are marked');
        self::assertMatchesRegularExpression('#1 exam added:\s*<a href="/library/huawei/h12-811">HCIA-Datacom V1.0</a>#', $dash);

        // Days before today keep the streak alive until today is over.
        $id = $this->memberId();
        $this->app->db()->run('DELETE FROM study_days WHERE user_id = ?', [$id]);
        foreach ([1, 2, 3] as $ago) {
            $this->app->db()->run('INSERT INTO study_days (user_id, day, answered, correct, pages) VALUES (?, ?, 5, 4, 1)',
                [$id, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-$ago days")->format('Y-m-d')]);
        }
        $dash = $this->get('/')->body;
        self::assertMatchesRegularExpression('#streak-value">3</span>#', $dash);
        self::assertStringContainsString('Study today to keep it going. Your best is 3 days.', $dash);
    }

    public function testDaysFollowThePersonsOwnTimezone(): void
    {
        // Kiritimati is UTC+14: for most of the UTC day it is already tomorrow there.
        $this->s->users()->setPreferences($this->memberId(), ['timezone' => 'Pacific/Kiritimati']);
        $this->get('/library/huawei/h12-811/view', ['mode' => 'practice']);
        $local = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d');
        self::assertSame($local, $this->app->db()->value('SELECT day FROM study_days WHERE user_id = ?', [$this->memberId()]));
    }

    public function testRequestsShowWhereTheyStand(): void
    {
        $member = $this->s->users()->findByUsername('member');
        [$error] = $this->s->requests()->create($member, $this->provider('huawei'), 'h12-811-v2-0', '');
        self::assertNull($error);
        $dash = $this->get('/')->body;
        self::assertStringContainsString('Your requests', $dash);
        self::assertStringContainsString('H12-811-V2-0', $dash);
        self::assertStringContainsString('Waiting', $dash);

        $this->newSession();
        $this->login();
        $admin = $this->get('/')->body;
        self::assertStringContainsString('Requests waiting', $admin);
        self::assertStringContainsString('Mercy Banda', $admin);
        self::assertStringContainsString('Answer 1', $admin);
    }

    /**
     * Cards are as tall as what they hold: none is stretched to match a neighbour (h-100), and
     * an empty card says so in one line rather than with a large empty state.
     */
    public function testCardsAreNeverStretchedOrPaddedOut(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/dashboard.php');
        self::assertStringNotContainsString('h-100', $template);
        self::assertStringNotContainsString('empty_state(', $template);
        self::assertStringContainsString('class="card-note"', $this->get('/')->body, 'a new member\'s empty cards use the one-line note');
    }

    public function testAdministratorsSeeTheSystemBelowTheirOwnStudying(): void
    {
        $this->get('/library/huawei/h12-811/view', ['mode' => 'practice']);
        $this->post('/api/library/huawei/h12-811/' . $this->version['version'] . '/questions/3/attempt', ['picks' => 'A']);
        $this->newSession();
        $this->login();
        $dash = $this->get('/')->body;
        self::assertStringContainsString('Your streak', $dash, 'their own studying first');
        self::assertStringContainsString('Running the library', $dash);
        self::assertStringContainsString('Exams in the library', $dash);
        self::assertStringContainsString('Studying this week', $dash);
        self::assertStringContainsString('href="/progress/people/member">Mercy Banda</a>', $dash);
        self::assertStringContainsString('1 answer, 0% right', $dash);
        self::assertMatchesRegularExpression('#\d+ sign-ins? by \d+ (person|people) in the last 24 hours#', $dash, 'sign-ins on one line');
        self::assertStringNotContainsString('admin signed in</div>', $dash, 'not one row each');
    }
}
