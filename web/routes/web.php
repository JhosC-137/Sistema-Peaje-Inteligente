<?php

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PeajeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('google.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/estado-inferencia', [DashboardController::class, 'estadoInferencia'])->name('estado.inferencia');
    Route::post('/alertas/{alerta}/atender', [DashboardController::class, 'atenderAlerta'])->name('alertas.atender');
    Route::post('/vehiculos', [DashboardController::class, 'guardarVehiculo'])->name('vehiculos.guardar');
    Route::delete('/vehiculos/{vehiculo}', [DashboardController::class, 'eliminarVehiculo'])->name('vehiculos.eliminar');
    Route::post('/tarifas', [DashboardController::class, 'guardarTarifa'])->name('tarifas.guardar');
    Route::post('/casetas', [DashboardController::class, 'guardarCaseta'])->name('casetas.guardar');
    Route::post('/usuarios', [DashboardController::class, 'guardarUsuario'])->name('usuarios.guardar');
    Route::post('/agente/consultar', [AgenteController::class, 'consultar'])->name('agente.consultar');
    Route::post('/peaje/simular', [PeajeController::class, 'procesar'])->name('peaje.simular');
});
