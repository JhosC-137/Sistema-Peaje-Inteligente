<?php

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PeajeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Compatibilidad con rutas existentes de la app móvil
Route::post('/auth/google', [AuthController::class, 'loginMovilGoogle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/peaje/procesar', [PeajeController::class, 'procesar']);
});

// API REST v1 (Sección 4.5)
Route::prefix('v1')->group(function () {
    Route::post('/auth/google', [AuthController::class, 'loginMovilGoogle']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/pasos/frame', [PeajeController::class, 'procesar']);
        Route::post('/placas/consultar', [AgenteController::class, 'consultarPlacaTexto']);
        Route::post('/placas/consultar-imagen', [AgenteController::class, 'consultarPlacaImagen']);
        Route::get('/placas/{placa}/historial', [AgenteController::class, 'historialPlaca']);
        Route::get('/casetas/{id}/resumen', [PeajeController::class, 'resumenCaseta']);
        Route::post('/agente/chat', [AgenteController::class, 'consultar']);
        Route::get('/estado', [DashboardController::class, 'estadoInferencia']);
    });
});
