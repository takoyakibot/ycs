<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ManageController;
use App\Http\Controllers\MarkdownController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SongController;
use App\Http\Controllers\TimestampDecompositionController;
use App\Http\Controllers\TimestampReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
 */

// 管理者（Channel Admin以上）専用機能
Route::middleware(['auth', 'admin'])->group(function () {
    // TODO: 別のサービスができるまでは自動的に歌枠検索に飛ばす
    Route::redirect('/manage', '/channels/manage', 301);

    Route::get('/channels/manage', [ManageController::class, 'index'])->name('manage.index');
    Route::get('/channels/manage/{id}', [ManageController::class, 'show'])->name('manage.show');
    Route::get('/channels/manage/{id}/settings', [ManageController::class, 'settings'])->name('manage.settings');

    // 楽曲マスタ管理
    Route::get('/songs/normalize', [SongController::class, 'index'])->name('songs.index');

    // タイムスタンプ分解・選別
    Route::get('/songs/decompose', [TimestampDecompositionController::class, 'index'])->name('songs.decompose');
    Route::get('api/songs/decompose/next', [TimestampDecompositionController::class, 'next'])->name('songs.decompose.next');
    Route::post('api/songs/decompose/select', [TimestampDecompositionController::class, 'select'])->name('songs.decompose.select');
    Route::post('api/songs/decompose/{id}/skip', [TimestampDecompositionController::class, 'skip'])->name('songs.decompose.skip');
    Route::get('api/songs/decompose/statistics', [TimestampDecompositionController::class, 'statistics'])->name('songs.decompose.statistics');
    Route::post('api/songs/decompose/scan', [TimestampDecompositionController::class, 'scan'])->name('songs.decompose.scan');
    Route::post('api/songs/decompose/bulk-link', [TimestampDecompositionController::class, 'bulkLink'])->name('songs.decompose.bulkLink');

    Route::get('api/manage/channels', [ManageController::class, 'fetchChannel'])->name('manage.fetchChannel');
    Route::post('api/manage/channels', [ManageController::class, 'addChannel'])->name('manage.addChannel');
    Route::get('api/manage/channels/{id}', [ManageController::class, 'fetchArchives'])->name('manage.fetchArchives');
    Route::post('api/manage/archives', [ManageController::class, 'addArchives'])->name('manage.addArchives');
    Route::patch('api/manage/archives/toggle-display', [ManageController::class, 'toggleDisplay'])->name('manage.toggleDisplay');
    Route::patch('api/manage/archives/fetch-comments', [ManageController::class, 'fetchComments'])->name('manage.fetchComments');
    Route::patch('api/manage/archives/edit-timestamps', [ManageController::class, 'editTimestamps'])->name('manage.editTimestamps');

    // チャンネル設定API（除外ワード管理）
    Route::get('api/manage/channels/{id}/excluded-words', [ManageController::class, 'fetchExcludedWords'])->name('manage.fetchExcludedWords');
    Route::post('api/manage/channels/{id}/excluded-words', [ManageController::class, 'addExcludedWord'])->name('manage.addExcludedWord');
    Route::delete('api/manage/channels/{id}/excluded-words/{wordId}', [ManageController::class, 'deleteExcludedWord'])->name('manage.deleteExcludedWord');
    Route::get('api/manage/channels/{id}/cover-songs/preview', [ManageController::class, 'previewCoverSongs'])->name('manage.previewCoverSongs');
    Route::post('api/manage/channels/{id}/cover-songs/reprocess', [ManageController::class, 'reprocessCoverSongs'])->name('manage.reprocessCoverSongs');

    // 楽曲マスタAPI
    Route::get('api/songs/timestamps', [SongController::class, 'fetchTimestamps'])->name('songs.fetchTimestamps');
    Route::get('api/songs', [SongController::class, 'fetchSongs'])->name('songs.fetchSongs');
    Route::post('api/songs', [SongController::class, 'storeSong'])->name('songs.storeSong');
    Route::post('api/songs/link', [SongController::class, 'linkTimestamp'])->name('songs.linkTimestamp');
    Route::post('api/songs/mark-not-song', [SongController::class, 'markAsNotSong'])->name('songs.markAsNotSong');
    Route::post('api/songs/unmark-not-song', [SongController::class, 'unmarkAsNotSong'])->name('songs.unmarkAsNotSong');
    Route::post('api/songs/confirm-auto-link', [SongController::class, 'confirmAutoLink'])->name('songs.confirmAutoLink');
    Route::post('api/songs/mark-pending', [SongController::class, 'markAsPending'])->name('songs.markAsPending');
    // Specific routes must come before parameterized routes to avoid parameter capture
    Route::delete('api/songs/unlink', [SongController::class, 'unlinkTimestamp'])->name('songs.unlinkTimestamp');
    Route::get('api/songs/fuzzy-search', [SongController::class, 'fuzzySearch'])->name('songs.fuzzySearch');
    Route::get('api/songs/search-spotify', [SongController::class, 'searchSpotify'])->name('songs.searchSpotify');
    Route::post('api/songs/video-duration', [SongController::class, 'fetchVideoDuration'])->name('songs.fetchVideoDuration');
    // 個別マッピング用API
    Route::post('api/songs/link-ts-item', [SongController::class, 'linkTsItemToSong'])->name('songs.linkTsItemToSong');
    Route::delete('api/songs/unlink-ts-item', [SongController::class, 'unlinkTsItem'])->name('songs.unlinkTsItem');
    Route::get('api/songs/ts-items-by-normalized-text', [SongController::class, 'getTsItemsByNormalizedText'])->name('songs.getTsItemsByNormalizedText');
    // Parameterized route - must be last to avoid capturing specific route names
    Route::put('api/songs/{id}', [SongController::class, 'updateSong'])->name('songs.updateSong');
    Route::delete('api/songs/{id}', [SongController::class, 'deleteSong'])->name('songs.deleteSong');
});

