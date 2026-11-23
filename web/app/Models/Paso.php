<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paso extends Model
{
    protected $table = 'pasos';
    protected $fillable = [
        'placa_leida', 'placa_normalizada', 'confianza_ocr',
        'categoria_detectada', 'ejes_detectados', 'pasajeros',
        'monto', 'caseta_id', 'usuario_id', 'imagen_url', 'estado'
    ];

    public function caseta() { return $this->belongsTo(Caseta::class); }
    public function usuario() { return $this->belongsTo(Usuario::class); }
    public function alerta() { return $this->hasOne(Alerta::class); }
}