<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Paso;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AgenteController extends Controller
{
    public function consultar(Request $request)
    {
        if ($request->hasFile('imagen')) {
            return $this->consultarConImagenInterno($request);
        }

        $pregunta = trim((string) $request->input('pregunta', $request->input('mensaje', '')));
        if ($pregunta === '') {
            return response()->json([
                'respuesta' => 'Escribe una consulta sobre placas, recaudación, pasos o alertas, o adjunta una foto de placa.',
            ]);
        }

        $preguntaLower = mb_strtolower($pregunta);

        // 1. Detectar si consulta una placa específica (soporta guiones o espacios, ej. 1234-ABC)
        if (preg_match('/\b([0-9]{3,4}[-\s]?[a-z]{2,3})\b/i', $pregunta, $match)) {
            $placaBuscada = $this->normalizarPlaca($match[1]);
            $analisis = $this->analizarPlaca($placaBuscada);
            return response()->json($analisis);
        }

        // 2. Consultas de recaudación / ingresos
        if (str_contains($preguntaLower, 'recaud') || str_contains($preguntaLower, 'dinero') || str_contains($preguntaLower, 'monto') || str_contains($preguntaLower, 'ingreso')) {
            $hoy = Paso::whereDate('created_at', today())->where('estado', 'autorizado')->sum('monto');
            $total = Paso::where('estado', 'autorizado')->sum('monto');
            return response()->json([
                'respuesta' => "💰 **Recaudación:** Hoy se han recaudado **Bs. " . number_format($hoy, 2) . "** en pasos autorizados (Total histórico: **Bs. " . number_format($total, 2) . "**).",
            ]);
        }

        // 3. Consultas de alertas / sin saldo / no registradas
        if (str_contains($preguntaLower, 'alerta') || str_contains($preguntaLower, 'saldo') || str_contains($preguntaLower, 'rechaz') || str_contains($preguntaLower, 'registrad')) {
            $pendientes = Alerta::with('paso')->where('atendida', false)->latest()->take(5)->get();
            if ($pendientes->isEmpty()) {
                return response()->json(['respuesta' => '✅ No hay alertas pendientes de atención en este momento.']);
            }
            $lista = $pendientes->map(fn($a) => "• Placa **" . ($a->paso->placa_normalizada ?? 'ILEGIBLE') . "** ({$a->tipo})")->implode("\n");
            return response()->json([
                'respuesta' => "🚨 Hay **{$pendientes->count()} alertas activas**:\n{$lista}",
            ]);
        }

        // 4. Consultas de conteo de vehículos / tráfico
        if (str_contains($preguntaLower, 'cuanto') || str_contains($preguntaLower, 'cuánto') || str_contains($preguntaLower, 'paso') || str_contains($preguntaLower, 'vehiculo') || str_contains($preguntaLower, 'vehículo') || str_contains($preguntaLower, 'trafico')) {
            $totalHoy = Paso::whereDate('created_at', today())->count();
            $porCat = Paso::selectRaw('categoria_detectada, count(*) as total')->groupBy('categoria_detectada')->pluck('total', 'categoria_detectada');
            $desglose = $porCat->isEmpty() ? 'Sin registros aún.' : $porCat->map(fn($c, $k) => "{$k}: {$c}")->implode(', ');
            return response()->json([
                'respuesta' => "🚗 **Tráfico:** Hoy se registraron **{$totalHoy} pasos**. Desglose histórico por categoría: {$desglose}.",
            ]);
        }

        return response()->json([
            'respuesta' => "Puedo ayudarte con consultas como:\n• *¿Cuál es el estado de la placa 1234ABC?*\n• *Subir la foto de una placa*\n• *¿Cuánto se recaudó hoy?*\n• *¿Qué alertas pendientes hay?*",
        ]);
    }

    public function consultarPlacaTexto(Request $request)
    {
        $request->validate(['placa' => 'required|string|max:20']);
        $placa = $this->normalizarPlaca($request->input('placa'));
        if ($placa === '') {
            return response()->json(['error' => 'Formato de placa inválido'], 422);
        }

        return response()->json($this->analizarPlaca($placa));
    }

    public function consultarPlacaImagen(Request $request)
    {
        $request->validate(['imagen' => 'required|image|max:5120']);
        return $this->consultarConImagenInterno($request);
    }

    public function historialPlaca(string $placa)
    {
        $placaNorm = $this->normalizarPlaca($placa);
        $vehiculo = Vehiculo::where('placa', $placaNorm)->first();
        $pasos = Paso::with('caseta')
            ->where('placa_normalizada', $placaNorm)
            ->latest()
            ->take(50)
            ->get();

        return response()->json([
            'placa' => $placaNorm,
            'vehiculo' => $vehiculo,
            'total_pasos' => $pasos->count(),
            'pasos' => $pasos,
        ]);
    }

    private function consultarConImagenInterno(Request $request)
    {
        $request->validate(['imagen' => 'required|image|max:5120']);

        $inferenceUrl = config('services.inference_url') ?? env('INFERENCE_URL');
        if (empty($inferenceUrl)) {
            return response()->json([
                'placa' => null,
                'estado_codigo' => 'inferencia_no_configurada',
                'respuesta' => '⚠️ El servidor de inferencia IA no está configurado (INFERENCE_URL).',
            ], 503);
        }

        $bytes = file_get_contents($request->file('imagen')->getRealPath());
        $base = rtrim($inferenceUrl, '/');

        $response = Http::timeout(30)->attach('imagen', $bytes, 'consulta.jpg')->post("{$base}/ocr");
        if ($response->status() === 404) {
            $response = Http::timeout(30)->attach('imagen', $bytes, 'consulta.jpg')->post("{$base}/inferir");
        }

        if (!$response->ok()) {
            return response()->json([
                'error' => 'Fallo al consultar el motor OCR de visión IA',
                'respuesta' => '⚠️ No se pudo conectar con el servicio de reconocimiento de placas.',
            ], 502);
        }

        $ia = $response->json();
        $placaRaw = $ia['placa_normalizada'] ?? $ia['placa_leida'] ?? '';
        $placaNorm = $this->normalizarPlaca($placaRaw);
        $confOcr = (float) ($ia['confianza_ocr'] ?? 0);

        if ($placaNorm === '' || strtoupper($placaNorm) === 'ILEGIBLE') {
            return response()->json([
                'placa' => null,
                'confianza_ocr' => $confOcr,
                'estado_codigo' => 'placa_ilegible',
                'respuesta' => '📷 No se detectó una placa legible en la imagen. Por favor intenta con otra foto más nítida y centrada en la placa.',
            ]);
        }

        $analisis = $this->analizarPlaca($placaNorm);
        $confPct = number_format($confOcr * 100, 1);
        $analisis['confianza_ocr'] = $confOcr;
        $analisis['respuesta'] = "📷 **OCR detectó:** `{$placaNorm}` (confianza {$confPct}%)\n" . $analisis['respuesta'];

        return response()->json($analisis);
    }

    private function normalizarPlaca(string $texto): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($texto))) ?? '';
    }

    private function analizarPlaca(string $placa): array
    {
        $vehiculo = Vehiculo::where('placa', $placa)->first();
        $ultimoPaso = Paso::with('caseta')->where('placa_normalizada', $placa)->latest()->first();

        // Estados 1 y 2: Placa registrada en el padrón
        if ($vehiculo) {
            $estadoSaldo = !$vehiculo->activo
                ? "⛔ **Cuenta inactiva.**"
                : ($vehiculo->saldo >= 5
                    ? "✅ **Con saldo disponible:** Bs. " . number_format($vehiculo->saldo, 2) . "."
                    : "⚠️ **Saldo insuficiente:** Bs. " . number_format($vehiculo->saldo, 2) . " (requiere recarga).");

            if ($ultimoPaso) {
                $casetaNombre = $ultimoPaso->caseta?->nombre ?? "Caseta #{$ultimoPaso->caseta_id}";
                $fecha = $ultimoPaso->created_at->format('d/m/Y H:i');
                $monto = number_format((float) $ultimoPaso->monto, 2);

                return [
                    'placa' => $placa,
                    'estado_codigo' => 'registrada_con_paso',
                    'estado_titulo' => 'Registrada y con paso',
                    'vehiculo' => $vehiculo,
                    'ultimo_paso' => $ultimoPaso,
                    'sugerencias' => [],
                    'respuesta' => "🚘 **Registrada y con paso** | Placa **{$placa}**\n"
                        . "• Propietario: **{$vehiculo->propietario}** ({$vehiculo->categoria}, {$vehiculo->ejes} ejes)\n"
                        . "• {$estadoSaldo}\n"
                        . "• Último paso: **{$fecha}** en **{$casetaNombre}** | Categoría detectada: **{$ultimoPaso->categoria_detectada}** | Monto: **Bs. {$monto}** ({$ultimoPaso->estado}).",
                ];
            }

            return [
                'placa' => $placa,
                'estado_codigo' => 'registrada_sin_paso',
                'estado_titulo' => 'Registrada sin paso',
                'vehiculo' => $vehiculo,
                'ultimo_paso' => null,
                'sugerencias' => [],
                'respuesta' => "🚘 **Registrada sin paso** | Placa **{$placa}**\n"
                    . "• Propietario: **{$vehiculo->propietario}** ({$vehiculo->categoria}, {$vehiculo->ejes} ejes)\n"
                    . "• {$estadoSaldo}\n"
                    . "• Nunca ha registrado un paso por caseta.",
            ];
        }

        // Buscar coincidencias cercanas por errores típicos de OCR (O↔0, I↔1, B↔8, S↔5) y Levenshtein <= 2
        $sugerencias = $this->buscarCoincidenciasCercanas($placa);
        $textoSugerencia = $sugerencias->isNotEmpty()
            ? "\n💡 ¿Quisiste decir: **" . $sugerencias->implode(', ') . "**?"
            : '';

        // Estado 3: Identificada por cámara pero no registrada (posible evasión)
        if ($ultimoPaso) {
            $casetaNombre = $ultimoPaso->caseta?->nombre ?? "Caseta #{$ultimoPaso->caseta_id}";
            $fecha = $ultimoPaso->created_at->format('d/m/Y H:i');
            return [
                'placa' => $placa,
                'estado_codigo' => 'identificada_no_registrada',
                'estado_titulo' => 'Identificada no registrada',
                'vehiculo' => null,
                'ultimo_paso' => $ultimoPaso,
                'sugerencias' => $sugerencias->values()->all(),
                'respuesta' => "🚨 **Identificada no registrada (posible evasión)** | Placa **{$placa}**\n"
                    . "• No figura en el padrón de vehículos, pero fue captada el **{$fecha}** en **{$casetaNombre}** ({$ultimoPaso->categoria_detectada}, {$ultimoPaso->ejes_detectados} ejes).{$textoSugerencia}",
            ];
        }

        // Estado 4: No existe ningún registro
        return [
            'placa' => $placa,
            'estado_codigo' => 'no_existe',
            'estado_titulo' => 'No existe',
            'vehiculo' => null,
            'ultimo_paso' => null,
            'sugerencias' => $sugerencias->values()->all(),
            'respuesta' => "❌ **No existe** | No hay ningún registro en el padrón ni en el historial de cámaras para la placa **{$placa}**.{$textoSugerencia}",
        ];
    }

    private function buscarCoincidenciasCercanas(string $placa)
    {
        $normalizarConfusion = static function (string $s): string {
            return strtr($s, ['O' => '0', 'Q' => '0', 'I' => '1', 'L' => '1', 'B' => '8', 'S' => '5', 'Z' => '2']);
        };
        $canon = $normalizarConfusion($placa);

        return Vehiculo::all()
            ->filter(function ($v) use ($placa, $canon, $normalizarConfusion) {
                return levenshtein($placa, $v->placa) <= 2 || $normalizarConfusion($v->placa) === $canon;
            })
            ->pluck('placa')
            ->take(4);
    }
}
