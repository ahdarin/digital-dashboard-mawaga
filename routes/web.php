<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionWorkflowController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\ClientMagicLinkController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\ClientOnboardingController;

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

    Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
    Route::get('/user-management/create', [UserManagementController::class, 'create'])->name('user-management.create');
    Route::post('/user-management', [UserManagementController::class, 'store'])->name('user-management.store');
    Route::delete('/user-management/{user}', [UserManagementController::class, 'destroy'])->name('user-management.destroy');

    Route::get('/client-onboarding', [ClientOnboardingController::class, 'index'])->name('client-onboarding.index');
    Route::get('/client-onboarding/create', [ClientOnboardingController::class, 'create'])->name('client-onboarding.create');
    Route::post('/client-onboarding', [ClientOnboardingController::class, 'store'])->name('client-onboarding.store');
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