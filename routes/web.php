<?php

use App\Http\Middleware\EnsureLocalDemo;
use Illuminate\Support\Facades\Route;

Route::view('/', 'policies.index')->middleware('throttle:demo-read')->name('policies.index');

Route::middleware([EnsureLocalDemo::class, 'throttle:demo-read'])->group(function () {
    Route::view('/policies/new', 'policies.create')->name('policies.create');
    Route::get('/policies/{id}', fn (int $id) => view('policies.show', ['policyId' => $id]))
        ->whereNumber('id')->name('policies.show');
});
