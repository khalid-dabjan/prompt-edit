<?php

use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('generations.store');
});

require __DIR__.'/settings.php';
