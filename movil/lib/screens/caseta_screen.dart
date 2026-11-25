import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:camera/camera.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:http/http.dart' as http;
import 'package:image/image.dart' as img;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:web_socket_channel/web_socket_channel.dart';
import '../config.dart';
import '../services/api_service.dart';
import '../widgets/vigilancia_painter.dart';
import 'consulta_screen.dart';
import 'login_screen.dart';

class CasetaItem {
  final int id;
  final String nombre;
  const CasetaItem(this.id, this.nombre);
}

class _RawFrame {
  final int width;
  final int height;
  final int sensorOrientation;
  final List<Uint8List> planes;
  final List<int> bytesPerRow;
  final List<int> bytesPerPixel;
  final bool isBgra;

  const _RawFrame({
    required this.width,
    required this.height,
    required this.sensorOrientation,
    required this.planes,
    required this.bytesPerRow,
    required this.bytesPerPixel,
    required this.isBgra,
  });
}

Uint8List _convertirFrameAJpeg(_RawFrame frame) {
  img.Image decoded;

  if (frame.isBgra) {
    decoded = img.Image.fromBytes(
      width: frame.width,
      height: frame.height,
      bytes: frame.planes[0].buffer,
      order: img.ChannelOrder.bgra,
    );
  } else if (frame.planes.length >= 3) {
    final step = frame.width > 1280 ? 2 : 1;
    final outW = (frame.width + step - 1) ~/ step;
    final outH = (frame.height + step - 1) ~/ step;
    decoded = img.Image(width: outW, height: outH);
    final yPlane = frame.planes[0];
    final uPlane = frame.planes[1];
    final vPlane = frame.planes[2];
    final yStride = frame.bytesPerRow[0];
    final uStride = frame.bytesPerRow[1];
    final vStride = frame.bytesPerRow[2];
    final uPixelStride = frame.bytesPerPixel[1];
    final vPixelStride = frame.bytesPerPixel[2];

    for (int dy = 0, y = 0; y < frame.height; dy++, y += step) {
      final yRow = y * yStride;
      final uRow = (y >> 1) * uStride;
      final vRow = (y >> 1) * vStride;
      for (int dx = 0, x = 0; x < frame.width; dx++, x += step) {
        final yIdx = yRow + x;
        final uIdx = uRow + (x >> 1) * uPixelStride;
        final vIdx = vRow + (x >> 1) * vPixelStride;
        final yVal = yIdx < yPlane.length ? yPlane[yIdx] : 0;
        final uVal = (uIdx < uPlane.length ? uPlane[uIdx] : 128) - 128;
        final vVal = (vIdx < vPlane.length ? vPlane[vIdx] : 128) - 128;

        int r = (yVal + 1.370705 * vVal).round();
        int g = (yVal - 0.337633 * uVal - 0.698001 * vVal).round();
        int b = (yVal + 1.732446 * uVal).round();

        decoded.setPixelRgb(
          dx,
          dy,
          r.clamp(0, 255),
          g.clamp(0, 255),
          b.clamp(0, 255),
        );
      }
    }
  } else {
    // Fallback para formatos de 1 o 2 planos (ej. NV21 / Y solo)
    decoded = img.Image(width: frame.width, height: frame.height);
    final yPlane = frame.planes[0];
    final yStride = frame.bytesPerRow[0];
    final hasUv = frame.planes.length >= 2;
    final uvPlane = hasUv ? frame.planes[1] : null;
    final uvStride = hasUv ? frame.bytesPerRow[1] : 0;

    for (int y = 0; y < frame.height; y++) {
      final yRow = y * yStride;
      final uvRow = (y >> 1) * uvStride;
      for (int x = 0; x < frame.width; x++) {
        final yIdx = yRow + x;
        final yVal = yIdx < yPlane.length ? yPlane[yIdx] : 0;
        int uVal = 0;
        int vVal = 0;
        if (uvPlane != null) {
          final uvIdx = uvRow + (x & ~1);
          if (uvIdx + 1 < uvPlane.length) {
            vVal = uvPlane[uvIdx] - 128;
            uVal = uvPlane[uvIdx + 1] - 128;
          }
        }
        int r = (yVal + 1.370705 * vVal).round();
        int g = (yVal - 0.337633 * uVal - 0.698001 * vVal).round();
        int b = (yVal + 1.732446 * uVal).round();

        decoded.setPixelRgb(
          x,
          y,
          r.clamp(0, 255),
          g.clamp(0, 255),
          b.clamp(0, 255),
        );
      }
    }
  }

  img.Image orientada = frame.sensorOrientation != 0
      ? img.copyRotate(decoded, angle: frame.sensorOrientation)
      : decoded;

  if (orientada.width > 640) {
    orientada = img.copyResize(orientada, width: 640);
  }

  return Uint8List.fromList(img.encodeJpg(orientada, quality: 65));
}

