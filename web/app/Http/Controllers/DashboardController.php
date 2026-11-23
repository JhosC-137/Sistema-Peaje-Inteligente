<?php

namespace App\Http\Controllers;

use App\Models\Alerta;
use App\Models\Caseta;
use App\Models\Paso;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $pasos = Paso::with('caseta')->latest()->take(25)->get();
        $alertas = Alerta::with('paso.caseta')->where('atendida', false)->latest()->get();
        $casetas = Caseta::all();
        $vehiculos = Vehiculo::orderBy('placa')->get();
        $usuarios = Auth::user()->rol === 'admin' ? Usuario::all() : collect();

        $stats = [
            'total_hoy' => Paso::whereDate('created_at', today())->count(),
            'recaudado_hoy' => Paso::whereDate('created_at', today())->where('estado', 'autorizado')->sum('monto'),
            'alertas_pendientes' => $alertas->count(),
        ];

        return view('dashboard', compact('pasos', 'alertas', 'casetas', 'vehiculos', 'usuarios', 'stats'));
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