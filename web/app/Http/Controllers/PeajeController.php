<?php

namespace App\Http\Controllers;

use App\Events\PasoRegistrado;
use App\Models\Alerta;
use App\Models\Paso;
use App\Models\Tarifa;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PeajeController extends Controller
{
    public function procesar(Request $request)
    {
        $request->validate([
            'caseta_id' => 'required|exists:casetas,id',
            'imagen' => 'nullable|image|max:5120',
        ]);

        $imagenUrl = null;
        if ($request->hasFile('imagen')) {
            $path = $request->file('imagen')->store('evidencias', 'public');
            $imagenUrl = asset('storage/' . $path);
        }

        // 1. Llamar al servidor de inferencia RunPod (o usar datos simulados si aún no está activo)
        $inferenceUrl = env('INFERENCE_URL');
        if (!empty($inferenceUrl) && $request->hasFile('imagen')) {
            $response = Http::timeout(25)
                ->attach('file', file_get_contents($request->file('imagen')->getRealPath()), 'frame.jpg')
                ->post(rtrim($inferenceUrl, '/') . '/inferir');

            if (!$response->ok()) {
                return response()->json(['error' => 'Fallo en el motor de visión IA'], 502);
            }
            $ia = $response->json();
        } else {
            // Modo prueba desde escritorio sin GPU
            $ia = [
                'placa_raw' => $request->input('placa_simulada', '1234ABC'),
                'confianza_ocr' => 0.96,
                'categoria' => $request->input('categoria_simulada', 'auto'),
                'ejes' => (int) $request->input('ejes_simulados', 2),
                'pasajeros' => (int) $request->input('pasajeros_simulados', 2),
            ];
        }

        // 2. Normalizar placa (solo alfanumérico en mayúsculas)
        $placaRaw = $ia['placa_raw'] ?? '';
        $placaNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper($placaRaw));

        $categoria = $ia['categoria'] ?? 'auto';
        $ejes = max(2, (int) ($ia['ejes'] ?? 2));
        $pasajeros = (int) ($ia['pasajeros'] ?? 0);

        // 3. Buscar tarifa correspondiente
        $tarifa = Tarifa::where('categoria', $categoria)->where('ejes', $ejes)->first()
            ?? Tarifa::where('categoria', $categoria)->first();
        $monto = $tarifa ? $tarifa->monto : 5.00;

        // 4. Evaluar vehículo y saldo
        $vehiculo = !empty($placaNorm) ? Vehiculo::where('placa', $placaNorm)->first() : null;
        $estado = 'autorizado';
        $tipoAlerta = null;

        if (!$vehiculo || !$vehiculo->activo) {
            $estado = 'no_registrada';
            $tipoAlerta = 'placa_no_registrada';
        } elseif ($vehiculo->saldo < $monto) {
            $estado = 'sin_saldo';
            $tipoAlerta = 'sin_saldo';
        } else {
            $vehiculo->decrement('saldo', $monto);
        }

        // 5. Registrar paso y alerta si corresponde
        $paso = Paso::create([
            'placa_leida' => $placaRaw,
            'placa_normalizada' => $placaNorm ?: null,
            'confianza_ocr' => $ia['confianza_ocr'] ?? 0,
            'categoria_detectada' => $categoria,
            'ejes_detectados' => $ejes,
            'pasajeros' => $pasajeros,
            'monto' => $monto,
            'caseta_id' => $request->caseta_id,
            'usuario_id' => $request->user()->id,
            'imagen_url' => $imagenUrl,
            'estado' => $estado,
        ]);

        if ($tipoAlerta) {
            Alerta::create(['paso_id' => $paso->id, 'tipo' => $tipoAlerta]);
        }

        // 6. Emitir evento en tiempo real vía Pusher
        event(new PasoRegistrado($paso));

        return response()->json([
            'mensaje' => 'Paso procesado',
            'estado' => $estado,
            'paso' => $paso,
            'saldo_restante' => $vehiculo ? $vehiculo->fresh()->saldo : null,
        ]);
    }
}