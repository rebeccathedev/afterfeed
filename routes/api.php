<?php

use App\Http\Controllers\Api\ArchiveApiController;
use App\Http\Controllers\Api\McpController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth.token', 'app.settings', 'privacy'])->name('api.v1.')->group(function (): void {
    Route::get('/status', [ArchiveApiController::class, 'status'])->name('status');
    Route::get('/accounts', [ArchiveApiController::class, 'accounts'])->name('accounts');
    Route::get('/posts', [ArchiveApiController::class, 'posts'])->name('posts.index');
    Route::get('/posts/{post}', [ArchiveApiController::class, 'post'])->name('posts.show');
    Route::get('/statistics', [ArchiveApiController::class, 'statistics'])->name('statistics');
});

Route::match(['get', 'post', 'delete'], '/mcp', McpController::class)->middleware(['auth.token', 'app.settings', 'privacy', 'throttle:120,1'])->name('mcp');
