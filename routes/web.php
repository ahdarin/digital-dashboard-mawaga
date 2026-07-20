<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionWorkflowController;
use App\Http\Controllers\Auth\GoogleAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

//Production Workflow Routes
Route::middleware('auth')->group(function () {
    Route::get('/production-workflow', [ProductionWorkflowController::class, 'index'])
        ->name('production-workflow.index');

    Route::patch('/production-workflow/{contentItem}/status', [ProductionWorkflowController::class, 'updateStatus'])
        ->name('production-workflow.update-status');
});

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

Route::post('/logout', function (Illuminate\Http\Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('login');
})->middleware('auth')->name('logout');