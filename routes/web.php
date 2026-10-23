<?php

use AmjadIqbal\PennantExperiments\Http\Controllers\ResultsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ResultsController::class, 'index'])->name('pennant-experiments.index');
Route::get('/{experiment}', [ResultsController::class, 'show'])->where('experiment', '[A-Za-z0-9._:-]+')->name('pennant-experiments.show');
Route::post('/{experiment}/end', [ResultsController::class, 'end'])->where('experiment', '[A-Za-z0-9._:-]+')->name('pennant-experiments.end');
Route::post('/{experiment}/restart', [ResultsController::class, 'restart'])->where('experiment', '[A-Za-z0-9._:-]+')->name('pennant-experiments.restart');
