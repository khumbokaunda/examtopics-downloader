<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CollectController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\JobController;
use App\Controllers\LibraryController;
use App\Controllers\ProviderController;
use App\Controllers\SettingsController;
use App\Controllers\ViewerController;
use App\Core\Http\Router;

// Every route requires authentication unless marked public; every POST requires CSRF.
return static function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'], public: true);
    $r->post('/login', [AuthController::class, 'login'], public: true);
    $r->post('/logout', [AuthController::class, 'logout']);

    $r->get('/', [DashboardController::class, 'index']);

    $r->get('/providers', [ProviderController::class, 'index']);
    $r->post('/providers/discover', [ProviderController::class, 'discover']);
    $r->post('/providers/add', [ProviderController::class, 'add']);
    $r->get('/providers/{slug}', [ProviderController::class, 'show']);
    $r->post('/providers/{slug}/refresh', [ProviderController::class, 'refresh']);
    $r->get('/api/providers/{slug}/exams', [ProviderController::class, 'examsJson']);

    $r->get('/collect', [CollectController::class, 'index']);
    $r->post('/collect', [CollectController::class, 'start']);

    $r->get('/jobs', [JobController::class, 'index']);
    $r->get('/jobs/{id}', [JobController::class, 'show']);
    $r->post('/jobs/{id}/cancel', [JobController::class, 'cancel']);
    $r->get('/api/jobs/active', [JobController::class, 'activeJson']);
    $r->get('/api/jobs/{id}', [JobController::class, 'statusJson']);

    $r->get('/library', [LibraryController::class, 'index']);
    $r->get('/library/{provider}/{exam}', [LibraryController::class, 'exam']);
    $r->get('/library/{provider}/{exam}/view', [ViewerController::class, 'show']);
    $r->get('/library/{provider}/{exam}/{version}/json', [LibraryController::class, 'downloadJson']);
    $r->post('/library/{provider}/{exam}/{version}/delete', [LibraryController::class, 'deleteVersion']);
    $r->post('/library/{provider}/{exam}/{version}/documents', [DocumentController::class, 'generate']);
    $r->get('/api/library/{provider}/{exam}/{version}/questions/{number}', [ViewerController::class, 'reveal']);

    $r->get('/documents/{id}/download', [DocumentController::class, 'download']);
    $r->post('/documents/{id}/delete', [DocumentController::class, 'delete']);

    $r->get('/settings', [SettingsController::class, 'index']);
    $r->post('/settings/password', [SettingsController::class, 'password']);
    $r->post('/settings/reconcile', [SettingsController::class, 'reconcile']);
};
