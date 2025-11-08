<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ManageController;
use App\Http\Controllers\MarkdownController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SongController;
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

Route::middleware(['auth', 'verified'])->group(function () {
    // TODO: 別のサービスができるまでは自動的に歌枠検索に飛ばす
    Route::redirect('/manage', '/channels/manage', 301);

    Route::get('/channels/manage', [ManageController::class, 'index'])->name('manage.index');
    Route::get('/channels/manage/{id}', [ManageController::class, 'show'])->name('manage.show');

    // 楽曲マスタ管理
    Route::get('/songs/normalize', [SongController::class, 'index'])->name('songs.index');

    // ログ管理
    Route::get('/manage/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/manage/logs/{filename}', [LogController::class, 'show'])->name('logs.show');
    Route::get('/manage/logs/{filename}/download', [LogController::class, 'download'])->name('logs.download');
    Route::delete('/manage/logs/{filename}', [LogController::class, 'delete'])->name('logs.delete');

    Route::get('api/manage/channels', [ManageController::class, 'fetchChannel'])->name('manage.fetchChannel');
    Route::post('api/manage/channels', [ManageController::class, 'addChannel'])->name('manage.addChannel');
    Route::get('api/manage/channels/{id}', [ManageController::class, 'fetchArchives'])->name('manage.fetchArchives');
    Route::post('api/manage/archives', [ManageController::class, 'addArchives'])->name('manage.addArchives');
    Route::patch('api/manage/archives/toggle-display', [ManageController::class, 'toggleDisplay'])->name('manage.toggleDisplay');
    Route::patch('api/manage/archives/fetch-comments', [ManageController::class, 'fetchComments'])->name('manage.fetchComments');
    Route::patch('api/manage/archives/edit-timestamps', [ManageController::class, 'editTimestamps'])->name('manage.editTimestamps');

    // 楽曲マスタAPI
    Route::get('api/songs/timestamps', [SongController::class, 'fetchTimestamps'])->name('songs.fetchTimestamps');
    Route::get('api/songs', [SongController::class, 'fetchSongs'])->name('songs.fetchSongs');
    Route::post('api/songs', [SongController::class, 'storeSong'])->name('songs.storeSong');
    Route::delete('api/songs/{id}', [SongController::class, 'deleteSong'])->name('songs.deleteSong');
    Route::post('api/songs/link', [SongController::class, 'linkTimestamp'])->name('songs.linkTimestamp');
    Route::post('api/songs/mark-not-song', [SongController::class, 'markAsNotSong'])->name('songs.markAsNotSong');
    Route::delete('api/songs/unlink', [SongController::class, 'unlinkTimestamp'])->name('songs.unlinkTimestamp');
    Route::get('api/songs/fuzzy-search', [SongController::class, 'fuzzySearch'])->name('songs.fuzzySearch');
    Route::get('api/songs/search-spotify', [SongController::class, 'searchSpotify'])->name('songs.searchSpotify');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/', [ChannelController::class, 'index'])->name('top');
Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::get('/channels/{id}', [ChannelController::class, 'show'])->name('channels.show');

Route::get('api/channels/{id}', [ChannelController::class, 'fetchArchives'])->name('channels.fetchArchives');
Route::get('api/channels/{id}/timestamps', [ChannelController::class, 'fetchTimestamps'])->name('channels.fetchTimestamps');

Route::get('/terms', [MarkdownController::class, 'show'])->name('markdown.show');

require __DIR__.'/auth.php';
