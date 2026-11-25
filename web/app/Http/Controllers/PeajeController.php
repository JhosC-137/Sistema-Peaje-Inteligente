<?php

namespace App\Http\Controllers;

use App\Events\PasoRegistrado;
use App\Models\Alerta;
use App\Models\Caseta;
use App\Models\Paso;
use App\Models\Tarifa;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PeajeController extends Controller
{
    public function procesar(Request $request)
    {
        $request->validate([
            'caseta_id' => 'required|exists:casetas,id',
            'imagen' => 'nullable|image|max:10240',
            'video' => 'nullable|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm,video/3gpp,application/octet-stream|max:51200',
        ]);

        $imagenUrl = null;
        $imagenBase64 = null;
        $path = null;
        if ($request->hasFile('imagen')) {
            $rawBytes = file_get_contents($request->file('imagen')->getRealPath());
            $imagenBase64 = base64_encode($rawBytes);
            $path = $request->file('imagen')->store('evidencias', 'public');
            $imagenUrl = asset('storage/' . $path);
        }

        // 1. Llamar al servidor de inferencia (video o imagen) o usar datos simulados
        $inferenceUrl = config('services.inference_url') ?? env('INFERENCE_URL');
        if (!empty($inferenceUrl) && $request->hasFile('video')) {
            $videoFile = $request->file('video');
            $response = Http::timeout(90)
                ->attach('video', file_get_contents($videoFile->getRealPath()), $videoFile->getClientOriginalName() ?: 'clip.mp4')
                ->post(rtrim($inferenceUrl, '/') . '/inferir-video');

            if (!$response->ok()) {
                return response()->json(['error' => 'Fallo al procesar el video en el motor IA'], 502);
            }
            $ia = $response->json();

            if (!empty($ia['imagen_anotada_b64'])) {
                $binario = base64_decode($ia['imagen_anotada_b64'], true);
                if ($binario !== false) {
                    $imagenBase64 = $ia['imagen_anotada_b64'];
                    $nombreArchivo = 'evidencias/vid_' . uniqid() . '.jpg';
                    Storage::disk('public')->put($nombreArchivo, $binario);
                    $imagenUrl = asset('storage/' . $nombreArchivo);
                }
            }
        } elseif (!empty($inferenceUrl) && $request->hasFile('imagen')) {
            $response = Http::timeout(30)
                ->attach('imagen', file_get_contents($request->file('imagen')->getRealPath()), 'frame.jpg')
                ->post(rtrim($inferenceUrl, '/') . '/inferir');

            if (!$response->ok()) {
                return response()->json(['error' => 'Fallo en el motor de visión IA'], 502);
            }
            $ia = $response->json();

            if (!empty($ia['imagen_anotada_b64'])) {
                $binario = base64_decode($ia['imagen_anotada_b64'], true);
                if ($binario !== false) {
                    $imagenBase64 = $ia['imagen_anotada_b64'];
                    if ($path) {
                        Storage::disk('public')->put($path, $binario);
                    }
                }
            }
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
        $placaRaw = $ia['placa_leida'] ?? $ia['placa_raw'] ?? '';
        $placaInput = $ia['placa_normalizada'] ?? $placaRaw;
        $placaNorm = strtoupper($placaInput) === 'ILEGIBLE'
            ? ''
            : preg_replace('/[^A-Z0-9]/', '', strtoupper($placaInput));

        $categoria = $ia['categoria_detectada'] ?? $ia['categoria'] ?? 'auto';
        $ejes = max(2, (int) ($ia['ejes_detectados'] ?? $ia['ejes'] ?? 2));
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
            'imagen_base64' => $imagenBase64,
            'estado' => $estado,
        ]);

        if ($imagenBase64 !== null) {
            $paso->update(['imagen_url' => url("/evidencias/{$paso->id}")]);
        }

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
            'imagen_anotada_b64' => $ia['imagen_anotada_b64'] ?? null,
            'deteccion' => [
                'detecciones' => $ia['detecciones'] ?? [[
                    'categoria' => $categoria,
                    'ejes' => $ejes,
                    'pasajeros' => $pasajeros,
                    'placa' => $placaNorm ?: 'ILEGIBLE',
                    'confirmado' => true,
                    'bbox_vehiculo' => $ia['bbox_vehiculo'] ?? null,
                    'bbox_placa' => $ia['bbox_placa'] ?? null,
                    'llantas_puntos' => $ia['llantas_puntos'] ?? [],
                    'llantas_boxes' => $ia['llantas_boxes'] ?? [],
                    'mascaras_pasajeros' => $ia['mascaras_pasajeros'] ?? [],
                ]],
            ],
        ]);
    }

    public function resumenCaseta(int $id)
    {
        $caseta = Caseta::findOrFail($id);
        $pasosHoy = Paso::where('caseta_id', $caseta->id)->whereDate('created_at', today());

        return response()->json([
            'caseta' => $caseta,
            'fecha' => today()->toDateString(),
            'total_pasos_hoy' => (clone $pasosHoy)->count(),
            'pasos_autorizados_hoy' => (clone $pasosHoy)->where('estado', 'autorizado')->count(),
            'recaudacion_hoy' => (float) (clone $pasosHoy)->where('estado', 'autorizado')->sum('monto'),
            'alertas_hoy' => (clone $pasosHoy)->where('estado', '!=', 'autorizado')->count(),
        ]);
    }

    public function verEvidencia(Paso $paso)
    {
        if (!empty($paso->imagen_base64)) {
            $binario = base64_decode($paso->imagen_base64, true);
            if ($binario !== false) {
                return response($binario, 200)->header('Content-Type', 'image/jpeg');
            }
        }
        abort(404);
    }

    public function verArchivoStorage(string $archivo)
    {
        $nombre = basename($archivo);
        $ruta = storage_path('app/public/evidencias/' . $nombre);
        if (is_file($ruta)) {
            return response()->file($ruta);
        }
        $paso = Paso::where('imagen_url', 'like', "%{$nombre}%")->whereNotNull('imagen_base64')->first();
        if ($paso) {
            $binario = base64_decode($paso->imagen_base64, true);
            if ($binario !== false) {
                return response($binario, 200)->header('Content-Type', 'image/jpeg');
            }
        }
        abort(404);
    }
}