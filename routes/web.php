<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionWorkflowController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AttendanceController;
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
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\CalendarController as ClientCalendarController;
use App\Http\Controllers\Client\HistoryController as ClientHistoryController;
use App\Http\Controllers\AudienceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ContentPlanController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\PackageTemplateController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\PreferencesController;
use Illuminate\Support\Facades\Schedule;
use App\Services\AiStrategyService;

Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();

        if ($user->client_id) {
            return redirect()->route('client.dashboard');
        }

        return redirect()->route('profile.me');
    }

    return view('welcome');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

// Preferensi tampilan personal (tema dsb) - dipakai user internal MAUPUN
// klien, jadi cuma butuh 'auth', bukan di dalam grup 'internal' atau
// 'client.user' manapun.
Route::middleware('auth')->group(function () {
    Route::patch('/preferences/theme', [PreferencesController::class, 'updateTheme'])
        ->name('preferences.theme');
});

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
        ->middleware(['permission:workflow,view', 'client.scope:contentItem'])
        ->name('content-items.show');
    Route::patch('/content-items/{contentItem}/reassign', [ContentItemController::class, 'reassign'])
        ->middleware(['permission:workflow,update', 'client.scope:contentItem'])
        ->name('content-items.reassign');
    Route::patch('/content-items/{contentItem}/transition', [ContentItemController::class, 'transition'])
        ->middleware(['permission:workflow,update', 'client.scope:contentItem'])
        ->name('content-items.transition');
    Route::patch('/content-items/{contentItem}/correct-status', [ContentItemController::class, 'correctStatus'])
        ->middleware(['permission:workflow,approve', 'client.scope:contentItem'])
        ->name('content-items.correct-status');
    Route::patch('/content-items/{contentItem}/content-link', [ContentItemController::class, 'updateContentLink'])
        ->middleware(['permission:workflow,update', 'client.scope:contentItem'])
        ->name('content-items.content-link');
    Route::patch('/content-items/{contentItem}/caption', [ContentItemController::class, 'updateCaption'])
        ->middleware(['permission:content_plan,create', 'client.scope:contentItem'])
        ->name('content-items.caption');
    Route::patch('/content-items/{contentItem}/footage-captured', [ContentItemController::class, 'markFootageCaptured'])
        ->middleware(['permission:workflow,update', 'client.scope:contentItem'])
        ->name('content-items.footage-captured');
    Route::delete('/content-items/{contentItem}/footage-captured', [ContentItemController::class, 'unmarkFootageCaptured'])
        ->middleware(['permission:workflow,update', 'client.scope:contentItem'])
        ->name('content-items.footage-captured.unmark');
    Route::post('/content-items/{contentItem}/pin', [ContentItemController::class, 'pin'])
        ->middleware(['permission:workflow,view', 'client.scope:contentItem'])
        ->name('content-items.pin');
    Route::delete('/content-items/{contentItem}/pin', [ContentItemController::class, 'unpin'])
        ->middleware(['permission:workflow,view', 'client.scope:contentItem'])
        ->name('content-items.pin.unmark');

    Route::get('/content-brief/{contentBrief}', [ContentBriefController::class, 'show'])
        ->middleware(['permission:workflow,view', 'client.scope:contentBrief,contentItem.client_id'])
        ->name('content-brief.show');

    Route::middleware('permission:content_plan,create')->group(function () {
        Route::post('/content-brief/generate/{contentItem}', [ContentBriefController::class, 'generate'])
            ->middleware('client.scope:contentItem')
            ->name('content-brief.generate');
        Route::post('/content-brief/{contentBrief}/regenerate', [ContentBriefController::class, 'regenerate'])
            ->middleware('client.scope:contentBrief,contentItem.client_id')
            ->name('content-brief.regenerate');
        Route::post('/content-brief/{contentBrief}/discuss', [ContentBriefController::class, 'discuss'])
            ->middleware('client.scope:contentBrief,contentItem.client_id')
            ->name('content-brief.discuss');
        Route::post('/content-brief/{contentBrief}/apply', [ContentBriefController::class, 'applyChanges'])
            ->middleware('client.scope:contentBrief,contentItem.client_id')
            ->name('content-brief.apply');
        Route::patch('/content-brief/{contentBrief}', [ContentBriefController::class, 'updateManual'])
            ->middleware('client.scope:contentBrief,contentItem.client_id')
            ->name('content-brief.update');
        Route::post('/content-brief/{contentBrief}/revert', [ContentBriefController::class, 'revert'])
            ->middleware('client.scope:contentBrief,contentItem.client_id')
            ->name('content-brief.revert');
        Route::post('/content-brief/{contentBrief}/finalize', [ContentBriefController::class, 'finalize'])
            ->middleware('client.scope:contentBrief,contentItem.client_id')
            ->name('content-brief.finalize');
        Route::post('/content-brief/{contentBrief}/withdraw', [ContentBriefController::class, 'withdraw'])
            ->middleware('client.scope:contentBrief,contentItem.client_id')
            ->name('content-brief.withdraw');
    });

    Route::post('/production-workflow/{contentItem}/revisions', [ContentRevisionController::class, 'store'])
        ->middleware(['permission:workflow,update', 'client.scope:contentItem'])
        ->name('content-revision.store');
    Route::patch('/production-workflow/{contentItem}/revisions/{revision}/start-work', [ContentRevisionController::class, 'startWork'])
        ->middleware(['permission:workflow,update', 'client.scope:contentItem'])
        ->name('content-revision.start-work');
    Route::post('/production-workflow/{contentItem}/publications', [ContentPublicationController::class, 'store'])
        ->middleware(['permission:publishing,manage', 'client.scope:contentItem'])
        ->name('content-publication.store');

    Route::middleware('permission:user_management,manage')->group(function () {
        Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
        Route::post('/user-management', [UserManagementController::class, 'store'])->name('user-management.store');
        Route::delete('/user-management/{user}', [UserManagementController::class, 'destroy'])->name('user-management.destroy');
        Route::patch('/user-management/{user}/activate', [UserManagementController::class, 'activate'])->name('user-management.activate');
        Route::put('/user-management/{user}/roles', [UserManagementController::class, 'updateRoles'])->name('user-management.roles.update');

        Route::put('/user-management/{user}/clients', [UserClientAssignmentController::class, 'update'])
            ->name('user-client-assignment.update');
        Route::put('/client-management/{client}/pic', [UserClientAssignmentController::class, 'updateForClient'])
            ->name('client-management.pic.update');
        Route::delete('/client-management/{client}/pic/{user}', [UserClientAssignmentController::class, 'removeFromClient'])
            ->name('client-management.pic.remove');
    });

    // Detail 1 client dibuka ke semua role internal (bukan cuma CEO/Manager)
    // supaya hasil search "klien" nggak jadi dead-end 403 - tapi tetap
    // discope ke client yang di-assign ke dia (client.scope, sama seperti
    // content item). Aksi ubah data (edit/hapus/dst) tetap di grup
    // client,manage di bawah - tombolnya di-disable di view buat yang
    // nggak punya izin itu.
    Route::middleware(['permission:client,view', 'client.scope:client,id'])->group(function () {
        Route::get('/client-management/{client}', [ClientManagementController::class, 'show'])->name('client-management.show');
    });

    Route::middleware('permission:client,manage')->group(function () {
        Route::get('/client-management', [ClientManagementController::class, 'index'])->name('client-management.index');
        Route::get('/client-management/create', [ClientManagementController::class, 'create'])->name('client-management.create');
        Route::post('/client-management', [ClientManagementController::class, 'store'])->name('client-management.store');
        Route::get('/client-management/{client}/edit', [ClientManagementController::class, 'edit'])->name('client-management.edit');
        Route::put('/client-management/{client}', [ClientManagementController::class, 'update'])->name('client-management.update');
        Route::delete('/client-management/{client}', [ClientManagementController::class, 'destroy'])->name('client-management.destroy');
        Route::put('/client-management/{client}/package', [ClientManagementController::class, 'updatePackage'])->name('client-management.package.update');
    });

    Route::get('/team-performance', [TeamPerformanceController::class, 'index'])
        ->middleware('permission:team_performance,view')
        ->name('team-performance.index');

    Route::middleware('permission:analytics,view')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
        Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
        Route::get('/analytics/{contentItem}', [AnalyticsController::class, 'show'])
            ->middleware('client.scope:contentItem')
            ->name('analytics.show');
        Route::post('/analytics/ai-strategy', [AnalyticsController::class, 'generateAiStrategy'])->name('analytics.ai-strategy');
        Route::get('/analytics/ai-strategy/history', [AnalyticsController::class, 'aiStrategyHistory'])->name('analytics.ai-strategy.history');
        Route::post('/analytics/ai-strategy/{aiStrategyInsight}/apply', [AnalyticsController::class, 'applyAiStrategy'])
            ->middleware('client.scope:aiStrategyInsight')
            ->name('analytics.ai-strategy.apply');
        Route::post('/analytics/ai-strategy/{aiStrategyInsight}/revert', [AnalyticsController::class, 'revertAiStrategy'])
            ->middleware('client.scope:aiStrategyInsight')
            ->name('analytics.ai-strategy.revert');
        Route::post('/analytics/ai-strategy/{aiStrategyInsight}/chat', [AnalyticsController::class, 'sendChatMessage'])
            ->middleware('client.scope:aiStrategyInsight')
            ->name('analytics.ai-strategy.chat');
        Route::post('/analytics/ai-strategy/{aiStrategyInsight}/refine', [AnalyticsController::class, 'refineFromDiscussion'])
            ->middleware('client.scope:aiStrategyInsight')
            ->name('analytics.ai-strategy.refine');
        Route::post('/analytics/ai-strategy/{aiStrategyInsight}/ideas/{index}/regenerate', [AnalyticsController::class, 'regenerateContentIdea'])
            ->middleware('client.scope:aiStrategyInsight')
            ->name('analytics.ai-strategy.ideas.regenerate');

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
        Route::post('/settings/detect-anomalies', [SettingsController::class, 'runAnomalyDetection'])
            ->name('settings.detect-anomalies');
    });

    Route::middleware('permission:content_plan,view')->group(function () {
        Route::get('/content-plan', [ContentPlanController::class, 'index'])->name('content-plan.index');
        Route::get('/content-plan/{contentPlan}', [ContentPlanController::class, 'show'])
            ->middleware('client.scope:contentPlan')
            ->name('content-plan.show');
    });

    Route::middleware('permission:content_plan,create')->group(function () {
        Route::patch('/content-plan/{contentPlan}/submit', [ContentPlanController::class, 'submit'])
            ->middleware('client.scope:contentPlan')
            ->name('content-plan.submit');
    });

    Route::middleware('permission:content_plan,approve')->group(function () {
        Route::patch('/content-plan/{contentPlan}/approve', [ContentPlanController::class, 'approve'])
            ->middleware('client.scope:contentPlan')
            ->name('content-plan.approve');
        Route::patch('/content-plan/{contentPlan}/reject', [ContentPlanController::class, 'reject'])
            ->middleware('client.scope:contentPlan')
            ->name('content-plan.reject');
    });

    Route::middleware('permission:content_plan,create')->group(function () {
        Route::post('/content-plan', [ContentPlanController::class, 'store'])->name('content-plan.store');
        Route::get('/content-plan/{contentPlan}/items/create', [ContentPlanController::class, 'createItem'])
            ->middleware('client.scope:contentPlan')
            ->name('content-plan.items.create');
        Route::post('/content-plan/{contentPlan}/items', [ContentPlanController::class, 'storeItem'])
            ->middleware('client.scope:contentPlan')
            ->name('content-plan.items.store');
        Route::post('/content-items/urgent', [ContentPlanController::class, 'quickCreateUrgent'])->name('content-items.quick-urgent');
    });

    Route::middleware('permission:master_data,manage')->group(function () {
        Route::post('/package-templates', [PackageTemplateController::class, 'store'])->name('package-templates.store');
        Route::put('/package-templates/{packageTemplate}', [PackageTemplateController::class, 'update'])->name('package-templates.update');
        Route::delete('/package-templates/{packageTemplate}', [PackageTemplateController::class, 'destroy'])->name('package-templates.destroy');

        Route::post('/master-data/{type}', [MasterDataController::class, 'store'])->name('master-data.store');
        Route::delete('/master-data/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master-data.destroy');
    });

    Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/beranda', [HomeController::class, 'index'])->name('profile.me');

    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('attendance.check-in');
    Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('attendance.check-out');

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
    Route::get('/client/dashboard', [ClientDashboardController::class, 'index'])->name('client.dashboard');

    Route::get('/client/calendar', [ClientCalendarController::class, 'index'])->name('client.calendar');

    Route::get('/client/riwayat', [ClientHistoryController::class, 'index'])->name('client.history');

    Route::get('/client/analytics', [ClientAnalyticsController::class, 'index'])->name('client.analytics');

    Route::get('/client/approval/{contentItem}', [ApprovalController::class, 'show'])->name('client.approval.show');
    Route::post('/client/approval/{contentItem}/approve', [ApprovalController::class, 'approve'])->name('client.approval.approve');
    Route::post('/client/approval/{contentItem}/request-revision', [ApprovalController::class, 'requestRevision'])->name('client.approval.request-revision');
});

Schedule::command(UpdateOverdueContentItems::class)->hourly();