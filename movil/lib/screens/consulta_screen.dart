import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../services/api_service.dart';

class _MensajeChat {
  final bool esUsuario;
  final String texto;
  final String? estadoCodigo;
  final String? estadoTitulo;
  final File? imagen;

  const _MensajeChat({
    required this.esUsuario,
    required this.texto,
    this.estadoCodigo,
    this.estadoTitulo,
    this.imagen,
  });
}

class ConsultaScreen extends StatefulWidget {
  const ConsultaScreen({super.key});

  @override
  State<ConsultaScreen> createState() => _ConsultaScreenState();
}

class _ConsultaScreenState extends State<ConsultaScreen> {
  final TextEditingController _ctrl = TextEditingController();
  final ScrollController _scroll = ScrollController();
  final ImagePicker _picker = ImagePicker();
  final List<_MensajeChat> _mensajes = [
    const _MensajeChat(
      esUsuario: false,
      texto:
          'Hola, soy el Agente de Peaje. Escribe una placa (ej. 1234ABC) o envía una foto de la placa para consultar su estado en el sistema.',
    ),
  ];
  bool _cargando = false;

  Future<void> _enviarTexto() async {
    final texto = _ctrl.text.trim();
    if (texto.isEmpty || _cargando) return;

    _ctrl.clear();
    setState(() {
      _mensajes.add(_MensajeChat(esUsuario: true, texto: texto));
      _cargando = true;
    });
    _bajarScroll();

    try {
      final data = await ApiService.consultarAgenteTexto(texto);
      if (!mounted) return;
      setState(() {
        _mensajes.add(
          _MensajeChat(
            esUsuario: false,
            texto: (data['respuesta'] ?? 'Sin respuesta').toString(),
            estadoCodigo: data['estado_codigo']?.toString(),
            estadoTitulo: data['estado_titulo']?.toString(),
          ),
        );
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _mensajes.add(
          _MensajeChat(
            esUsuario: false,
            texto: 'Error: ${e.toString().replaceFirst('Exception: ', '')}',
          ),
        );
      });
    } finally {
      if (mounted) {
        setState(() => _cargando = false);
        _bajarScroll();
      }
    }
  }

  Future<void> _enviarImagen(ImageSource source) async {
    if (_cargando) return;
    final picked = await _picker.pickImage(
      source: source,
      maxWidth: 1280,
      imageQuality: 85,
    );
    if (picked == null) return;

    final file = File(picked.path);
    setState(() {
      _mensajes.add(
        _MensajeChat(
          esUsuario: true,
          texto: 'Consulta de placa por imagen',
          imagen: file,
        ),
      );
      _cargando = true;
    });
    _bajarScroll();

    try {
      final data = await ApiService.consultarAgenteImagen(file);
      if (!mounted) return;
      setState(() {
        _mensajes.add(
          _MensajeChat(
            esUsuario: false,
            texto: (data['respuesta'] ?? 'Sin respuesta').toString(),
            estadoCodigo: data['estado_codigo']?.toString(),
            estadoTitulo: data['estado_titulo']?.toString(),
          ),
        );
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _mensajes.add(
          _MensajeChat(
            esUsuario: false,
            texto: 'Error: ${e.toString().replaceFirst('Exception: ', '')}',
          ),
        );
      });
    } finally {
      if (mounted) {
        setState(() => _cargando = false);
        _bajarScroll();
      }
    }
  }

  void _bajarScroll() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(
          _scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Color _colorEstado(String? codigo) {
    switch (codigo) {
      case 'registrada_con_paso':
        return const Color(0xFF10B981);
      case 'registrada_sin_paso':
        return const Color(0xFF38BDF8);
      case 'identificada_no_registrada':
        return const Color(0xFFF59E0B);
      default:
        return const Color(0xFFEF4444);
    }
  }

  @override
  void dispose() {
    _ctrl.dispose();
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF020617),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0F172A),
        title: const Text(
          'Modo Consulta (Agente)',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
        ),
      ),
      body: Column(
        children: [
          Expanded(
            child: ListView.builder(
              controller: _scroll,
              padding: const EdgeInsets.all(14),
              itemCount: _mensajes.length,
              itemBuilder: (ctx, idx) {
                final m = _mensajes[idx];
                return Align(
                  alignment: m.esUsuario
                      ? Alignment.centerRight
                      : Alignment.centerLeft,
                  child: Container(
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(12),
                    constraints: BoxConstraints(
                      maxWidth: MediaQuery.of(context).size.width * 0.82,
                    ),
                    decoration: BoxDecoration(
                      color: m.esUsuario
                          ? const Color(0xFF4F46E5)
                          : const Color(0xFF0F172A),
                      borderRadius: BorderRadius.circular(14),
                      border: m.esUsuario
                          ? null
                          : Border.all(color: const Color(0xFF1E293B)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (m.estadoTitulo != null)
                          Container(
                            margin: const EdgeInsets.only(bottom: 6),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 3,
                            ),
                            decoration: BoxDecoration(
                              color: _colorEstado(
                                m.estadoCodigo,
                              ).withValues(alpha: 0.18),
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(
                                color: _colorEstado(m.estadoCodigo),
                              ),
                            ),
                            child: Text(
                              m.estadoTitulo!,
                              style: TextStyle(
                                color: _colorEstado(m.estadoCodigo),
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                        if (m.imagen != null)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: Image.file(
                                m.imagen!,
                                height: 140,
                                fit: BoxFit.cover,
                              ),
                            ),
                          ),
                        Text(
                          m.texto.replaceAll('**', ''),
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 13.5,
                            height: 1.35,
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
          if (_cargando)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 6),
              child: LinearProgressIndicator(color: Color(0xFF6366F1)),
            ),
          Container(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
            color: const Color(0xFF0F172A),
            child: Row(
              children: [
                IconButton(
                  onPressed: _cargando
                      ? null
                      : () => _enviarImagen(ImageSource.camera),
                  icon: const Icon(Icons.camera_alt, color: Color(0xFF38BDF8)),
                  tooltip: 'Tomar foto de placa',
                ),
                IconButton(
                  onPressed: _cargando
                      ? null
                      : () => _enviarImagen(ImageSource.gallery),
                  icon: const Icon(
                    Icons.photo_library,
                    color: Color(0xFFA78BFA),
                  ),
                  tooltip: 'Elegir foto de galería',
                ),
                Expanded(
                  child: TextField(
                    controller: _ctrl,
                    style: const TextStyle(color: Colors.white, fontSize: 14),
                    textInputAction: TextInputAction.send,
                    onSubmitted: (_) => _enviarTexto(),
                    decoration: InputDecoration(
                      hintText: 'Placa o pregunta (ej. 1234ABC)',
                      hintStyle: const TextStyle(color: Color(0xFF64748B)),
                      filled: true,
                      fillColor: const Color(0xFF020617),
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12),
                        borderSide: BorderSide.none,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.filled(
                  onPressed: _cargando ? null : _enviarTexto,
                  style: IconButton.styleFrom(
                    backgroundColor: const Color(0xFF6366F1),
                  ),
                  icon: const Icon(Icons.send, color: Colors.white),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
