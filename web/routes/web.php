<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AnswerController;
use App\Controllers\AuthController;
use App\Controllers\BackupController;
use App\Controllers\BrandingController;
use App\Controllers\CollectController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\EmailController;
use App\Controllers\InboxController;
use App\Controllers\JobController;
use App\Controllers\LibraryController;
use App\Controllers\MediaController;
use App\Controllers\ProviderController;
use App\Controllers\RequestController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Controllers\ViewerController;
use App\Core\Http\Router;
use App\Services\Access;

// Every route requires authentication unless marked public; every POST requires CSRF.
// can: names the permission a route needs (see App\Services\Access). A route without one is
// for administrators only.
return static function (Router $r): void {
    $read = 'library.view';
    $collect = 'exams.collect';
    $me = Access::SIGNED_IN;

    $r->get('/login', [AuthController::class, 'showLogin'], public: true);
    $r->post('/login', [AuthController::class, 'login'], public: true);
    $r->get('/register', [AuthController::class, 'showRegister'], public: true);
    $r->post('/register', [AuthController::class, 'register'], public: true);
    $r->get('/verify', [AuthController::class, 'showVerify'], public: true);
    $r->post('/verify', [AuthController::class, 'verify'], public: true);
    $r->post('/verify/resend', [AuthController::class, 'resendVerify'], public: true);
    $r->get('/forgot', [AuthController::class, 'showForgot'], public: true);
    $r->post('/forgot', [AuthController::class, 'forgot'], public: true);
    $r->get('/reset', [AuthController::class, 'showReset'], public: true);
    $r->post('/reset', [AuthController::class, 'reset'], public: true);
    $r->post('/logout', [AuthController::class, 'logout'], can: $me);
    $r->get('/branding/theme.css', [BrandingController::class, 'css'], public: true);
    $r->get('/branding/{file}', [BrandingController::class, 'file'], public: true);

    $r->get('/', [DashboardController::class, 'index'], can: $read);

    $r->get('/providers', [ProviderController::class, 'index'], can: $read);
    $r->post('/providers/discover', [ProviderController::class, 'discover'], can: $collect);
    $r->post('/providers/add', [ProviderController::class, 'add'], can: $collect);
    $r->get('/providers/{slug}', [ProviderController::class, 'show'], can: $read);
    $r->post('/providers/{slug}/refresh', [ProviderController::class, 'refresh'], can: $read);
    $r->get('/api/providers/{slug}/exams', [ProviderController::class, 'examsJson'], can: $collect);

    $r->get('/collect', [CollectController::class, 'index'], can: $collect);
    $r->post('/collect', [CollectController::class, 'start'], can: $collect);
    $r->post('/collect/links', [CollectController::class, 'addLinks'], can: $collect);
    $r->post('/collect/links/clear', [CollectController::class, 'clearLinks'], can: $collect);

    $r->get('/jobs', [JobController::class, 'index'], can: $collect);
    $r->get('/jobs/{uuid}', [JobController::class, 'show'], can: $collect);
    $r->post('/jobs/{uuid}/cancel', [JobController::class, 'cancel'], can: $collect);
    $r->get('/api/jobs/active', [JobController::class, 'activeJson'], can: $collect);
    $r->get('/api/jobs/{uuid}', [JobController::class, 'statusJson'], can: $read);
    $r->get('/api/jobs/{uuid}/log', [JobController::class, 'logJson'], can: $collect);

    $r->get('/library', [LibraryController::class, 'index'], can: $read);
    $r->get('/api/palette', [LibraryController::class, 'paletteJson'], can: $read);
    $r->get('/library/{provider}/{exam}', [LibraryController::class, 'exam'], can: $read);
    $r->get('/library/{provider}/{exam}/view', [ViewerController::class, 'show'], can: $read);
    $r->get('/library/{provider}/{exam}/corrections', [AnswerController::class, 'index'], can: 'answers.edit');
    $r->post('/library/{provider}/{exam}/answers', [AnswerController::class, 'save'], can: 'answers.edit');
    $r->get('/library/{provider}/{exam}/{version}/json', [LibraryController::class, 'downloadJson'], can: $read);
    $r->post('/library/{provider}/{exam}/{version}/delete', [LibraryController::class, 'deleteVersion'], can: 'library.manage');
    $r->post('/library/{provider}/{exam}/{version}/documents', [DocumentController::class, 'generate'], can: 'documents.build');
    $r->post('/library/{provider}/{exam}/{version}/images', [LibraryController::class, 'downloadImages'], can: $collect);
    $r->get('/api/library/{provider}/{exam}/{version}/questions/{number}', [ViewerController::class, 'reveal'], can: $read);
    $r->get('/media/{hash}', [MediaController::class, 'show'], can: $read);

    $r->get('/documents/{uuid}/download', [DocumentController::class, 'download'], can: $read);
    $r->post('/documents/{uuid}/delete', [DocumentController::class, 'delete'], can: 'documents.build');
    $r->get('/api/documents/{uuid}', [DocumentController::class, 'statusJson'], can: $read);

    $r->get('/settings', [SettingsController::class, 'index'], can: 'settings.manage');
    $r->post('/settings/reconcile', [SettingsController::class, 'reconcile'], can: 'settings.manage');
    $r->post('/settings/appearance', [SettingsController::class, 'appearance'], can: $me);
    $r->post('/settings/time', [SettingsController::class, 'time'], can: 'settings.manage');
    $r->post('/api/appearance', [SettingsController::class, 'appearanceJson'], can: $me);
    $r->get('/settings/branding', [BrandingController::class, 'index'], can: 'settings.manage');
    $r->post('/settings/branding', [BrandingController::class, 'save'], can: 'settings.manage');
    $r->post('/settings/branding/logo/remove', [BrandingController::class, 'removeLogo'], can: 'settings.manage');
    $r->get('/settings/email', [EmailController::class, 'index'], can: 'settings.manage');
    $r->post('/settings/email', [EmailController::class, 'save'], can: 'settings.manage');
    $r->post('/settings/email/test', [EmailController::class, 'test'], can: 'settings.manage');

    $r->get('/account', [AccountController::class, 'index'], can: $me);
    $r->post('/account/profile', [AccountController::class, 'profile'], can: $me);
    $r->post('/account/password', [AccountController::class, 'password'], can: $me);
    $r->post('/account/time', [AccountController::class, 'time'], can: $me);
    $r->post('/account/email/send', [AccountController::class, 'sendCode'], can: $me);
    $r->post('/account/email/verify', [AccountController::class, 'verifyEmail'], can: $me);
    $r->post('/account/notifications', [AccountController::class, 'notifications'], can: $me);

    $r->get('/inbox', [InboxController::class, 'index'], can: $me);
    $r->post('/inbox/read', [InboxController::class, 'readAll'], can: $me);
    $r->get('/inbox/{id}', [InboxController::class, 'open'], can: $me);

    $r->get('/requests', [RequestController::class, 'index'], can: 'exams.request');
    $r->post('/requests', [RequestController::class, 'store'], can: 'exams.request');
    $r->post('/requests/{uuid}/cancel', [RequestController::class, 'cancel'], can: 'exams.request');
    $r->post('/requests/{uuid}/answer', [RequestController::class, 'answer'], can: 'requests.manage');

    $r->get('/backups', [BackupController::class, 'index'], can: 'backup.manage');
    $r->post('/backups', [BackupController::class, 'create'], can: 'backup.manage');
    $r->post('/backups/upload', [BackupController::class, 'upload'], can: 'backup.manage');
    $r->get('/backups/{name}/download', [BackupController::class, 'download'], can: 'backup.manage');
    $r->post('/backups/{name}/restore', [BackupController::class, 'restore'], can: 'backup.manage');
    $r->post('/backups/{name}/delete', [BackupController::class, 'delete'], can: 'backup.manage');

    $r->get('/users', [UserController::class, 'index'], can: 'users.manage');
    $r->get('/users/new', [UserController::class, 'create'], can: 'users.manage');
    $r->post('/users/registration', [UserController::class, 'registration'], can: 'users.manage');
    $r->post('/users', [UserController::class, 'store'], can: 'users.manage');
    $r->get('/users/{username}', [UserController::class, 'edit'], can: 'users.manage');
    $r->post('/users/{username}', [UserController::class, 'update'], can: 'users.manage');
    $r->post('/users/{username}/delete', [UserController::class, 'delete'], can: 'users.manage');
    $r->post('/users/{username}/approve', [UserController::class, 'approve'], can: 'users.manage');
};
