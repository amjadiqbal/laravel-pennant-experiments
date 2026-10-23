<?php

use AmjadIqbal\PennantExperiments\Http\Controllers\TrackController;
use Illuminate\Support\Facades\Route;

Route::get('/variant/{experiment}', [TrackController::class, 'variant'])->where('experiment', '[A-Za-z0-9._:-]+')->name('pennant-experiments.track.variant');
Route::post('/expose', [TrackController::class, 'expose'])->name('pennant-experiments.track.expose');
Route::post('/convert', [TrackController::class, 'convert'])->name('pennant-experiments.track.convert');
