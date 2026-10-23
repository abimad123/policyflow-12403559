<?php

use App\Http\Controllers\MockCallbackController;
use App\Http\Controllers\PolicyReliabilityController;
use App\Http\Controllers\PolicyRequestController;
use App\Http\Controllers\PolicySubmissionController;
use App\Http\Middleware\EnsureLocalDemo;
use App\Http\Middleware\EnsureDemoWrite;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:demo-write', EnsureDemoWrite::class])->group(function () {
    Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
    Route::post('/policy-requests/{id}/submit', [PolicySubmissionController::class, 'store'])->whereNumber('id');
    Route::post('/policy-requests/{id}/retry', [PolicyReliabilityController::class, 'retry'])->whereNumber('id');
    Route::post('/policy-requests/{id}/reconcile', [PolicyReliabilityController::class, 'reconcile'])->whereNumber('id');
    Route::post('/mock-insurer/callback', [MockCallbackController::class, 'store'])->middleware(EnsureLocalDemo::class);
    Route::post('/mock-insurer/policy-requests/{id}/dispatch-callbacks', [MockCallbackController::class, 'dispatch'])->whereNumber('id');
});

Route::middleware('throttle:demo-read')->group(function () {
    Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
    Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show'])->whereNumber('id');
});

Route::get('/mock-insurer/status/{providerReference}', [MockCallbackController::class, 'status'])
    ->middleware(['throttle:demo-read', EnsureDemoWrite::class]);
