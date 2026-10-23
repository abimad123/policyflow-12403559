<?php

use App\Http\Controllers\MockCallbackController;
use App\Http\Controllers\PolicyReliabilityController;
use App\Http\Controllers\PolicyRequestController;
use App\Http\Controllers\PolicySubmissionController;
use App\Http\Middleware\EnsureLocalDemo;
use Illuminate\Support\Facades\Route;

Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show'])->whereNumber('id');
Route::post('/policy-requests/{id}/submit', [PolicySubmissionController::class, 'store'])->whereNumber('id');
Route::post('/policy-requests/{id}/retry', [PolicyReliabilityController::class, 'retry'])->whereNumber('id');
Route::post('/policy-requests/{id}/reconcile', [PolicyReliabilityController::class, 'reconcile'])->whereNumber('id');
Route::post('/mock-insurer/callback', [MockCallbackController::class, 'store'])->middleware(EnsureLocalDemo::class);
Route::get('/mock-insurer/status/{providerReference}', [MockCallbackController::class, 'status']);
Route::post('/mock-insurer/policy-requests/{id}/dispatch-callbacks', [MockCallbackController::class, 'dispatch'])->whereNumber('id');
