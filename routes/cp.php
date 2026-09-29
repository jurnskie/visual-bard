<?php

use Illuminate\Support\Facades\Route;
use Jurnskie\VisualBard\Http\Controllers\PreviewBardController;
use Jurnskie\VisualBard\Http\Controllers\SaveBardController;
use Jurnskie\VisualBard\Http\Controllers\ShowBuilderController;
use Jurnskie\VisualBard\Http\Controllers\ShowCanvasController;

Route::prefix('visual-bard/{entry}/{field}')->name('visual-bard.')->group(function () {
    Route::get('/', ShowBuilderController::class)->name('show');
    Route::get('canvas', ShowCanvasController::class)->name('canvas');
    Route::post('preview', PreviewBardController::class)->name('preview');
    Route::patch('/', SaveBardController::class)->name('update');
});
