import 'dart:async';
import 'dart:io';
import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/api_service.dart';
import 'login_screen.dart';

class CasetaItem {
  final int id;
  final String nombre;
  const CasetaItem(this.id, this.nombre);
}

class CasetaScreen extends StatefulWidget {
  const CasetaScreen({super.key});

  @override
  State<CasetaScreen> createState() => _CasetaScreenState();
}

class _CasetaScreenState extends State<CasetaScreen> {
  CameraController? _cameraController;
  bool _camaraLista = false;
  bool _procesando = false;
  bool _modoAuto = false;
  Timer? _timerAuto;

  int _casetaId = 1;
  String _operador = '';
  dynamic _ultimoResultado;
  String? _errorMsg;

  final List<CasetaItem> _casetas = const [
    CasetaItem(1, 'Caseta Autopista La Paz - El Alto'),
    CasetaItem(2, 'Caseta Achica Arriba'),
  ];

  @override
  void initState() {
    super.initState();
    _cargarUsuario();
    _iniciarCamara();
  }

  Future<void> _cargarUsuario() async {
    final prefs = await SharedPreferences.getInstance();
    setState(() {
      _operador = prefs.getString('user_nombre') ?? 'Operador';
    });
  }

  Future<void> _iniciarCamara() async {
    final cameras = await availableCameras();
    if (cameras.isEmpty) return;

    final trasera = cameras.firstWhere(
      (c) => c.lensDirection == CameraLensDirection.back,
      orElse: () => cameras.first,
    );

    _cameraController = CameraController(
      trasera,
      ResolutionPreset.medium,
      enableAudio: false,
    );

    await _cameraController!.initialize();
    if (!mounted) return;
    setState(() => _camaraLista = true);
  }

  Future<void> _capturarYEnviar() async {
    if (!_camaraLista || _procesando || _cameraController == null) return;

    setState(() {
      _procesando = true;
      _errorMsg = null;
    });

    try {
      final xFile = await _cameraController!.takePicture();
      final respuesta = await ApiService.enviarCaptura(
        casetaId: _casetaId,
        imagen: File(xFile.path),
      );

      if (!mounted) return;
      setState(() {
        _ultimoResultado = respuesta;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _errorMsg = e.toString().replaceFirst('Exception: ', '');
      });
    } finally {
      if (mounted) setState(() => _procesando = false);
    }
  }

  void _toggleModoAuto(bool activo) {
    setState(() => _modoAuto = activo);
    _timerAuto?.cancel();
    if (activo) {
      _timerAuto = Timer.periodic(const Duration(seconds: 5), (_) {
        if (!_procesando) _capturarYEnviar();
      });
    }
  }

  Future<void> _salir() async {
    _timerAuto?.cancel();
    await GoogleSignIn.instance.signOut();
    await ApiService.cerrarSesion();
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  @override
  void dispose() {
    _timerAuto?.cancel();
    _cameraController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF020617),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0F172A),
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Control de Caseta',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: Colors.white,
              ),
            ),
            Text(
              _operador,
              style: const TextStyle(fontSize: 11, color: Color(0xFF94A3B8)),
            ),
          ],
        ),
        actions: [
          IconButton(
            onPressed: _salir,
            icon: const Icon(Icons.logout, color: Color(0xFF94A3B8)),
            tooltip: 'Cerrar sesión',
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            color: const Color(0xFF0F172A),
            child: Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<int>(
                    value: _casetaId,
                    dropdownColor: const Color(0xFF1E293B),
                    style: const TextStyle(color: Colors.white, fontSize: 13),
                    decoration: InputDecoration(
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 8,
                      ),
                      filled: true,
                      fillColor: const Color(0xFF020617),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10),
                      ),
                    ),
                    items: _casetas
                        .map(
                          (c) => DropdownMenuItem<int>(
                            value: c.id,
                            child: Text(
                              c.nombre,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        )
                        .toList(),
                    onChanged: (val) => setState(() => _casetaId = val!),
                  ),
                ),
                const SizedBox(width: 10),
                Column(
                  children: [
                    const Text(
                      'Auto 5s',
                      style: TextStyle(color: Color(0xFF94A3B8), fontSize: 11),
                    ),
                    Switch(
                      value: _modoAuto,
                      activeColor: const Color(0xFF10B981),
                      onChanged: _toggleModoAuto,
                    ),
                  ],
                ),
              ],
            ),
          ),

          Expanded(
            child: Container(
              margin: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.black,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: const Color(0xFF1E293B)),
              ),
              clipBehavior: Clip.antiAlias,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  if (_camaraLista && _cameraController != null)
                    CameraPreview(_cameraController!)
                  else
                    const Center(child: CircularProgressIndicator()),
                  if (_procesando)
                    Container(
                      color: Colors.black54,
                      child: const Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            CircularProgressIndicator(color: Color(0xFF6366F1)),
                            SizedBox(height: 12),
                            Text(
                              'Analizando vehículo...',
                              style: TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),

          if (_errorMsg != null)
            Container(
              margin: const EdgeInsets.symmetric(horizontal: 14),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFF450A0A),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text(
                _errorMsg!,
                style: const TextStyle(color: Color(0xFFFCA5A5), fontSize: 12),
              ),
            ),

          if (_ultimoResultado != null)
            _buildTarjetaResultado(_ultimoResultado),

          Padding(
            padding: const EdgeInsets.all(14),
            child: SizedBox(
              width: double.infinity,
              height: 54,
              child: ElevatedButton.icon(
                onPressed: _procesando ? null : _capturarYEnviar,
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF10B981),
                  foregroundColor: const Color(0xFF020617),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
                icon: const Icon(Icons.camera_alt),
                label: const Text(
                  'CAPTURAR Y REGISTRAR PASO',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTarjetaResultado(dynamic data) {
    final estado = data['estado'] ?? 'no_registrada';
    final paso = data['paso'] ?? {};
    final saldo = data['saldo_restante'];

    Color colorBorde;
    Color colorFondo;
    String titulo;

    if (estado == 'autorizado') {
      colorBorde = const Color(0xFF10B981);
      colorFondo = const Color(0xFF064E3B).withOpacity(0.5);
      titulo = 'PASO AUTORIZADO';
    } else if (estado == 'sin_saldo') {
      colorBorde = const Color(0xFFF59E0B);
      colorFondo = const Color(0xFF78350F).withOpacity(0.5);
      titulo = 'ALERTA: SALDO INSUFICIENTE';
    } else {
      colorBorde = const Color(0xFFEF4444);
      colorFondo = const Color(0xFF7F1D1D).withOpacity(0.5);
      titulo = 'ALERTA: PLACA NO REGISTRADA';
    }

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 14),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: colorFondo,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: colorBorde, width: 1.5),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                titulo,
                style: TextStyle(
                  color: colorBorde,
                  fontWeight: FontWeight.bold,
                  fontSize: 12,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'Placa: ${paso['placa_normalizada'] ?? 'ILEGIBLE'}',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                ),
              ),
              Text(
                '${(paso['categoria_detectada'] ?? '').toString().toUpperCase()} • ${paso['ejes_detectados'] ?? 2} ejes • Bs. ${paso['monto']}',
                style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 12),
              ),
            ],
          ),
          if (saldo != null)
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                const Text(
                  'Saldo Restante',
                  style: TextStyle(color: Color(0xFF94A3B8), fontSize: 11),
                ),
                Text(
                  'Bs. $saldo',
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                    fontSize: 15,
                  ),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
