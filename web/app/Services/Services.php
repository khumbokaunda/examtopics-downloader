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
use App\Repositories\LoginAttemptRepository;
use App\Repositories\MediaRepository;
use App\Repositories\ProviderRepository;
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

    public function loginAttempts(): LoginAttemptRepository
    {
        return $this->app->service(LoginAttemptRepository::class, fn() => new LoginAttemptRepository($this->app->db()));
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

    public function settings(): SettingsRepository
    {
        return $this->app->service(SettingsRepository::class, fn() => new SettingsRepository($this->app->db()));
    }

    public function branding(): Branding
    {
        return $this->app->service(Branding::class, fn() => new Branding($this->app));
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
            $this->exams(), $this->refreshes(), $this->cache(), $this->versions(), $this->media()));
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
            $this->answerEdits(),
            fn(string $uuid): bool => $this->supervisor()->launchScript('build-document.php', [$uuid], 'documents-build.log')));
    }
}
