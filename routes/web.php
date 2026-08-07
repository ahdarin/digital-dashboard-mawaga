<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionWorkflowController;
use App\Http\Controllers\ContentItemController;
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
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Client\ApprovalController;
use App\Http\Controllers\Client\AnalyticsController as ClientAnalyticsController;
use App\Http\Controllers\AudienceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ContentPlanController;
use App\Http\Controllers\MasterDataController;
use Illuminate\Support\Facades\Schedule;
use App\Services\AiStrategyService;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route(auth()->user()->client_id ? 'client.dashboard' : 'dashboard');
    }

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

    Route::get('/content-items/{contentItem}', [ContentItemController::class, 'show'])
        ->name('content-items.show');

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
    Route::patch('/user-management/{user}/activate', [UserManagementController::class, 'activate'])->name('user-management.activate');

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
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
    Route::get('/analytics/table', [AnalyticsController::class, 'table'])->name('analytics.table');
    Route::get('/analytics/{contentItem}', [AnalyticsController::class, 'show'])->name('analytics.show');
    Route::post('/analytics/ai-strategy', [AnalyticsController::class, 'generateAiStrategy'])->name('analytics.ai-strategy');
    Route::get('/analytics/ai-strategy/history', [AnalyticsController::class, 'aiStrategyHistory'])->name('analytics.ai-strategy.history');
    Route::post('/analytics/ai-strategy/{aiStrategyInsight}/apply', [AnalyticsController::class, 'applyAiStrategy'])
        ->name('analytics.ai-strategy.apply');
    Route::post('/analytics/ai-strategy/{aiStrategyInsight}/revert', [AnalyticsController::class, 'revertAiStrategy'])
        ->name('analytics.ai-strategy.revert');
    Route::post('/analytics/ai-strategy/{aiStrategyInsight}/chat', [AnalyticsController::class, 'sendChatMessage'])
        ->name('analytics.ai-strategy.chat');
    Route::post('/analytics/ai-strategy/{aiStrategyInsight}/refine', [AnalyticsController::class, 'refineFromDiscussion'])
        ->name('analytics.ai-strategy.refine');
    Route::post('/analytics/ai-strategy/{aiStrategyInsight}/ideas/{index}/regenerate', [AnalyticsController::class, 'regenerateContentIdea'])
        ->name('analytics.ai-strategy.ideas.regenerate');

    Route::get('/audience', [AudienceController::class, 'index'])->name('audience');
    Route::post('/audience/import', [AudienceController::class, 'importCsv'])->name('audience.import');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings'); 
    Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])
        ->name('notifications.mark-all-read');
    Route::post('/settings/import-performance', [SettingsController::class, 'importPerformance'])->name('settings.import-performance');
    Route::get('/settings/import', [SettingsController::class, 'importPage'])->name('settings.import');
    Route::get('/settings/integrations', [SettingsController::class, 'integrationsPage'])->name('settings.integrations');
     Route::post('/settings/detect-anomalies', [SettingsController::class, 'runAnomalyDetection'])
        ->name('settings.detect-anomalies');

    Route::get('/content-plan', [ContentPlanController::class, 'index'])->name('content-plan.index');
    Route::post('/content-plan', [ContentPlanController::class, 'store'])->name('content-plan.store');
    Route::get('/content-plan/calendar', [ContentPlanController::class, 'calendar'])
    ->name('content-plan.calendar');
    Route::get('/content-plan/create', [ContentPlanController::class, 'create'])
    ->name('content-plan.create');
    Route::get('/content-plan/{contentPlan}', [ContentPlanController::class, 'show'])->name('content-plan.show');
    Route::patch('/content-plan/{contentPlan}/approve', [ContentPlanController::class, 'approve'])->name('content-plan.approve');
    Route::patch('/content-plan/{contentPlan}/reject', [ContentPlanController::class, 'reject'])->name('content-plan.reject');
    Route::get('/content-plan/{contentPlan}/items/create', [ContentPlanController::class, 'createItem'])->name('content-plan.items.create');
    Route::post('/content-plan/{contentPlan}/items', [ContentPlanController::class, 'storeItem'])->name('content-plan.items.store');
    Route::get('/content-plan', [ContentPlanController::class, 'index'])->name('content-plan.index');Route::get('/content-plan/calendar', [ContentPlanController::class, 'calendar'])->name('content-plan.calendar'); // <-- BARU, taruh sebelum baris di bawah ini

    Route::get('/master-data', [MasterDataController::class, 'index'])->name('master-data.index');
    Route::post('/master-data/{type}', [MasterDataController::class, 'store'])->name('master-data.store');
    Route::delete('/master-data/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master-data.destroy');

    Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile', function () {
        return redirect()->route('profile.show', auth()->id());
    })->name('profile.me');

    Route::get('/publishing-tracker', [ContentPublicationController::class, 'index'])->name('publishing-tracker.index');

    Route::get('/revision-log', [ContentRevisionController::class, 'index'])->name('revision-log.index');

    Route::get('/report', [ReportController::class, 'index'])->name('report.index');
    Route::post('/report/generate', [ReportController::class, 'generate'])->name('report.generate');
    Route::post('/report/generate-performance', [ReportController::class, 'generatePerformance'])
        ->name('report.generate-performance');
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
    Route::get('/client/dashboard', [ApprovalController::class, 'index'])->name('client.dashboard');

    Route::get('/client/analytics', [ClientAnalyticsController::class, 'index'])->name('client.analytics');

    Route::get('/client/approval', [ApprovalController::class, 'index'])->name('client.approval.index');
    Route::get('/client/approval/{contentItem}', [ApprovalController::class, 'show'])->name('client.approval.show');
    Route::post('/client/approval/{contentItem}/approve', [ApprovalController::class, 'approve'])->name('client.approval.approve');
    Route::post('/client/approval/{contentItem}/request-revision', [ApprovalController::class, 'requestRevision'])->name('client.approval.request-revision');
});

Schedule::command(UpdateOverdueContentItems::class)->hourly();