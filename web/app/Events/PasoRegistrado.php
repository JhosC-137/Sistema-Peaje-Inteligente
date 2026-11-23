<?php

namespace App\Events;

use App\Models\Paso;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PasoRegistrado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $datos;

    public function __construct(Paso $paso)
    {
        $paso->load('caseta');
        $this->datos = [
            'id' => $paso->id,
            'placa' => $paso->placa_normalizada ?? 'ILEGIBLE',
            'categoria' => $paso->categoria_detectada,
            'ejes' => $paso->ejes_detectados,
            'pasajeros' => $paso->pasajeros,
            'monto' => $paso->monto,
            'estado' => $paso->estado,
            'caseta' => $paso->caseta->nombre ?? 'Caseta 1',
            'imagen_url' => $paso->imagen_url,
            'hora' => $paso->created_at->format('H:i:s'),
        ];
    }

    public function broadcastOn(): array
    {
        return [new Channel('peaje-channel')];
    }

    public function broadcastAs(): string
    {
        return 'paso.registrado';
    }
}