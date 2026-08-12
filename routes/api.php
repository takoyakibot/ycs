<?php

use App\Http\Controllers\HighlightDetectionApiController;
use App\Http\Controllers\SubtitleApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Chrome拡張用API（Sanctumトークン認証）
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('manage/archives/subtitles/store', [SubtitleApiController::class, 'store'])
        ->middleware('throttle:30,1');

    Route::post('extension/highlights/detect', [HighlightDetectionApiController::class, 'detect'])
        ->middleware('throttle:10,1');

    Route::get('extension/subtitle-matches', [SubtitleApiController::class, 'matchByPosition'])
        ->middleware('throttle:30,1');

    Route::get('extension/subtitle-targets', [SubtitleApiController::class, 'subtitleTargets'])
        ->middleware('throttle:10,1');

    Route::get('extension/scan-targets', [SubtitleApiController::class, 'scanTargets'])
        ->middleware('throttle:10,1');
});
