<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Paso;
use App\Models\Vehiculo;
use Illuminate\Http\Request;

class AgenteController extends Controller
{
    public function consultar(Request $request)
    {
        $pregunta = mb_strtolower(trim($request->input('pregunta', '')));
        if (empty($pregunta)) {
            return response()->json(['respuesta' => 'Escribe una consulta sobre placas, recaudación, pasos o alertas.']);
        }

        // 1. Detectar si consulta una placa específica (ej. 1234ABC o 4 dígitos + 3 letras)
        if (preg_match('/\b([0-9]{3,4}[a-z]{2,3})\b/i', $pregunta, $match)) {
            $placaBuscada = strtoupper($match[1]);
            return response()->json(['respuesta' => $this->analizarPlaca($placaBuscada)]);
        }

        // 2. Consultas de recaudación / ingresos
        if (str_contains($pregunta, 'recaud') || str_contains($pregunta, 'dinero') || str_contains($pregunta, 'monto') || str_contains($pregunta, 'ingreso')) {
            $hoy = Paso::whereDate('created_at', today())->where('estado', 'autorizado')->sum('monto');
            $total = Paso::where('estado', 'autorizado')->sum('monto');
            return response()->json([
                'respuesta' => "💰 **Recaudación:** Hoy se han recaudado **Bs. " . number_format($hoy, 2) . "** en pasos autorizados (Total histórico: **Bs. " . number_format($total, 2) . "**)."
            ]);
        }

        // 3. Consultas de alertas / sin saldo / no registradas
        if (str_contains($pregunta, 'alerta') || str_contains($pregunta, 'saldo') || str_contains($pregunta, 'rechaz') || str_contains($pregunta, 'registrad')) {
            $pendientes = Alerta::with('paso')->where('atendida', false)->latest()->take(5)->get();
            if ($pendientes->isEmpty()) {
                return response()->json(['respuesta' => '✅ No hay alertas pendientes de atención en este momento.']);
            }
            $lista = $pendientes->map(fn($a) => "• Placa **{$a->paso->placa_normalizada}** ({$a->tipo})")->implode("\n");
            return response()->json([
                'respuesta' => "🚨 Hay **{$pendientes->count()} alertas activas**:\n{$lista}"
            ]);
        }

        // 4. Consultas de conteo de vehículos / tráfico
        if (str_contains($pregunta, 'cuanto') || str_contains($pregunta, 'cuánto') || str_contains($pregunta, 'paso') || str_contains($pregunta, 'vehiculo') || str_contains($pregunta, 'vehículo') || str_contains($pregunta, 'trafico')) {
            $totalHoy = Paso::whereDate('created_at', today())->count();
            $porCat = Paso::selectRaw('categoria_detectada, count(*) as total')->groupBy('categoria_detectada')->pluck('total', 'categoria_detectada');
            $desglose = $porCat->isEmpty() ? 'Sin registros aún.' : $porCat->map(fn($c, $k) => "{$k}: {$c}")->implode(', ');
            return response()->json([
                'respuesta' => "🚗 **Tráfico:** Hoy se registraron **{$totalHoy} pasos**. Desglose histórico por categoría: {$desglose}."
            ]);
        }

        return response()->json([
            'respuesta' => "Puedo ayudarte con consultas como:\n• *¿Cuál es el estado de la placa 1234ABC?*\n• *¿Cuánto se recaudó hoy?*\n• *¿Qué alertas pendientes hay?*\n• *¿Cuántos vehículos pasaron hoy?*"
        ]);
    }

    private function analizarPlaca(string $placa): string
    {
        $vehiculo = Vehiculo::where('placa', $placa)->first();
        $ultimoPaso = Paso::where('placa_normalizada', $placa)->latest()->first();

        // Estado 1 y 2: Placa existe en el padrón
        if ($vehiculo) {
            if (!$vehiculo->activo) {
                return "⛔ **Estado: Inactiva.** La placa **{$placa}** ({$vehiculo->propietario}) está deshabilitada en el sistema.";
            }
            $estadoSaldo = $vehiculo->saldo >= 5
                ? "✅ **Estado: Autorizada con saldo.** Saldo actual: **Bs. " . number_format($vehiculo->saldo, 2) . "**."
                : "⚠️ **Estado: Saldo insuficiente.** Saldo actual: **Bs. " . number_format($vehiculo->saldo, 2) . "** (requiere recarga).";

            $infoPaso = $ultimoPaso
                ? " Último paso: {$ultimoPaso->created_at->format('d/m H:i')} ({$ultimoPaso->estado})."
                : " Aún no registra pasos por caseta.";

            return "🚘 **Placa {$placa}** | Propietario: **{$vehiculo->propietario}** ({$vehiculo->categoria}, {$vehiculo->ejes} ejes).\n{$estadoSaldo}{$infoPaso}";
        }

        // Estado 3 y 4: No registrada -> Buscar similitud OCR (Levenshtein <= 2)
        $sugerencias = Vehiculo::all()->filter(function ($v) use ($placa) {
            return levenshtein($placa, $v->placa) <= 2;
        })->pluck('placa');

        if ($sugerencias->isNotEmpty()) {
            return "❓ **Estado: No registrada (posible error de lectura OCR).** La placa **{$placa}** no figura en el padrón, pero se encontraron placas similares: **" . $sugerencias->implode(', ') . "**.";
        }

        return "❌ **Estado: No registrada.** La placa **{$placa}** no existe en la base de datos ni tiene coincidencias cercanas.";
    }
}