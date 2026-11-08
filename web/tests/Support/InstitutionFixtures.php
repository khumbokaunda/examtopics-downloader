<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Access;

/** People and institutions for the institution tests. Use in an HttpTestCase. */
trait InstitutionFixtures
{
    protected static string $pw = 'member password long';

    /** An administrator and five members, each with a verified address name@example.com. */
    protected function people(string ...$extra): void
    {
        $this->configure($this->startFixture());
        $this->s->users()->create('admin', 'correct horse battery', 'admin', ['email' => 'admin@example.com', 'email_verified' => true]);
        foreach (['olive', 'bob', 'carol', 'dan', 'erin', ...$extra] as $name) {
            $this->s->users()->create($name, self::$pw, Access::MEMBER, ['email' => $name . '@example.com', 'email_verified' => true]);
        }
        $this->newSession();
    }

    protected function as(string $name): void
    {
        $this->newSession();
        if ($name === 'admin') {
            $this->login();
        } else {
            $this->login($name, self::$pw, Access::MEMBER);
        }
    }

    /** @return array<string,string> */
    protected function profile(string $name): array
    {
        return ['name' => $name, 'short_name' => '', 'type' => 'private_centre', 'address' => 'Area 47, Plot 12', 'region' => 'Central',
            'district' => 'Lilongwe', 'email' => 'info@example.org', 'phone' => '+265 999 123 456', 'website' => 'https://example.org',
            'registration_info' => '', 'contact_name' => 'Olive Banda', 'contact_position' => 'Director',
            'intended_use' => 'Assessing our networking students during the course.'];
    }

    /** Register as $owner and have the administrator verify it. @return string its uuid */
    protected function institution(string $owner, string $name, bool $verify = true): string
    {
        $this->as($owner);
        $r = $this->post('/institutions/register', $this->profile($name));
        self::assertSame(303, $r->status, $r->body);
        $uuid = (string) $this->app->db()->value('SELECT uuid FROM institutions WHERE name = ?', [$name]);
        if ($verify) {
            $this->as('admin');
            $this->post('/platform/institutions/' . $uuid . '/decision', ['decision' => 'verify', 'evidence' => 'Phoned the office.']);
            self::assertSame('verified', $this->app->db()->value('SELECT status FROM institutions WHERE uuid = ?', [$uuid]));
        }
        return $uuid;
    }

    /** Invite as $by, then accept as $who. */
    protected function join(string $uuid, string $by, string $who, string $role): void
    {
        $this->as($by);
        $this->post('/institutions/' . $uuid . '/invitations', ['email' => $who . '@example.com', 'role' => $role]);
        $flash = $this->flashes()[0]['message'] ?? '';
        self::assertMatchesRegularExpression('#/invitations/[a-f0-9]{48}#', $flash);
        preg_match('#/invitations/([a-f0-9]{48})#', $flash, $m);
        $this->as($who);
        self::assertSame(303, $this->post('/invitations/' . $m[1] . '/accept')->status);
        self::assertSame('success', $this->flashes()[0]['type']);
    }

    protected function memberUuid(string $institution, string $username): string
    {
        return (string) $this->app->db()->value('SELECT m.uuid FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id
            JOIN users u ON u.id = m.user_id WHERE i.uuid = ? AND u.username = ?', [$institution, $username]);
    }

    /**
     * An institution with an instructor (bob), an examination officer (erin), two students in
     * class CCNA 1 (carol, dan) and one not (fay), three published questions, and a published
     * assessment assigned to the class. Signed in as the owner (olive) at the end.
     *
     * @return array{0:string,1:string,2:string} the institution's uuid, the assessment's path and uuid
     */
    protected function sittingScenario(): array
    {
        $this->people('fay');
        $a = $this->institution('olive', 'Lilongwe ICT Academy');
        $this->join($a, 'olive', 'bob', 'instructor');
        $this->join($a, 'olive', 'erin', 'exam_officer');
        $this->join($a, 'olive', 'carol', 'student');
        $this->join($a, 'olive', 'dan', 'student');
        $this->join($a, 'olive', 'fay', 'student');
        $base = '/institutions/' . $a;

        $this->as('olive');
        $this->post($base . '/classes', ['name' => 'CCNA 1']);
        $class = (string) $this->app->db()->value('SELECT uuid FROM institution_classes');
        $this->post($base . '/classes/' . $class . '/students', ['students' => [$this->memberUuid($a, 'carol'), $this->memberUuid($a, 'dan')]]);

        // Three published questions: single choice (2 marks, B right), multiple select with part
        // marks (3 marks, A and C right), true or false (1 mark, true).
        $this->post($base . '/banks', ['name' => 'Bank']);
        $bank = $base . '/banks/' . $this->app->db()->value('SELECT uuid FROM question_banks');
        $this->post($bank . '/questions', ['type' => 'single', 'text' => 'Which layer routes?', 'choice_text' => ['Physical', 'Network', 'Session'], 'correct' => ['1'],
            'marks' => '2', 'difficulty' => 'easy', 'explanation' => 'SECRET EXPLANATION']);
        $this->post($bank . '/questions', ['type' => 'multiple', 'text' => 'Which are routing protocols?', 'choice_text' => ['OSPF', 'HTTP', 'EIGRP', 'FTP'],
            'correct' => ['0', '2'], 'partial_credit' => '1', 'marks' => '3', 'difficulty' => 'medium']);
        $this->post($bank . '/questions', ['type' => 'true_false', 'text' => 'OSPF is link-state.', 'tf_answer' => 'T', 'marks' => '1', 'difficulty' => 'easy']);
        $questions = array_column($this->app->db()->all('SELECT uuid FROM bank_questions ORDER BY id'), 'uuid');
        foreach ($questions as $q) {
            $this->post($bank . '/questions/' . $q . '/status', ['action' => 'submit']);
            $this->post($bank . '/questions/' . $q . '/status', ['action' => 'publish']);
        }

        $this->post($base . '/assessments', ['title' => 'Module test', 'type' => 'summative']);
        $assessment = (string) $this->app->db()->value('SELECT uuid FROM assessments');
        $path = $base . '/assessments/' . $assessment;
        $this->post($path . '/settings', ['title' => 'Module test', 'type' => 'summative', 'description' => '', 'instructions' => 'Answer every question.',
            'duration_minutes' => '30', 'pass_percent' => '50', 'max_attempts' => '1', 'opens_at' => '', 'closes_at' => '', 'shuffle_questions' => '1',
            'shuffle_choices' => '1', 'negative_marking' => '0', 'review_policy' => 'answers']);
        $this->post($path . '/questions', ['bank' => (string) $this->app->db()->value('SELECT uuid FROM question_banks'), 'questions' => $questions]);
        $this->post($path . '/assignments', ['class' => $class]);
        $this->post($path . '/status', ['action' => 'publish']);
        self::assertSame('published', $this->app->db()->value('SELECT status FROM assessments'));
        return [$a, $path, $assessment];
    }
}
