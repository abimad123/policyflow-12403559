<?php

use App\Http\Middleware\EnsureLocalDemo;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureLocalDemo::class)->group(function () {
    Route::view('/', 'policies.index')->name('policies.index');
    Route::view('/policies/new', 'policies.create')->name('policies.create');
    Route::get('/policies/{id}', fn (int $id) => view('policies.show', ['policyId' => $id]))
        ->whereNumber('id')->name('policies.show');
});
