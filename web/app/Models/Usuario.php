<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'usuarios';
    protected $rememberTokenName = '';
    protected $fillable = ['email_google', 'nombre', 'rol', 'activo'];
    protected $casts = ['activo' => 'boolean'];
}