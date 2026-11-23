<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado | Peaje Inteligente</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-slate-900 border border-red-900/60 rounded-2xl p-8 text-center shadow-2xl">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-red-500/10 text-red-400 border border-red-500/30 mb-4">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold text-red-400">Acceso No Autorizado (403)</h1>
        <p class="text-slate-300 text-sm mt-3">
            Hola <span class="font-semibold">{{ $nombre ?? 'Usuario' }}</span>, la cuenta:
        </p>
        <div class="my-3 py-2 px-3 rounded-lg bg-slate-950 border border-slate-800 text-amber-400 font-mono text-sm">
            {{ $email }}
        </div>
        <p class="text-slate-400 text-xs mb-6">
            No se encuentra en la lista blanca de operadores o administradores activos. Solicita tu alta a un administrador del sistema.
        </p>
        <a href="{{ route('login') }}" class="inline-block bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium py-2.5 px-5 rounded-xl transition">
            Volver al inicio
        </a>
    </div>
</body>
</html>