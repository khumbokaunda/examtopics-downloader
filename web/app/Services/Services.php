<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\AnswerEditRepository;
use App\Repositories\CacheVersionRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\ExamRepository;
use App\Repositories\InventoryRefreshRepository;
use App\Repositories\JobRepository;
use App\Repositories\MediaRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\QuestionEditRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;

/** Wiring: one factory method per service, built on demand and shared within the App. */
final class Services
{
    public function __construct(private App $app)
    {
    }

    public function users(): UserRepository
    {
        return $this->app->service(UserRepository::class, fn() => new UserRepository($this->app->db()));
    }

    public function providers(): ProviderRepository
    {
        return $this->app->service(ProviderRepository::class, fn() => new ProviderRepository($this->app->db()));
    }

    public function exams(): ExamRepository
    {
        return $this->app->service(ExamRepository::class, fn() => new ExamRepository($this->app->db()));
    }

    public function jobs(): JobRepository
    {
        return $this->app->service(JobRepository::class, fn() => new JobRepository($this->app->db()));
    }

    public function refreshes(): InventoryRefreshRepository
    {
        return $this->app->service(InventoryRefreshRepository::class, fn() => new InventoryRefreshRepository($this->app->db()));
    }

    public function versions(): CacheVersionRepository
    {
        return $this->app->service(CacheVersionRepository::class, fn() => new CacheVersionRepository($this->app->db()));
    }

    public function documentRepo(): DocumentRepository
    {
        return $this->app->service(DocumentRepository::class, fn() => new DocumentRepository($this->app->db()));
    }

    public function answerEdits(): AnswerEditRepository
    {
        return $this->app->service(AnswerEditRepository::class, fn() => new AnswerEditRepository($this->app->db()));
    }

    public function questionEdits(): QuestionEditRepository
    {
        return $this->app->service(QuestionEditRepository::class, fn() => new QuestionEditRepository($this->app->db()));
    }

    public function corrections(): Corrections
    {
        return $this->app->service(Corrections::class, fn() => new Corrections($this->answerEdits(), $this->questionEdits()));
    }

    public function integrity(): IntegrityChecks
    {
        return $this->app->service(IntegrityChecks::class, fn() => new IntegrityChecks(
            $this->app->db(), $this->versions(), $this->cache(), $this->corrections(), $this->media(), $this->documentRepo(),
            $this->app->paths()->storage() . '/integrity.lock',
            (bool) $this->app->config()->get('integrity.background', true)
                ? fn(): bool => $this->supervisor()->launchScript('integrity.php', [], 'integrity.log')
                : null));
    }

    public function settings(): SettingsRepository
    {
        return $this->app->service(SettingsRepository::class, fn() => new SettingsRepository($this->app->db()));
    }

    public function branding(): Branding
    {
        return $this->app->service(Branding::class, fn() => new Branding($this->app));
    }

    public function mailer(): Mailer
    {
        return $this->app->service(Mailer::class, fn() => new Mailer($this->app, $this->branding()));
    }

    public function mailQueue(): MailQueue
    {
        $background = (bool) $this->app->config()->get('mail.background', true);
        return $this->app->service(MailQueue::class, fn() => new MailQueue(
            $this->app->db(), $this->mailer(), $this->app->logger(),
            $background ? fn(): bool => $this->supervisor()->launchScript('send-mail.php', [], 'mail-send.log') : null));
    }

    public function throttle(): Throttle
    {
        return $this->app->service(Throttle::class, fn() => new Throttle($this->app->db()));
    }

    public function codes(): AccountCodes
    {
        return $this->app->service(AccountCodes::class, fn() => new AccountCodes(
            $this->app->db(), $this->mailer(), $this->throttle(), $this->app->logger()));
    }

    public function notifier(): Notifier
    {
        return $this->app->service(Notifier::class, fn() => new Notifier(
            $this->app->db(), $this->users(), $this->mailQueue(), $this->mailer(), $this->app->logger()));
    }

    public function requests(): ExamRequests
    {
        return $this->app->service(ExamRequests::class, fn() => new ExamRequests(
            $this->app->db(), $this->users(), $this->notifier(), $this->app->logger()));
    }

    public function registration(): Registration
    {
        return $this->app->service(Registration::class, fn() => new Registration($this->app, $this->users(), $this->notifier()));
    }

    public function backup(): Backup
    {
        return $this->app->service(Backup::class, fn() => new Backup($this->app));
    }

