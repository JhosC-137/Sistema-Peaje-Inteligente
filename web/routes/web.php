<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PeajeController;


Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('google.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/alertas/{alerta}/atender', [DashboardController::class, 'atenderAlerta'])->name('alertas.atender');
    Route::post('/vehiculos', [DashboardController::class, 'guardarVehiculo'])->name('vehiculos.guardar');
    Route::post('/usuarios', [DashboardController::class, 'guardarUsuario'])->name('usuarios.guardar');
    Route::post('/agente/consultar', [AgenteController::class, 'consultar'])->name('agente.consultar');
    Route::post('/peaje/simular', [PeajeController::class, 'procesar'])->name('peaje.simular');
});