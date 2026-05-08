<?php

use ArtisanPackUI\SecureUploads\Http\Controllers\SecureFileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Secure File Routes
|--------------------------------------------------------------------------
|
| These routes handle secure file serving via signed URLs.
|
*/

Route::middleware(['web', 'signed'])->group(function () {
    Route::get('/secure-file/{identifier}', [SecureFileController::class, 'show'])
        ->name('secure-file.show');

    Route::get('/secure-file/{identifier}/download', [SecureFileController::class, 'download'])
        ->name('secure-file.download');
});
