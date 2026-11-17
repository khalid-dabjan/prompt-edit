<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');

    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generations')
        ->name('generations.store');

    Route::post('generations/estimate', GenerationEstimateController::class)
        ->middleware('throttle:120,1')
        ->name('generations.estimate');

    Route::get('generations/{generation}/image', GenerationImageController::class)
        ->can('view', 'generation')
        ->name('generations.image');
});

require __DIR__.'/settings.php';
