<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CollectionExportController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ImportUploadController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OnThisDayController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PostDetailController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->name('login.store');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->name('register.store');
});

Route::middleware(['auth', 'app.settings', 'privacy'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/settings/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/settings/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');
    Route::get('/', [ArchiveController::class, 'index'])->name('archives.index');
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::get('/map', [MapController::class, 'index'])->name('map.index');
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
    Route::get('/people/{person}', [PeopleController::class, 'show'])->name('people.show');
    Route::get('/on-this-day', [OnThisDayController::class, 'index'])->name('on-this-day.index');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::post('/collections', [CollectionController::class, 'store'])->name('collections.store');
    Route::post('/collections/{postCollection}/posts/{post}', [CollectionController::class, 'addPost'])->name('collections.posts.store');
    Route::delete('/collections/{postCollection}/posts/{post}', [CollectionController::class, 'removePost'])->name('collections.posts.destroy');
    Route::get('/collections/{postCollection}/export', [CollectionExportController::class, 'create'])->name('collections.export.create');
    Route::post('/collections/{postCollection}/export', [CollectionExportController::class, 'store'])->name('collections.export.store');
    Route::get('/posts/{post}', [PostDetailController::class, 'show'])->name('posts.show');
    Route::put('/posts/{post}/annotation', [PostDetailController::class, 'annotate'])->name('posts.annotation.update');
    Route::post('/imports/chunk', [ImportUploadController::class, 'store'])->name('imports.chunk');
});
