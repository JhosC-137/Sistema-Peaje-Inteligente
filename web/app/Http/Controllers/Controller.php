<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) return redirect()->route('dashboard');
        return view('login');
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->with('error', 'Error al autenticar con Google: ' . $e->getMessage());
        }

        $usuario = Usuario::where('email_google', $googleUser->getEmail())->first();

        if (!$usuario || !$usuario->activo) {
            return response()->view('acceso_denegado', [
                'email' => $googleUser->getEmail(),
                'nombre' => $googleUser->getName()
            ], 403);
        }

        Auth::login($usuario, true);
        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    // Endpoint para Flutter (verifica idToken de Google y emite token Sanctum)
    public function loginMovilGoogle(Request $request)
    {
        $request->validate(['id_token' => 'required|string']);

        $res = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $request->id_token
        ]);

        if (!$res->ok()) {
            return response()->json(['error' => 'Token de Google inválido'], 401);
        }

        $email = $res->json('email');
        $usuario = Usuario::where('email_google', $email)->where('activo', true)->first();

        if (!$usuario) {
            return response()->json(['error' => 'Cuenta no autorizada en el sistema'], 403);
        }

        $token = $usuario->createToken('flutter-peaje')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => $usuario
        ]);
    }
}