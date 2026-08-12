<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionWorkflowController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\ContentBriefController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\ClientMagicLinkController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\ClientManagementController;
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
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Schedule;
use App\Services\AiStrategyService;

Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();

        if ($user->client_id) {
            return redirect()->route('client.dashboard');
        }

        return redirect()->route(
            $user->hasPermissionTo('dashboard', 'view') ? 'dashboard' : 'production-workflow.index'
        );
    }

    return view('welcome');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

//Production Workflow Routes
Route::middleware(['auth', 'internal'])->group(function () {
    Route::get('/production-workflow', [ProductionWorkflowController::class, 'index'])
        ->middleware('permission:workflow,view')
        ->name('production-workflow.index');
    Route::patch('/production-workflow/{contentItem}/status', [ProductionWorkflowController::class, 'updateStatus'])
        ->middleware('permission:workflow,update')
        ->name('production-workflow.update-status');
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard,view')
        ->name('dashboard');

    Route::get('/content-items/{contentItem}', [ContentItemController::class, 'show'])
        ->middleware('permission:workflow,view')
        ->name('content-items.show');
    Route::patch('/content-items/{contentItem}/reassign', [ContentItemController::class, 'reassign'])
        ->name('content-items.reassign');
    Route::patch('/content-items/{contentItem}/transition', [ContentItemController::class, 'transition'])
        ->middleware('permission:workflow,update')
        ->name('content-items.transition');

    Route::post('/content-brief/generate/{contentItem}', [ContentBriefController::class, 'generate'])
        ->name('content-brief.generate');
    Route::get('/content-brief/{contentBrief}', [ContentBriefController::class, 'show'])
        ->name('content-brief.show');
    Route::post('/content-brief/{contentBrief}/regenerate', [ContentBriefController::class, 'regenerate'])
        ->name('content-brief.regenerate');
    Route::post('/content-brief/{contentBrief}/discuss', [ContentBriefController::class, 'discuss'])
        ->name('content-brief.discuss');
    Route::post('/content-brief/{contentBrief}/apply', [ContentBriefController::class, 'applyChanges'])
        ->name('content-brief.apply');
    Route::post('/content-brief/{contentBrief}/revert', [ContentBriefController::class, 'revert'])
        ->name('content-brief.revert');
    Route::post('/content-brief/{contentBrief}/finalize', [ContentBriefController::class, 'finalize'])
        ->name('content-brief.finalize');
    Route::post('/content-brief/{contentBrief}/withdraw', [ContentBriefController::class, 'withdraw'])
        ->name('content-brief.withdraw');

    Route::post('/production-workflow/{contentItem}/revisions', [ContentRevisionController::class, 'store'])
        ->middleware('permission:workflow,update')
        ->name('content-revision.store');
    Route::patch('/production-workflow/{contentItem}/revisions/{revision}/start-work', [ContentRevisionController::class, 'startWork'])
        ->middleware('permission:workflow,update')
        ->name('content-revision.start-work');
    Route::post('/production-workflow/{contentItem}/publications', [ContentPublicationController::class, 'store'])
        ->middleware('permission:publishing,manage')
        ->name('content-publication.store');

    Route::middleware('permission:user_management,manage')->group(function () {
        Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
        Route::get('/user-management/create', [UserManagementController::class, 'create'])->name('user-management.create');
        Route::post('/user-management', [UserManagementController::class, 'store'])->name('user-management.store');
        Route::delete('/user-management/{user}', [UserManagementController::class, 'destroy'])->name('user-management.destroy');
        Route::patch('/user-management/{user}/activate', [UserManagementController::class, 'activate'])->name('user-management.activate');

        Route::get('/user-management/{user}/clients', [UserClientAssignmentController::class, 'edit'])
            ->name('user-client-assignment.edit');
        Route::put('/user-management/{user}/clients', [UserClientAssignmentController::class, 'update'])
            ->name('user-client-assignment.update');
    });

    Route::middleware('permission:client,manage')->group(function () {
        Route::get('/client-management', [ClientManagementController::class, 'index'])->name('client-management.index');
        Route::get('/client-management/create', [ClientManagementController::class, 'create'])->name('client-management.create');
        Route::post('/client-management', [ClientManagementController::class, 'store'])->name('client-management.store');
        Route::get('/client-management/{client}', [ClientManagementController::class, 'show'])->name('client-management.show');
        Route::get('/client-management/{client}/edit', [ClientManagementController::class, 'edit'])->name('client-management.edit');
        Route::put('/client-management/{client}', [ClientManagementController::class, 'update'])->name('client-management.update');
        Route::delete('/client-management/{client}', [ClientManagementController::class, 'destroy'])->name('client-management.destroy');
    });

    Route::get('/team-performance', [TeamPerformanceController::class, 'index'])
        ->middleware('permission:team_performance,view')
        ->name('team-performance.index');

    Route::middleware('permission:analytics,view')->group(function () {
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
    });

    Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])
        ->name('notifications.mark-all-read');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::get('/search', [SearchController::class, 'search'])->name('search');

    Route::middleware('permission:settings,manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings/import-performance', [SettingsController::class, 'importPerformance'])->name('settings.import-performance');
        Route::get('/settings/import', [SettingsController::class, 'importPage'])->name('settings.import');
        Route::get('/settings/integrations', [SettingsController::class, 'integrationsPage'])->name('settings.integrations');
        Route::post('/settings/detect-anomalies', [SettingsController::class, 'runAnomalyDetection'])
            ->name('settings.detect-anomalies');
    });

    Route::middleware('permission:content_plan,view')->group(function () {
        Route::get('/content-plan', [ContentPlanController::class, 'index'])->name('content-plan.index');
        Route::get('/content-plan/calendar', [ContentPlanController::class, 'calendar'])->name('content-plan.calendar');
        Route::get('/content-plan/{contentPlan}', [ContentPlanController::class, 'show'])->name('content-plan.show');
        Route::patch('/content-plan/{contentPlan}/approve', [ContentPlanController::class, 'approve'])->name('content-plan.approve');
        Route::patch('/content-plan/{contentPlan}/reject', [ContentPlanController::class, 'reject'])->name('content-plan.reject');
    });

    Route::middleware('permission:content_plan,create')->group(function () {
        Route::post('/content-plan', [ContentPlanController::class, 'store'])->name('content-plan.store');
        Route::get('/content-plan/{contentPlan}/items/create', [ContentPlanController::class, 'createItem'])->name('content-plan.items.create');
        Route::post('/content-plan/{contentPlan}/items', [ContentPlanController::class, 'storeItem'])->name('content-plan.items.store');
    });

    Route::middleware('permission:master_data,manage')->group(function () {
        Route::get('/master-data', [MasterDataController::class, 'index'])->name('master-data.index');
        Route::post('/master-data/{type}', [MasterDataController::class, 'store'])->name('master-data.store');
        Route::delete('/master-data/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master-data.destroy');
    });

    Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile', function () {
        return redirect()->route('profile.show', auth()->id());
    })->name('profile.me');

    Route::get('/publishing-tracker', [ContentPublicationController::class, 'index'])
        ->middleware('permission:workflow,view')
        ->name('publishing-tracker.index');

    Route::get('/revision-log', [ContentRevisionController::class, 'index'])
        ->middleware('permission:workflow,view')
        ->name('revision-log.index');

    Route::middleware('permission:report,view')->group(function () {
        Route::get('/report', [ReportController::class, 'index'])->name('report.index');
        Route::post('/report/generate', [ReportController::class, 'generate'])->name('report.generate');
        Route::post('/report/generate-performance', [ReportController::class, 'generatePerformance'])
            ->name('report.generate-performance');
    });
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