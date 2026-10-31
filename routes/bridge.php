<?php

use Atannex\Bridge\Http\Controllers\CallController;
use Atannex\Bridge\Http\Controllers\FileController;
use Atannex\Bridge\Http\Controllers\HealthController;
use Atannex\Bridge\Http\Controllers\TokenController;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;

$config = config('bridge.routes');

Route::group(array_filter([
    'prefix' => $config['prefix'],
    'middleware' => $config['middleware'],
    'domain' => $config['domain'] ?? null,
    'as' => 'bridge.',
]), function () {
    Route::post('call/{operation}', CallController::class)
        ->where('operation', '[a-z][a-z0-9_.]{0,119}')
        ->name('call');

    Route::get('health', HealthController::class)->name('health');

    Route::get('files/{token}', FileController::class)
        ->middleware(ValidateSignature::class)
        ->name('files');

    if (config('bridge.auth.user_endpoint')) {
        Route::get('auth/user', [TokenController::class, 'user'])->name('auth.user');
    }

    if (config('bridge.auth.tokens.enabled')) {
        Route::post('auth/token', [TokenController::class, 'store'])->name('auth.token');
        Route::post('auth/two-factor', [TokenController::class, 'twoFactor'])->name('auth.two-factor');
        Route::post('auth/refresh', [TokenController::class, 'refresh'])->name('auth.refresh');
        Route::post('auth/logout', [TokenController::class, 'destroy'])->name('auth.logout');
    }
});