class CasetaScreen extends StatefulWidget {
  const CasetaScreen({super.key});

  @override
  State<CasetaScreen> createState() => _CasetaScreenState();
}

class _CasetaScreenState extends State<CasetaScreen> {
  CameraController? _cameraController;
  WebSocketChannel? _wsChannel;
  StreamSubscription? _wsSub;

  bool _camaraLista = false;
  bool _procesando = false;
  bool _modoVigilanciaActivo = false;
  bool _wsConectado = false;
  bool _usarHttpFallback = false;
  bool _esperandoRespuestaWs = false;
  bool _convirtiendoFrame = false;
  bool _registrandoPaso = false;

  DateTime? _ultimoEnvioWs;
  Uint8List? _ultimoFrameJpeg;
  Map<String, dynamic>? _deteccionActual;
  final Map<String, DateTime> _ultimosRegistros = {};

  int _casetaId = 1;
  String _operador = '';
  String _wsUrl = AppConfig.wsVigilanciaUrl;
  dynamic _ultimoResultado;
  String? _errorMsg;

  final List<CasetaItem> _casetas = const [
    CasetaItem(1, 'Caseta Autopista La Paz - El Alto'),
    CasetaItem(2, 'Caseta Achica Arriba'),
  ];

  @override
  void initState() {
    super.initState();
    _cargarConfig();
    _iniciarCamara();
  }

  Future<void> _cargarConfig() async {
    final prefs = await SharedPreferences.getInstance();
    final urlGuardada = prefs.getString('ws_vigilancia_url');
    if (mounted) {
      setState(() {
        _operador = prefs.getString('user_nombre') ?? 'Operador';
        _wsUrl = urlGuardada ?? AppConfig.wsVigilanciaUrl;
      });
    }
    if (urlGuardada == null || urlGuardada.isEmpty) {
      final remota = await ApiService.obtenerInferenceUrl();
      if (remota != null && mounted) {
        setState(() => _wsUrl = _normalizarWsUrl(remota));
      }
    }
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
      imageFormatGroup: Platform.isIOS
          ? ImageFormatGroup.bgra8888
          : ImageFormatGroup.yuv420,
    );

