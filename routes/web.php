<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ManageController;
use App\Http\Controllers\ProfileController;
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
    //TODO: 別のサービスができるまでは自動的に歌枠検索に飛ばす
    Route::redirect('/manage', '/channels/manage', 301);

    Route::get('/channels/manage', [ManageController::class, 'index'])->name('manage.index');
    Route::get('/channels/manage/{id}', [ManageController::class, 'show'])->name('manage.show');

    Route::get('api/channels', [ManageController::class, 'fetchChannel'])->name('manage.fetchChannel');
    Route::post('api/channels', [ManageController::class, 'addChannel'])->name('manage.addChannel');
    Route::get('api/archives', [ManageController::class, 'fetchArchive'])->name('manage.fetchArchive');
    Route::post('api/archives', [ManageController::class, 'addArchives'])->name('manage.addArchives');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/', [ChannelController::class, 'index'])->name('top');
Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::get('/channels/{id}', [ChannelController::class, 'show'])
    ->name('channels.show');

require __DIR__ . '/auth.php';
