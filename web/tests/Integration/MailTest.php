<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\MailQueue;
use App\Services\Mailer;
use Tests\Support\ArrayTransport;
use Tests\Support\HttpTestCase;

final class MailTest extends HttpTestCase
{
    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure($this->startFixture());
        $this->transport = new ArrayTransport();
        $this->app->set(Mailer::class, new Mailer($this->app, $this->s->branding(), $this->transport));
        $this->newSession();
        $this->login();
    }

    private function setUpMail(): void
    {
        self::assertSame(303, $this->post('/settings/email', ['host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls',
            'username' => 'sender@example.com', 'password' => 'app-password-16ch', 'from_address' => '', 'from_name' => '',
            'site_url' => 'https://exams.example.com/'])->status);
    }

    public function testSettingsKeepTheStoredPasswordAndNeverShowIt(): void
    {
        self::assertFalse($this->s->mailer()->configured());
        $this->setUpMail();
        $s = $this->s->mailer()->settings();
        self::assertSame(['smtp.example.com', 'app-password-16ch', 'https://exams.example.com'], [$s['host'], $s['password'], $s['site_url']]);
        self::assertTrue($this->s->mailer()->configured(), 'the from address falls back to the username');

        $this->post('/settings/email', ['host' => 'smtp.gmail.com', 'port' => '465', 'encryption' => 'ssl', 'username' => 'sender@example.com', 'password' => '']);
        self::assertSame('app-password-16ch', $this->s->mailer()->settings()['password'], 'a blank password keeps the stored one');
        $page = $this->get('/settings/email')->body;
        self::assertStringNotContainsString('app-password-16ch', $page);
        self::assertStringContainsString('Stored (leave empty to keep)', $page);
        self::assertStringNotContainsString('app-password-16ch', (string) file_get_contents($this->app->paths()->logsRoot() . '/security.log'));

        $this->post('/settings/email', ['host' => 'smtp.example.com;rm', 'port' => '587', 'encryption' => 'tls']);
        self::assertSame('danger', $this->flashes()[0]['type']);
    }

    public function testSendWrapsTheFragmentAndNeverThrows(): void
    {
        $mailer = $this->s->mailer();
        self::assertFalse($mailer->send('someone@example.com', 'Hello', '<p>Hi</p>'), 'skipped while not set up');
        self::assertSame([], $this->transport->sent);

        $this->setUpMail();
        $this->post('/settings/branding', ['name' => 'Kaunda <Exams>', 'tagline' => '', 'accent' => 'teal']);
        self::assertTrue($mailer->send('someone@example.com', 'Hello', '<p>Open <a href="https://exams.example.com/inbox">your inbox</a>.</p>'));
        $m = $this->transport->sent[0];
        self::assertSame('sender@example.com', $m->fromAddress);
        self::assertSame('Kaunda <Exams>', $m->fromName, 'the from name falls back to the system name');
        self::assertStringContainsString('Kaunda &lt;Exams&gt;', $m->html, 'branding is escaped');
        self::assertStringContainsString('role="presentation"', $m->html, 'laid out with tables');
        self::assertStringNotContainsString('<style', $m->html);
        self::assertStringNotContainsString('color:#6b7280;margin-top:4px">', $m->html, 'an empty tagline leaves no empty line');
        self::assertSame("Open your inbox (https://exams.example.com/inbox).\n", $m->text, 'the text part comes from the fragment, not the letterhead');
        self::assertSame([], $m->embeds, 'no logo, nothing embedded');

        $this->transport->fail = true;
        self::assertFalse($mailer->send('someone@example.com', 'Hello', '<p>Hi</p>'));
        self::assertStringContainsString('could not authenticate', strtolower((string) file_get_contents($this->app->paths()->logsRoot() . '/mail.log')));
        self::assertFalse($mailer->send('not an address', 'Hello', '<p>Hi</p>'));
    }

    public function testOnlyPngLogosAreEmbedded(): void
    {
        $this->setUpMail();
        $dir = $this->s->branding()->dir();
        @mkdir($dir, 0750, true);
        file_put_contents($dir . '/logo_20261014000000.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->s->settings()->set('brand.logo', 'logo_20261014000000.svg');
        $this->app->set(\App\Services\Branding::class, new \App\Services\Branding($this->app));
        $mailer = new Mailer($this->app, new \App\Services\Branding($this->app), $this->transport);
        $mailer->send('someone@example.com', 'Hello', '<p>Hi</p>');
        self::assertSame([], $this->transport->sent[0]->embeds, 'mail clients do not show SVG');

        $img = imagecreatetruecolor(10, 10);
        imagepng($img, $dir . '/logo_20261014000001.png');
        $this->s->settings()->set('brand.logo', 'logo_20261014000001.png');
        $mailer = new Mailer($this->app, new \App\Services\Branding($this->app), $this->transport);
        $mailer->send('someone@example.com', 'Hello', '<p>Hi</p>');
        self::assertSame(['logo'], array_keys($this->transport->sent[1]->embeds));
        self::assertStringContainsString('src="cid:logo"', $this->transport->sent[1]->html);
    }

    public function testTheQueueSendsOnceAndRetriesWithBackoff(): void
    {
        $this->setUpMail();
        $queue = new MailQueue($this->app->db(), $this->s->mailer(), $this->app->logger());
        self::assertTrue($queue->enqueue('request:1:admin', 'admin@example.com', 'Admin', 'New exam request', '<p>Someone asked.</p>'));
        self::assertFalse($queue->enqueue('request:1:admin', 'admin@example.com', 'Admin', 'New exam request', '<p>Someone asked.</p>'), 'one event, one email');

        $this->transport->fail = true;
        self::assertSame(['sent' => 0, 'failed' => 1], $queue->drain());
        $row = $this->app->db()->one('SELECT * FROM mail_queue');
        self::assertSame(['queued', 1], [$row['status'], (int) $row['attempts']]);
        self::assertGreaterThan(time() + 50, strtotime($row['next_attempt_at'] . ' UTC'), 'retried after a minute');
        self::assertSame(['sent' => 0, 'failed' => 0], $queue->drain(), 'not due yet');

        $this->transport->fail = false;
        $this->app->db()->run('UPDATE mail_queue SET next_attempt_at = ?', [gmdate('Y-m-d H:i:s', time() - 1)]);
        self::assertSame(['sent' => 1, 'failed' => 0], $queue->drain());
        self::assertSame('sent', $this->app->db()->value('SELECT status FROM mail_queue'));
        self::assertSame('Someone asked.', trim($this->transport->sent[0]->text));

        // After the last retry it gives up.
        $queue->enqueue('request:2:admin', 'admin@example.com', null, 'Again', '<p>x</p>');
        $this->transport->fail = true;
        for ($i = 0; $i <= count(MailQueue::BACKOFF); $i++) {
            $this->app->db()->run('UPDATE mail_queue SET next_attempt_at = ? WHERE status = ?', [gmdate('Y-m-d H:i:s', time() - 1), 'queued']);
            $queue->drain();
        }
        self::assertSame('failed', $this->app->db()->value("SELECT status FROM mail_queue WHERE idempotency_key = 'request:2:admin'"));
    }

    public function testTheTestButton(): void
    {
        $this->setUpMail();
        $this->post('/settings/email/test', ['to' => 'Me@Example.com']);
        self::assertSame('success', $this->flashes()[0]['type']);
        self::assertSame('me@example.com', $this->transport->sent[0]->to);
        self::assertStringContainsString('https://exams.example.com', $this->transport->sent[0]->html);

        $this->transport->fail = true;
        $this->post('/settings/email/test', ['to' => 'me@example.com']);
        self::assertStringContainsString('mail.log', $this->flashes()[0]['message']);
    }
}
