<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caseta extends Model
{
    protected $table = 'casetas';
    protected $fillable = ['nombre', 'ubicacion'];
}