    public function audit(): AuditLog
    {
        return $this->app->service(AuditLog::class, fn() => new AuditLog($this->app));
    }

    public function progress(): StudyProgress
    {
        return $this->app->service(StudyProgress::class, fn() => new StudyProgress($this->app->db()));
    }

    public function twoStep(): TwoStep
    {
        return $this->app->service(TwoStep::class, fn() => new TwoStep($this->app->db()));
    }

    public function signInGuard(): SignInGuard
    {
        return $this->app->service(SignInGuard::class, fn() => new SignInGuard($this->app->db(), $this->app->config(),
            $this->app->logger(), $this->audit(), fn(string $event, array $d) => $this->securityNotice($event, $d)));
    }

    /**
     * Tell people when sign-in protection acts: the owner of a locked account (in the inbox
     * and by email), and everyone who reviews sign-ins.
     *
     * @param array<string,mixed> $d
     */
    private function securityNotice(string $event, array $d): void
    {
        $notifier = $this->notifier();
        if ($event === 'account_locked') {
            $user = (array) $d['user'];
            $notifier->notify($user, 'security', 'Your account was locked',
                'Someone typed the wrong password for your account ' . $this->signInGuard()->lockoutAttempts() . ' times in a row, from the address '
                . $d['ip'] . ', so it is locked for ' . $d['minutes'] . ' minutes. If it was you, wait and try again, or reset your password from the sign-in page. '
                . 'If it was not, change your password when you are back in.', '/account#security', null, 'locked:' . $user['id'] . ':' . time());
            $notifier->notifyPermission('security.manage', 'security', 'Account locked: ' . Access::displayName($user),
                Access::displayName($user) . ' (' . $user['username'] . ') was locked for ' . $d['minutes'] . ' minutes after wrong passwords from ' . $d['ip'] . '.',
                '/security/sign-ins', null, 'locked-review:' . $user['id'] . ':' . time(), (int) $user['id']);
        } elseif ($event === 'network_blocked') {
            $notifier->notifyPermission('security.manage', 'security', 'Network blocked: ' . $d['ip'],
                $d['failures'] . ' failed sign-ins came from ' . $d['ip'] . ' in a short time, so sign-ins from it are refused for ' . $d['minutes'] . ' minutes.',
                '/security/sign-ins', null, 'blocked:' . $d['ip'] . ':' . time());
        }
    }

    public function backupUploads(): BackupUploads
    {
        return $this->app->service(BackupUploads::class, fn() => new BackupUploads($this->app, $this->backup()));
    }

    public function supervisor(): WorkerSupervisor
    {
        return $this->app->service(WorkerSupervisor::class, fn() => new WorkerSupervisor(
            $this->app->config(), $this->app->paths(), $this->jobs(), $this->app->logger()));
    }

    public function jobService(): JobService
    {
        return $this->app->service(JobService::class, fn() => new JobService(
            $this->jobs(), $this->supervisor(), $this->app->config(), $this->app->logger()));
    }

    public function cache(): CacheService
    {
        return $this->app->service(CacheService::class, fn() => new CacheService(
            $this->app->db(), $this->app->paths(), $this->app->logger(), $this->providers(), $this->exams(),
            $this->versions(), new DatasetValidator()));
    }

    public function runner(): JobRunner
    {
        return $this->app->service(JobRunner::class, fn() => new JobRunner(
            $this->app->config(), $this->app->paths(), $this->app->logger(), $this->jobs(), $this->providers(),
            $this->exams(), $this->refreshes(), $this->cache(), $this->versions(), $this->media(),
            fn(int $providerId, string $exam) => $this->requests()->collected($providerId, $exam),
            fn(array $job) => $this->audit()->recordJob($job)));
    }

    public function media(): MediaStore
    {
        return $this->app->service(MediaStore::class, fn() => new MediaStore(
            $this->app->paths(), new MediaRepository($this->app->db()), $this->app->logger(),
            (int) $this->app->config()->get('media.max_bytes', 5 * 1024 * 1024)));
    }

    public function documents(): DocumentStore
    {
        return $this->app->service(DocumentStore::class, fn() => new DocumentStore(
            $this->app->config(), $this->app->paths(), $this->app->logger(), $this->documentRepo(), $this->cache(), $this->media(),
            $this->corrections(),
            fn(string $uuid): bool => $this->supervisor()->launchScript('build-document.php', [$uuid], 'documents-build.log')));
    }
}
