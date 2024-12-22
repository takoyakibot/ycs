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
    Route::get('/channels/manage', [ManageController::class, 'index'])->name('manage');
    Route::get('/channels/manage/{id}', [ManageController::class, 'manageChannel'])->name('manage.channel');
    Route::post('/channels/manage/{id}', [ManageController::class, 'updateAchives'])->name('manage.updateAchives');
    Route::get('api/channels', [ManageController::class, 'fetchChannel'])->name('manage.fetchChannel');
    Route::post('api/channels', [ManageController::class, 'addChannel'])->name('manage.addChannel');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/', [ChannelController::class, 'index'])->name('top');
Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::get('/channels/{id}', [ChannelController::class, 'channels.show'])
    ->name('channels.show');

require __DIR__ . '/auth.php';
