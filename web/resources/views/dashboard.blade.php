<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard | Control de Peaje Inteligente</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
    <!-- Navbar -->
    <nav class="bg-slate-900 border-b border-slate-800 px-6 py-4 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="w-3 h-3 rounded-full bg-emerald-400 animate-pulse" id="pusher-status" title="Conectado en tiempo real"></span>
            <h1 class="text-lg font-bold tracking-tight">Control de Peaje Inteligente</h1>
            <span class="text-xs px-2.5 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 uppercase">
                {{ auth()->user()->rol }}
            </span>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <span class="text-slate-400">{{ auth()->user()->nombre }} ({{ auth()->user()->email_google }})</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="bg-slate-800 hover:bg-slate-700 px-3 py-1.5 rounded-lg text-slate-200 transition">Salir</button>
            </form>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-6 space-y-6">
        @if(session('ok'))
            <div class="p-4 rounded-xl bg-emerald-950/60 border border-emerald-700 text-emerald-300 text-sm">
                {{ session('ok') }}
            </div>
        @endif

        <!-- Métricas -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-slate-400 text-xs uppercase font-semibold">Pasos Registrados Hoy</p>
                <p class="text-3xl font-bold mt-1" id="stat-total">{{ $stats['total_hoy'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-slate-400 text-xs uppercase font-semibold">Recaudación Autorizada Hoy</p>
                <p class="text-3xl font-bold text-emerald-400 mt-1" id="stat-recaudado">Bs. {{ number_format($stats['recaudado_hoy'], 2) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-slate-400 text-xs uppercase font-semibold">Alertas Activas</p>
                <p class="text-3xl font-bold text-amber-400 mt-1" id="stat-alertas">{{ $stats['alertas_pendientes'] }}</p>
            </div>
        </div>

        <!-- Grid Principal: Pasos en Vivo + Agente & Simulador -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Izquierda (2/3): Tabla en tiempo real + Alertas -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Alertas Activas -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <h2 class="font-semibold text-amber-400 mb-3 flex items-center gap-2">
                        <span>🚨 Alertas Pendientes</span>
                    </h2>
                    <div id="lista-alertas" class="space-y-2">
                        @forelse($alertas as $alerta)
                            <div class="flex items-center justify-between bg-slate-950 border border-amber-900/50 rounded-lg p-3 text-sm">
                                <div>
                                    <span class="font-mono font-bold text-amber-300">{{ $alerta->paso->placa_normalizada ?? 'ILEGIBLE' }}</span>
                                    <span class="text-slate-400 ml-2">• {{ $alerta->tipo === 'sin_saldo' ? 'Saldo insuficiente' : 'Placa no registrada' }}</span>
                                    <span class="text-slate-500 text-xs ml-2">({{ $alerta->created_at->format('H:i:s') }})</span>
                                </div>
                                <form method="POST" action="{{ route('alertas.atender', $alerta) }}">
                                    @csrf
                                    <button class="bg-amber-600 hover:bg-amber-500 text-slate-950 font-semibold px-3 py-1 rounded-lg text-xs">Atender</button>
                                </form>
                            </div>
                        @empty
                            <p class="text-slate-500 text-sm" id="sin-alertas-msg">Sin alertas pendientes.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Tabla de Pasos en Tiempo Real -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-800 flex justify-between items-center">
                        <h2 class="font-semibold">Últimos Pasos por Caseta (Tiempo Real)</h2>
                        <span class="text-xs text-slate-400">Actualización automática vía Pusher</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-950 text-slate-400 text-xs uppercase">
                                <tr>
                                    <th class="px-4 py-3">Hora</th>
                                    <th class="px-4 py-3">Placa</th>
                                    <th class="px-4 py-3">Categoría / Ejes</th>
                                    <th class="px-4 py-3">Tarifa</th>
                                    <th class="px-4 py-3">Estado</th>
                                    <th class="px-4 py-3">Evidencia</th>
                                </tr>
                            </thead>
                            <tbody id="tabla-pasos" class="divide-y divide-slate-800">
                                @foreach($pasos as $paso)
                                    @php
                                        $badge = match($paso->estado) {
                                            'autorizado' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                            'sin_saldo' => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                            default => 'bg-red-500/15 text-red-300 border-red-500/30',
                                        };
                                    @endphp
                                    <tr class="hover:bg-slate-800/40">
                                        <td class="px-4 py-3 text-slate-400">{{ $paso->created_at->format('H:i:s') }}</td>
                                        <td class="px-4 py-3 font-mono font-bold">{{ $paso->placa_normalizada ?? 'ILEGIBLE' }}</td>
                                        <td class="px-4 py-3 capitalize">{{ $paso->categoria_detectada }} ({{ $paso->ejes_detectados }} ejes)</td>
                                        <td class="px-4 py-3 font-semibold">Bs. {{ number_format($paso->monto, 2) }}</td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-0.5 rounded-full text-xs border {{ $badge }}">{{ $paso->estado }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($paso->imagen_url)
                                                <a href="{{ $paso->imagen_url }}" target="_blank" class="text-indigo-400 hover:underline text-xs">Ver foto</a>
                                            @else
                                                <span class="text-slate-600 text-xs">Simulado</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha (1/3): Agente Conversacional + Simulador de Prueba -->
            <div class="space-y-6">
                <!-- Agente Conversacional -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col h-[420px]">
                    <h2 class="font-semibold text-indigo-400 mb-2">🤖 Agente de Consulta Inteligente</h2>
                    <div id="chat-historial" class="flex-1 overflow-y-auto space-y-3 bg-slate-950 rounded-lg p-3 text-sm border border-slate-800 mb-3">
                        <div class="text-slate-400 text-xs">
                            Consulta placas (ej. <code class="text-indigo-300">1234ABC</code> o <code class="text-indigo-300">1234ABD</code> para probar sugerencia OCR), recaudación o alertas.
                        </div>
                    </div>
                    <form id="form-agente" class="flex gap-2">
                        <input type="text" id="input-pregunta" placeholder="Ej: Estado placa 1234ABC..." required
                               class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-indigo-500">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 px-4 py-2 rounded-lg text-sm font-semibold">Enviar</button>
                    </form>
                </div>

                <!-- Simulador de Paso (Para probar sin cámara/GPU) -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                    <h2 class="font-semibold text-slate-200 mb-3">🧪 Simulador de Paso de Vehículo</h2>
                    <form id="form-simulador" class="space-y-3 text-sm">
                        <div>
                            <label class="text-xs text-slate-400">Caseta</label>
                            <select name="caseta_id" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 mt-1">
                                @foreach($casetas as $c)
                                    <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-xs text-slate-400">Placa</label>
                                <input type="text" name="placa_simulada" value="1234ABC" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 mt-1 font-mono uppercase">
                            </div>
                            <div>
                                <label class="text-xs text-slate-400">Categoría</label>
                                <select name="categoria_simulada" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 mt-1">
                                    <option value="auto">Auto</option>
                                    <option value="moto">Moto</option>
                                    <option value="minibus">Minibús</option>
                                    <option value="bus">Bus</option>
                                    <option value="camion">Camión</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs text-slate-400">Ejes detectados</label>
                            <input type="number" name="ejes_simulados" value="2" min="2" max="6" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 mt-1">
                        </div>
                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-slate-950 font-bold py-2 rounded-lg transition">
                            Registrar Paso de Prueba
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Panel Exclusivo Administrador -->
        @if(auth()->user()->rol === 'admin')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-4 border-t border-slate-800">
                <!-- Gestión de Vehículos y Recarga -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <h2 class="font-semibold text-indigo-300">Admin: Registrar / Recargar Vehículo</h2>
                    <form method="POST" action="{{ route('vehiculos.guardar') }}" class="grid grid-cols-2 gap-3 text-sm">
                        @csrf
                        <input type="text" name="placa" placeholder="Placa (ej. 1234ABC)" required class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 uppercase">
                        <input type="text" name="propietario" placeholder="Propietario" required class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2">
                        <select name="categoria" class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2">
                            <option value="auto">Auto</option>
                            <option value="moto">Moto</option>
                            <option value="minibus">Minibús</option>
                            <option value="bus">Bus</option>
                            <option value="camion">Camión</option>
                        </select>
                        <input type="number" name="ejes" value="2" min="2" max="6" placeholder="Ejes" required class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2">
                        <input type="number" step="0.01" name="saldo" placeholder="Saldo (Bs.)" required class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2">
                        <button class="bg-indigo-600 hover:bg-indigo-500 font-semibold rounded-lg px-4 py-2">Guardar Vehículo</button>
                    </form>
                </div>

                <!-- Gestión de Usuarios Autorizados -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <h2 class="font-semibold text-indigo-300">Admin: Autorizar Operador / Admin Google</h2>
                    <form method="POST" action="{{ route('usuarios.guardar') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                        @csrf
                        <input type="email" name="email_google" placeholder="correo@gmail.com" required class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2">
                        <input type="text" name="nombre" placeholder="Nombre completo" required class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2">
                        <select name="rol" class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2">
                            <option value="operador">Operador</option>
                            <option value="admin">Admin</option>
                        </select>
                        <button class="sm:col-span-3 bg-indigo-600 hover:bg-indigo-500 font-semibold rounded-lg px-4 py-2">Guardar Usuario Autorizado</button>
                    </form>
                </div>
            </div>
        @endif
    </main>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        // 1. Conexión en tiempo real con Pusher
        const pusherKey = "{{ config('broadcasting.connections.pusher.key') }}";
        const pusherCluster = "{{ config('broadcasting.connections.pusher.options.cluster') }}";

        if (pusherKey) {
            const pusher = new Pusher(pusherKey, { cluster: pusherCluster });
            const channel = pusher.subscribe('peaje-channel');

            channel.bind('paso.registrado', function(payload) {
                const d = payload.datos;
                const tbody = document.getElementById('tabla-pasos');
                const badgeClass = d.estado === 'autorizado'
                    ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
                    : (d.estado === 'sin_saldo' ? 'bg-amber-500/15 text-amber-300 border-amber-500/30' : 'bg-red-500/15 text-red-300 border-red-500/30');

                const fotoHtml = d.imagen_url
                    ? `<a href="${d.imagen_url}" target="_blank" class="text-indigo-400 hover:underline text-xs">Ver foto</a>`
                    : `<span class="text-slate-600 text-xs">Simulado</span>`;

                const fila = `
                    <tr class="bg-indigo-950/30 transition">
                        <td class="px-4 py-3 text-slate-400">${d.hora}</td>
                        <td class="px-4 py-3 font-mono font-bold">${d.placa}</td>
                        <td class="px-4 py-3 capitalize">${d.categoria} (${d.ejes} ejes)</td>
                        <td class="px-4 py-3 font-semibold">Bs. ${Number(d.monto).toFixed(2)}</td>
                        <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs border ${badgeClass}">${d.estado}</span></td>
                        <td class="px-4 py-3">${fotoHtml}</td>
                    </tr>`;
                tbody.insertAdjacentHTML('afterbegin', fila);

                // Actualizar contadores
                const totalEl = document.getElementById('stat-total');
                totalEl.textContent = parseInt(totalEl.textContent) + 1;

                if (d.estado === 'autorizado') {
                    const recEl = document.getElementById('stat-recaudado');
                    const actual = parseFloat(recEl.textContent.replace('Bs. ', '').replace(',', ''));
                    recEl.textContent = 'Bs. ' + (actual + parseFloat(d.monto)).toFixed(2);
                } else {
                    const alertEl = document.getElementById('stat-alertas');
                    alertEl.textContent = parseInt(alertEl.textContent) + 1;
                }
            });
        }

        // 2. Chat Agente Conversacional
        document.getElementById('form-agente').addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = document.getElementById('input-pregunta');
            const pregunta = input.value.trim();
            if (!pregunta) return;

            const box = document.getElementById('chat-historial');
            box.insertAdjacentHTML('beforeend', `<div class="text-right"><span class="inline-block bg-indigo-600 text-white px-3 py-1.5 rounded-xl">${pregunta}</span></div>`);
            input.value = '';
            box.scrollTop = box.scrollHeight;

            const res = await fetch("{{ route('agente.consultar') }}", {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ pregunta })
            });
            const data = await res.json();
            const htmlResp = data.respuesta.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
            box.insertAdjacentHTML('beforeend', `<div class="bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-slate-200">${htmlResp}</div>`);
            box.scrollTop = box.scrollHeight;
        });

        // 3. Simulador de Paso
        document.getElementById('form-simulador').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const res = await fetch("{{ route('peaje.simular') }}", {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: formData
            });
            if (!res.ok) alert('Error al simular paso');
        });
    </script>
</body>
</html>