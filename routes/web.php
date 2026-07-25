<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionWorkflowController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\ClientMagicLinkController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\ClientOnboardingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ContentRevisionController;
use App\Http\Controllers\ContentPublicationController;
use App\Http\Controllers\TeamPerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserClientAssignmentController;
use App\Console\Commands\UpdateOverdueContentItems;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AiAdvisorController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Schedule;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

//Production Workflow Routes
Route::middleware(['auth', 'internal'])->group(function () {
    Route::get('/production-workflow', [ProductionWorkflowController::class, 'index'])
        ->name('production-workflow.index');
    Route::patch('/production-workflow/{contentItem}/status', [ProductionWorkflowController::class, 'updateStatus'])
        ->name('production-workflow.update-status');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/production-workflow/{contentItem}', [ProductionWorkflowController::class, 'show'])
    ->name('production-workflow.show');

    Route::post('/production-workflow/{contentItem}/revisions', [ContentRevisionController::class, 'store'])
        ->name('content-revision.store');
    Route::patch('/production-workflow/{contentItem}/revisions/{revision}/resolve', [ContentRevisionController::class, 'resolve'])
        ->name('content-revision.resolve');
    Route::post('/production-workflow/{contentItem}/publications', [ContentPublicationController::class, 'store'])
        ->name('content-publication.store');

    Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
    Route::get('/user-management/create', [UserManagementController::class, 'create'])->name('user-management.create');
    Route::post('/user-management', [UserManagementController::class, 'store'])->name('user-management.store');
    Route::delete('/user-management/{user}', [UserManagementController::class, 'destroy'])->name('user-management.destroy');

    Route::get('/user-management/{user}/clients', [UserClientAssignmentController::class, 'edit'])
        ->name('user-client-assignment.edit');
    Route::put('/user-management/{user}/clients', [UserClientAssignmentController::class, 'update'])
        ->name('user-client-assignment.update');

    Route::get('/client-onboarding', [ClientOnboardingController::class, 'index'])->name('client-onboarding.index');
    Route::get('/client-onboarding/create', [ClientOnboardingController::class, 'create'])->name('client-onboarding.create');
    Route::post('/client-onboarding', [ClientOnboardingController::class, 'store'])->name('client-onboarding.store');
    Route::get('/client-onboarding/{client}', [ClientOnboardingController::class, 'show'])->name('client-onboarding.show');
    Route::get('/client-onboarding/{client}/edit', [ClientOnboardingController::class, 'edit'])->name('client-onboarding.edit');
    Route::put('/client-onboarding/{client}', [ClientOnboardingController::class, 'update'])->name('client-onboarding.update');
    Route::delete('/client-onboarding/{client}', [ClientOnboardingController::class, 'destroy'])->name('client-onboarding.destroy');

    Route::get('/team-performance', [TeamPerformanceController::class, 'index'])->name('team-performance.index');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
    Route::get('/analytics/{contentItem}', [AnalyticsController::class, 'show'])->name('analytics.show');

    Route::get('/ai-advisor', [AiAdvisorController::class, 'index'])->name('ai-advisor');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings'); 
    Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])
        ->name('notifications.mark-all-read');

    Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile', function () {
        return redirect()->route('profile.show', auth()->id());
    })->name('profile.me');
});

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

Route::post('/logout', function (Illuminate\Http\Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::get('/client/login', [ClientMagicLinkController::class, 'showForm'])->name('client.login');

Route::post('/client/login', [ClientMagicLinkController::class, 'requestLink'])
    ->middleware('throttle:5,1') // maksimal 5 request per menit per IP
    ->name('client.login.request');

Route::get('/client/magic-login/{token}', [ClientMagicLinkController::class, 'verify'])->name('client.magic-login.verify');

Route::middleware(['auth', 'client.user'])->group(function () {
    Route::get('/client/dashboard', function () {
        return 'Client Dashboard (belum dibuat)';
    })->name('client.dashboard');
});

//KF203.a : peringatan otomatis ketika tugas berstatus Overdue atau memasuki masa kritis tenggat waktu.
Schedule::command(UpdateOverdueContentItems::class)->hourly();