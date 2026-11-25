<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Caseta;
use App\Models\Paso;
use App\Models\Tarifa;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    public function index()
    {
        $pasos = Paso::with('caseta')->latest()->take(25)->get();
        $alertas = Alerta::with('paso.caseta')->where('atendida', false)->latest()->get();
        $casetas = Caseta::orderBy('id')->get();
        $tarifas = Tarifa::orderBy('categoria')->orderBy('ejes')->get();
        $vehiculos = Vehiculo::orderBy('placa')->get();
        $usuarios = Auth::user()->rol === 'admin' ? Usuario::all() : collect();

        $stats = [
            'total_hoy' => Paso::whereDate('created_at', today())->count(),
            'recaudado_hoy' => Paso::whereDate('created_at', today())->where('estado', 'autorizado')->sum('monto'),
            'alertas_pendientes' => $alertas->count(),
        ];

        return view('dashboard', compact('pasos', 'alertas', 'casetas', 'tarifas', 'vehiculos', 'usuarios', 'stats'));
    }

    public function estadoInferencia()
    {
        $inferenceUrl = config('services.inference_url') ?? env('INFERENCE_URL');
        if (empty($inferenceUrl)) {
            return response()->json([
                'online' => false,
                'url_configurada' => false,
                'mensaje' => 'INFERENCE_URL no configurada',
            ]);
        }

        try {
            $res = Http::timeout(5)->get(rtrim($inferenceUrl, '/') . '/health');
            if ($res->ok()) {
                return response()->json([
                    'online' => true,
                    'url_configurada' => true,
                    'inference_url' => rtrim($inferenceUrl, '/'),
                    'detalle' => $res->json(),
                ]);
            }
            return response()->json([
                'online' => false,
                'url_configurada' => true,
                'inference_url' => rtrim($inferenceUrl, '/'),
                'mensaje' => 'HTTP ' . $res->status(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'online' => false,
                'url_configurada' => true,
                'inference_url' => rtrim($inferenceUrl, '/'),
                'mensaje' => 'Sin conexión con el servidor IA',
            ]);
        }
    }

    public function atenderAlerta(Alerta $alerta)
    {
        $alerta->update(['atendida' => true]);
        return back()->with('ok', 'Alerta marcada como atendida.');
    }

    public function guardarVehiculo(Request $request)
    {
        abort_if(Auth::user()->rol !== 'admin', 403);
        $data = $request->validate([
            'placa' => 'required|string|max:15',
            'propietario' => 'required|string|max:120',
            'categoria' => 'required|in:moto,auto,minibus,camion,bus',
            'ejes' => 'required|integer|min:2|max:6',
            'saldo' => 'required|numeric|min:0',
        ]);
        $data['placa'] = preg_replace('/[^A-Z0-9]/', '', strtoupper($data['placa']));

        Vehiculo::updateOrCreate(['placa' => $data['placa']], array_merge($data, ['activo' => true]));
        return back()->with('ok', "Vehículo {$data['placa']} guardado/recargado correctamente.");
    }

    public function eliminarVehiculo(Vehiculo $vehiculo)
    {
        abort_if(Auth::user()->rol !== 'admin', 403);
        $placa = $vehiculo->placa;
        $vehiculo->delete();
        return back()->with('ok', "Vehículo {$placa} eliminado.");
    }

    public function guardarTarifa(Request $request)
    {
        abort_if(Auth::user()->rol !== 'admin', 403);
        $data = $request->validate([
            'categoria' => 'required|in:moto,auto,minibus,camion,bus',
            'ejes' => 'required|integer|min:2|max:6',
            'monto' => 'required|numeric|min:0',
        ]);

        Tarifa::updateOrCreate(
            ['categoria' => $data['categoria'], 'ejes' => $data['ejes']],
            ['monto' => $data['monto']]
        );
        return back()->with('ok', "Tarifa actualizada para {$data['categoria']} ({$data['ejes']} ejes).");
    }

    public function guardarCaseta(Request $request)
    {
        abort_if(Auth::user()->rol !== 'admin', 403);
        $data = $request->validate([
            'nombre' => 'required|string|max:120',
            'ubicacion' => 'required|string|max:150',
        ]);

        Caseta::create($data);
        return back()->with('ok', "Caseta '{$data['nombre']}' registrada.");
    }

    public function guardarUsuario(Request $request)
    {
        abort_if(Auth::user()->rol !== 'admin', 403);
        $data = $request->validate([
            'email_google' => 'required|email',
            'nombre' => 'required|string|max:120',
            'rol' => 'required|in:admin,operador',
        ]);

        Usuario::updateOrCreate(
            ['email_google' => strtolower(trim($data['email_google']))],
            ['nombre' => $data['nombre'], 'rol' => $data['rol'], 'activo' => true]
        );
        return back()->with('ok', 'Usuario autorizado actualizado.');
    }
}
