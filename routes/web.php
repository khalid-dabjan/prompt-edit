<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route('generations.create'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');

    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generations')
        ->name('generations.store');

    Route::post('generations/estimate', GenerationEstimateController::class)
        ->middleware('throttle:120,1')
        ->name('generations.estimate');

    Route::get('admin/report', ReportController::class)
        ->can('viewAdminReport')
        ->name('admin.report');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');

    Route::post('credits', [CreditController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('credits.store');

    Route::get('generations/{generation}/image', GenerationImageController::class)
        ->can('view', 'generation')
        ->name('generations.image');
});

require __DIR__.'/settings.php';
