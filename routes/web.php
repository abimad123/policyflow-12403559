<?php

use App\Http\Middleware\EnsureLocalDemo;
use Illuminate\Support\Facades\Route;

Route::view('/', 'policies.index')->middleware('throttle:demo-read')->name('policies.index');
Route::view('/policies/new', 'policies.create')->middleware('throttle:demo-read')->name('policies.create');

Route::middleware([EnsureLocalDemo::class, 'throttle:demo-read'])->group(function () {
    Route::get('/policies/{id}', fn (int $id) => view('policies.show', ['policyId' => $id]))
        ->whereNumber('id')->name('policies.show');
});
