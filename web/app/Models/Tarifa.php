<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tarifa extends Model
{
    protected $table = 'tarifas';
    protected $fillable = ['categoria', 'ejes', 'monto'];
    protected $casts = ['monto' => 'float'];
}