    await _cameraController!.initialize();
    if (!mounted) return;
    setState(() => _camaraLista = true);
  }

  String _normalizarWsUrl(String raw) {
    var s = raw.trim().replaceAll('#', '');
    while (s.endsWith('/')) {
      s = s.substring(0, s.length - 1);
    }
    if (s.startsWith('https://')) {
      s = 'wss://${s.substring(8)}';
    } else if (s.startsWith('http://')) {
      s = 'ws://${s.substring(7)}';
    } else if (!s.startsWith('ws://') && !s.startsWith('wss://')) {
      s = 'wss://$s';
    }
    if (s.endsWith('/vigilancia') && !s.endsWith('/ws/vigilancia')) {
      s = '${s.substring(0, s.length - '/vigilancia'.length)}/ws/vigilancia';
    } else if (!s.endsWith('/ws/vigilancia')) {
      s = '$s/ws/vigilancia';
    }
    return s;
  }

  String _httpVigilanciaUrl() {
    var s = _normalizarWsUrl(_wsUrl);
    if (s.startsWith('wss://')) {
      s = 'https://${s.substring(6)}';
    } else if (s.startsWith('ws://')) {
      s = 'http://${s.substring(5)}';
    }
    if (s.endsWith('/ws/vigilancia')) {
      s = '${s.substring(0, s.length - '/ws/vigilancia'.length)}/vigilancia';
    }
    return s;
  }

  Future<void> _toggleVigilancia(bool activar) async {
    if (!_camaraLista || _cameraController == null) return;

    if (!activar) {
      await _detenerVigilancia();
      return;
    }

    setState(() {
      _modoVigilanciaActivo = true;
      _usarHttpFallback = false;
      _errorMsg = null;
      _deteccionActual = null;
    });

    final prefs = await SharedPreferences.getInstance();
    final urlGuardada = prefs.getString('ws_vigilancia_url');
    if (urlGuardada == null || urlGuardada.isEmpty) {
      final remota = await ApiService.obtenerInferenceUrl();
      if (remota != null && mounted) {
        setState(() => _wsUrl = _normalizarWsUrl(remota));
      }
    }

    await _conectarWebSocket();
    if (_modoVigilanciaActivo) {
      await _iniciarStreamCamara();
    }
  }

  Future<void> _conectarWebSocket() async {
    await _wsSub?.cancel();
    _wsSub = null;
    await _wsChannel?.sink.close();
    _wsChannel = null;

    final url = _normalizarWsUrl(_wsUrl);
    try {
      final channel = WebSocketChannel.connect(Uri.parse(url));
      await channel.ready.timeout(const Duration(seconds: 6));
      if (!_modoVigilanciaActivo || !mounted) {
        await channel.sink.close();
        return;
      }
      _wsChannel = channel;
      setState(() {
        _wsConectado = true;
        _usarHttpFallback = false;
      });

      _wsSub = channel.stream.listen(
        _onMensajeWs,
        onError: (_) => _activarFallbackHttp(),
        onDone: () {
          if (_modoVigilanciaActivo) _activarFallbackHttp();
        },
      );
    } catch (_) {
      _activarFallbackHttp();
    }
  }

  void _activarFallbackHttp() {
    if (!mounted || !_modoVigilanciaActivo) return;
    _wsChannel = null;
    setState(() {
      _wsConectado = true;
      _usarHttpFallback = true;
      _esperandoRespuestaWs = false;
      _errorMsg = null;
    });
  }

  Future<void> _iniciarStreamCamara() async {
    final controller = _cameraController;
    if (controller == null || controller.value.isStreamingImages) return;

    await controller.startImageStream((CameraImage image) {
      if (!_modoVigilanciaActivo || !_wsConectado || _convirtiendoFrame) {
        return;
      }
      if (_esperandoRespuestaWs) {
        final ultimo = _ultimoEnvioWs;
        if (ultimo == null || DateTime.now().difference(ultimo).inSeconds < 5) {
          return;
        }
      }
      _procesarYEnviarFrame(image);
    });
  }

  Future<void> _procesarYEnviarFrame(CameraImage image) async {
    _convirtiendoFrame = true;
    _esperandoRespuestaWs = true;
    _ultimoEnvioWs = DateTime.now();

    try {
      final raw = _RawFrame(
        width: image.width,
        height: image.height,
        sensorOrientation:
            _cameraController?.description.sensorOrientation ?? 0,
        planes: image.planes.map((p) => Uint8List.fromList(p.bytes)).toList(),
        bytesPerRow: image.planes.map((p) => p.bytesPerRow).toList(),
        bytesPerPixel: image.planes.map((p) => p.bytesPerPixel ?? 1).toList(),
        isBgra: image.format.group == ImageFormatGroup.bgra8888,
      );

      final jpegBytes = await compute(_convertirFrameAJpeg, raw);
      if (!_modoVigilanciaActivo) return;

      _ultimoFrameJpeg = jpegBytes;

      if (_usarHttpFallback) {
        final req = http.MultipartRequest(
          'POST',
          Uri.parse(_httpVigilanciaUrl()),
        );
        req.files.add(
          http.MultipartFile.fromBytes(
            'imagen',
            jpegBytes,
            filename: 'frame.jpg',
          ),
        );
        final streamed = await req.send().timeout(const Duration(seconds: 10));
        final body = await streamed.stream.bytesToString();
        if (streamed.statusCode == 200) {
          _onMensajeWs(body);
        } else {
          _esperandoRespuestaWs = false;
          if (mounted) {
            setState(() {
              _errorMsg =
                  'Servidor IA (${streamed.statusCode}). Revisa URL en el ícono 🔗.';
            });
          }
        }
      } else if (_wsChannel != null) {
        _wsChannel!.sink.add(jpegBytes);
      } else {
        _esperandoRespuestaWs = false;
      }
    } catch (e) {
      _esperandoRespuestaWs = false;
      if (mounted) {
        setState(() {
          _errorMsg = _usarHttpFallback
              ? 'Sin conexión con Colab/RunPod. Revisa URL en 🔗 ($e)'
              : 'Error enviando frame: $e';
        });
      }
    } finally {
      _convirtiendoFrame = false;
    }
  }

  void _onMensajeWs(dynamic mensaje) {
    _esperandoRespuestaWs = false;
    if (!mounted) return;

    try {
      final decoded = jsonDecode(mensaje.toString());
      if (decoded is! Map<String, dynamic>) return;

      setState(() {
        _deteccionActual = decoded;
      });

      final List<dynamic> lista = decoded['detecciones'] is List
          ? decoded['detecciones'] as List<dynamic>
          : [decoded];

      for (final item in lista) {
        if (item is! Map) continue;
        final placa = (item['placa'] ?? '').toString().trim().toUpperCase();
        final confOcr = (item['conf_ocr'] as num?)?.toDouble() ?? 0.0;
        final bool placaValida = placa.length >= 5 &&
            placa != 'ILEGIBLE' &&
            RegExp(r'^[A-Z0-9-]+$').hasMatch(placa) &&
            confOcr >= 0.72;
        if (item['confirmado'] == true && placaValida) {
          final ahora = DateTime.now();
          final ultimo = _ultimosRegistros[placa];
          if ((ultimo == null || ahora.difference(ultimo).inSeconds >= 30) &&
              !_registrandoPaso &&
              _ultimoFrameJpeg != null) {
            _ultimosRegistros[placa] = ahora;
            _registrarPasoEnSegundoPlano(_ultimoFrameJpeg!, placa);
            break;
          }
        }
      }
    } catch (_) {
      // Ignorar tramas no JSON
    }
  }

  Future<void> _registrarPasoEnSegundoPlano(
    Uint8List jpegBytes,
    String placa,
  ) async {
    _registrandoPaso = true;
    try {
      final respuesta = await ApiService.enviarCapturaBytes(
        casetaId: _casetaId,
        jpegBytes: jpegBytes,
      );
      if (!mounted) return;
      setState(() {
        _ultimoResultado = respuesta;
        _errorMsg = null;
      });
    } catch (e) {
      // Permitir reintento si falló el envío a Laravel
      _ultimosRegistros.remove(placa);
      if (!mounted) return;
      setState(() {
        _errorMsg = e.toString().replaceFirst('Exception: ', '');
      });
    } finally {
      _registrandoPaso = false;
    }
  }

  Future<void> _detenerVigilancia() async {
    setState(() {
      _modoVigilanciaActivo = false;
      _wsConectado = false;
      _esperandoRespuestaWs = false;
      _deteccionActual = null;
    });

    final controller = _cameraController;
    if (controller != null && controller.value.isStreamingImages) {
      await controller.stopImageStream();
    }
    await _wsSub?.cancel();
    _wsSub = null;
    await _wsChannel?.sink.close();
    _wsChannel = null;
  }

  Future<void> _capturarYEnviar() async {
    if (!_camaraLista || _procesando || _cameraController == null) return;

    final estabaEnVigilancia = _modoVigilanciaActivo;
    if (estabaEnVigilancia) {
      await _detenerVigilancia();
    }

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
      final det = respuesta['deteccion'];
      setState(() {
        _ultimoResultado = respuesta;
        if (det is Map<String, dynamic>) {
          _deteccionActual = det;
        }
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _errorMsg = e.toString().replaceFirst('Exception: ', '');
      });
    } finally {
      if (mounted) setState(() => _procesando = false);
      if (mounted && estabaEnVigilancia) {
        await _toggleVigilancia(true);
      }
    }
  }

  Future<void> _editarWsUrl() async {
    final ctrl = TextEditingController(text: _wsUrl);
    final nuevaUrl = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF0F172A),
        title: const Text(
          'URL Inferencia (Colab / RunPod)',
          style: TextStyle(color: Colors.white, fontSize: 16),
        ),
        content: TextField(
          controller: ctrl,
          style: const TextStyle(color: Colors.white, fontSize: 13),
          decoration: const InputDecoration(
            hintText: 'https://...-8000.proxy.runpod.net',
            hintStyle: TextStyle(color: Color(0xFF64748B)),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, '__AUTO__'),
            child: const Text('Auto (Render)'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancelar'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, ctrl.text.trim()),
            child: const Text('Guardar'),
          ),
        ],
      ),
    );
    ctrl.dispose();

    if (nuevaUrl == null) return;
    final prefs = await SharedPreferences.getInstance();
    if (nuevaUrl == '__AUTO__' || nuevaUrl.isEmpty) {
      await prefs.remove('ws_vigilancia_url');
      final remota = await ApiService.obtenerInferenceUrl();
      if (!mounted) return;
      setState(() {
        _wsUrl = _normalizarWsUrl(remota ?? AppConfig.wsVigilanciaUrl);
      });
    } else {
      final norm = _normalizarWsUrl(nuevaUrl);
      await prefs.setString('ws_vigilancia_url', norm);
      if (!mounted) return;
      setState(() => _wsUrl = norm);
    }

    if (_modoVigilanciaActivo) {
      await _detenerVigilancia();
      await _toggleVigilancia(true);
    }
  }

  Future<void> _abrirConsulta() async {
    final estabaEnVigilancia = _modoVigilanciaActivo;
    if (estabaEnVigilancia) {
      await _detenerVigilancia();
    }
    if (!mounted) return;
    await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const ConsultaScreen()),
    );
    if (mounted && estabaEnVigilancia) {
      await _toggleVigilancia(true);
    }
  }

  Future<void> _salir() async {
    await _detenerVigilancia();
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
    _wsSub?.cancel();
    _wsChannel?.sink.close();
    final controller = _cameraController;
    if (controller != null && controller.value.isStreamingImages) {
      controller.stopImageStream();
    }
    controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final previewAspectRatio = (_camaraLista && _cameraController != null)
        ? (MediaQuery.of(context).orientation == Orientation.portrait
              ? 1 / _cameraController!.value.aspectRatio
              : _cameraController!.value.aspectRatio)
        : 3 / 4;

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
            onPressed: _abrirConsulta,
            icon: const Icon(
              Icons.smart_toy_outlined,
              color: Color(0xFF38BDF8),
            ),
            tooltip: 'Modo Consulta (Agente)',
          ),
          IconButton(
            onPressed: _editarWsUrl,
            icon: const Icon(Icons.link, color: Color(0xFF94A3B8)),
            tooltip: 'Configurar WebSocket',
          ),
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
                    initialValue: _casetaId,
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
                      'CCTV Vivo',
                      style: TextStyle(color: Color(0xFF94A3B8), fontSize: 11),
                    ),
                    Switch(
                      value: _modoVigilanciaActivo,
                      activeThumbColor: const Color(0xFF10B981),
                      onChanged: _toggleVigilancia,
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
                    Center(
                      child: AspectRatio(
                        aspectRatio: previewAspectRatio,
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            CameraPreview(_cameraController!),
                            if (_deteccionActual != null)
                              Positioned.fill(
                                child: IgnorePointer(
                                  child: CustomPaint(
                                    size: Size.infinite,
                                    painter: VigilanciaPainter(
                                      _deteccionActual,
                                    ),
                                  ),
                                ),
                              ),
                          ],
                        ),
                      ),
                    )
                  else
                    const Center(child: CircularProgressIndicator()),
                  if (_modoVigilanciaActivo)
                    Positioned(
                      top: 10,
                      left: 10,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 10,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: _wsConectado
                              ? const Color(0xFF065F46).withValues(alpha: 0.85)
                              : const Color(0xFF7F1D1D).withValues(alpha: 0.85),
                          borderRadius: BorderRadius.circular(20),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              Icons.circle,
                              size: 8,
                              color: _wsConectado
                                  ? const Color(0xFF34D399)
                                  : const Color(0xFFFCA5A5),
                            ),
                            const SizedBox(width: 6),
                            Text(
                              _wsConectado
                                  ? '${_usarHttpFallback ? "EN VIVO (HTTP)" : "EN VIVO"}${_deteccionActual != null ? " • ${_deteccionActual!['latencia_ms'] ?? ''}ms (${_deteccionActual!['device'] ?? ''}) • ${_deteccionActual!['total_vehiculos'] ?? 0} veh" : ""}${_registrandoPaso ? " • Enviando..." : ""}'
                                  : 'DESCONECTADO',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
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

  void _verImagenAnotada({Uint8List? bytes, String? url}) {
    showDialog(
      context: context,
      builder: (_) => Dialog(
        backgroundColor: Colors.black,
        child: InteractiveViewer(
          child: bytes != null
              ? Image.memory(bytes, fit: BoxFit.contain)
              : Image.network(url!, fit: BoxFit.contain),
        ),
      ),
    );
  }

  Widget _buildTarjetaResultado(dynamic data) {
    final estado = data['estado'] ?? 'no_registrada';
    final paso = data['paso'] ?? {};
    final saldo = data['saldo_restante'];
    final imagenUrl = paso['imagen_url']?.toString();
    final b64 = data['imagen_anotada_b64']?.toString();
    Uint8List? imagenBytes;
    if (b64 != null && b64.isNotEmpty) {
      try {
        imagenBytes = base64Decode(b64);
      } catch (_) {}
    }

    Color colorBorde;
    Color colorFondo;
    String titulo;

    if (estado == 'autorizado') {
      colorBorde = const Color(0xFF10B981);
      colorFondo = const Color(0xFF064E3B).withValues(alpha: 0.5);
      titulo = 'PASO AUTORIZADO';
    } else if (estado == 'sin_saldo') {
      colorBorde = const Color(0xFFF59E0B);
      colorFondo = const Color(0xFF78350F).withValues(alpha: 0.5);
      titulo = 'ALERTA: SALDO INSUFICIENTE';
    } else {
      colorBorde = const Color(0xFFEF4444);
      colorFondo = const Color(0xFF7F1D1D).withValues(alpha: 0.5);
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
          if (imagenBytes != null ||
              (imagenUrl != null && imagenUrl.isNotEmpty))
            GestureDetector(
              onTap: () =>
                  _verImagenAnotada(bytes: imagenBytes, url: imagenUrl),
              child: Padding(
                padding: const EdgeInsets.only(right: 12),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: imagenBytes != null
                      ? Image.memory(
                          imagenBytes,
                          width: 68,
                          height: 68,
                          fit: BoxFit.cover,
                        )
                      : Image.network(
                          imagenUrl!,
                          width: 68,
                          height: 68,
                          fit: BoxFit.cover,
                          errorBuilder: (_, _, _) => const SizedBox.shrink(),
                        ),
                ),
              ),
            ),
          Expanded(
            child: Column(
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
                  '${(paso['categoria_detectada'] ?? '').toString().toUpperCase()} • ${paso['ejes_detectados'] ?? 2} ejes • ${paso['pasajeros'] ?? 0} pas. • Bs. ${paso['monto']}',
                  style: const TextStyle(
                    color: Color(0xFFCBD5E1),
                    fontSize: 12,
                  ),
                ),
              ],
            ),
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
