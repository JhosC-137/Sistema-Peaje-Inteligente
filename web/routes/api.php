<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PeajeController;

Route::post('/auth/google', [AuthController::class, 'loginMovilGoogle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/peaje/procesar', [PeajeController::class, 'procesar']);
});