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
}
