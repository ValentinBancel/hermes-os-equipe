<?php

use App\Http\Controllers\Pont\InstallateurController;
use App\Http\Controllers\Pont\PontController;
use Illuminate\Support\Facades\Route;

/*
 * Pont distant (L4, R4, R12) : groupe sans session ni CSRF, hors liste d'IP. L'appairage est protégé par le code
 * à usage unique et un throttle ; tout le reste par la signature HMAC de l'instance.
 */
Route::prefix('pont')->group(function () {
    Route::get('installer.sh', [InstallateurController::class, 'installeur'])->name('pont.installeur');
    Route::get('fichiers/{module}', [InstallateurController::class, 'fichier'])
        ->where('module', 'hermes_os_[a-z_]+\.py')->name('pont.fichier');

    Route::post('v1/appairage', [PontController::class, 'appairage'])->middleware('throttle:pont-appairage');

    Route::middleware('pont.signe')->group(function () {
        Route::post('v1/releves', [PontController::class, 'releves']);
        Route::get('v1/actions', [PontController::class, 'actions']);
        Route::post('v1/actions/{id}/resultat', [PontController::class, 'resultat'])->whereUuid('id');
    });
});