// スーパー管理者専用機能
Route::middleware(['auth', 'super_admin'])->group(function () {
    // ログ管理
    Route::get('/manage/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/manage/logs/{filename}', [LogController::class, 'show'])->name('logs.show');
    Route::get('/manage/logs/{filename}/download', [LogController::class, 'download'])->name('logs.download');
    Route::delete('/manage/logs/{filename}', [LogController::class, 'delete'])->name('logs.delete');

    // タイムスタンプ報告管理
    Route::get('/manage/reports', [TimestampReportController::class, 'manage'])->name('reports.index');

    // タイムスタンプ報告管理API
    Route::get('api/manage/timestamp-reports', [TimestampReportController::class, 'index'])->name('timestamp-reports.index');
    Route::get('api/manage/timestamp-reports/{report}', [TimestampReportController::class, 'show'])->name('timestamp-reports.show');
    Route::patch('api/manage/timestamp-reports/{report}/resolve', [TimestampReportController::class, 'resolve'])->name('timestamp-reports.resolve');

    // 管理者管理
    Route::get('/manage/admins', [AdminController::class, 'index'])->name('admins.index');
    Route::get('api/manage/admins', [AdminController::class, 'fetchAdmins'])->name('admins.fetchAdmins');
    Route::post('api/manage/admins', [AdminController::class, 'store'])->name('admins.store');
    Route::delete('api/manage/admins/{id}', [AdminController::class, 'destroy'])->name('admins.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/api-key', [ProfileController::class, 'destroyApiKey'])->name('profile.api-key.destroy');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/', [ChannelController::class, 'index'])->name('top');
Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::get('/channels/{id}', [ChannelController::class, 'show'])->name('channels.show');

Route::get('api/channels/{id}', [ChannelController::class, 'fetchArchives'])->name('channels.fetchArchives');
Route::get('api/channels/{id}/timestamps', [ChannelController::class, 'fetchTimestamps'])->name('channels.fetchTimestamps');
Route::get('api/channels/{id}/timestamps/random', [ChannelController::class, 'fetchRandomTimestamp'])
    ->name('channels.fetchRandomTimestamp')
    ->middleware('throttle:30,1'); // 1分間に30回まで
Route::post('api/channels/{id}/timestamps/next-in-archive', [ChannelController::class, 'fetchNextTimestampInArchive'])
    ->name('channels.fetchNextTimestampInArchive')
    ->middleware('throttle:60,1'); // 1分間に60回まで
Route::get('api/channels/{id}/timestamps/texts', [ChannelController::class, 'fetchTimestampTexts'])
    ->name('channels.fetchTimestampTexts')
    ->middleware('throttle:10,1'); // 1分間に10回まで
Route::get('api/channels/{id}/timestamps/download', [ChannelController::class, 'downloadTimestamps'])
    ->name('channels.downloadTimestamps')
    ->middleware('throttle:10,1'); // 1分間に10回まで

Route::get('/terms', [MarkdownController::class, 'show'])->name('markdown.show');

// お問い合わせフォーム（ゲスト可、レートリミット適用）
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact', [ContactController::class, 'store'])
    ->name('contact.store')
    ->middleware('throttle:5,60'); // 60分間に5回まで

// タイムスタンプ報告API（ゲスト可）
Route::post('api/timestamp-reports', [TimestampReportController::class, 'store'])->name('timestamp-reports.store');

// ユーザー操作ログAPI（ゲスト可、レートリミット適用）
Route::post('api/user-actions/log', [\App\Http\Controllers\UserActionLogController::class, 'log'])
    ->name('user-actions.log')
    ->middleware('throttle:60,1'); // 1分間に60回まで

require __DIR__.'/auth.php';
