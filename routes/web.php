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

Route::get('/', [ChannelController::class, 'index'])->name('top');

Route::get('/{id}', [ChannelController::class, 'show'])
    ->name('channels.show')
    ->where('id', '[0-9]+');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/manage', [ManageController::class, 'index'])->name('manage');
    Route::post('/manage/add-channel', [ManageController::class, 'addChannel'])->name('manage.addChannel');
    Route::get('/manage/{id}', [ManageController::class, 'manageChannel'])->name('manage.channel');
    Route::post('/manage/{id}', [ManageController::class, 'updateAchives'])->name('manage.updateAchives');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
