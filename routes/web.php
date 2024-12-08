<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
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

Route::get('/', [ChannelController::class, 'index']);

Route::get('/{id}', [ChannelController::class, 'show'])
    ->name('channels.show')
    ->where('id', '[0-9]+');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::put('/dashboard/api-key', [DashboardController::class, 'updateApiKey'])->name('dashboard.updateApiKey');
    Route::post('/dashboard/api-key', [DashboardController::class, 'registerApiKey'])->name('dashboard.registerApiKey');
    Route::post('/dashboard/add-channel', [DashboardController::class, 'addChannel'])->name('dashboard.addChannel');
    Route::get('/dashboard/{id}', [DashboardController::class, 'manageChannel'])->name('dashboard.channel');
    Route::post('/dashboard/{id}/update-achives', [DashboardController::class, 'updateAchives'])->name('dashboard.updateAchives');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
