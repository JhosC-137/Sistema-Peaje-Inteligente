<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    protected $table = 'vehiculos';
    protected $fillable = ['placa', 'propietario', 'categoria', 'ejes', 'saldo', 'activo'];
    protected $casts = ['activo' => 'boolean', 'saldo' => 'float'];
}