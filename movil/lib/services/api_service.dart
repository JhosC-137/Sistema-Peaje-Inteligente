import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../config.dart';

class ApiService {
  static Future<dynamic> loginConGoogle(String idToken) async {
    final res = await http
        .post(
          Uri.parse('${AppConfig.baseUrl}/auth/google'),
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: jsonEncode({'id_token': idToken}),
        )
        .timeout(const Duration(seconds: 65));

    final data = jsonDecode(res.body);
    if (res.statusCode == 200) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('auth_token', data['token']);
      await prefs.setString('user_nombre', data['usuario']['nombre']);
      await prefs.setString('user_rol', data['usuario']['rol']);
      return data;
    } else {
      throw Exception(data['error'] ?? 'Acceso denegado (${res.statusCode})');
    }
  }

  static Future<dynamic> enviarCaptura({
    required int casetaId,
    required File imagen,
  }) async {
    final file = await http.MultipartFile.fromPath('imagen', imagen.path);
    return _enviarMultipart(casetaId: casetaId, archivo: file);
  }

  static Future<dynamic> enviarCapturaBytes({
    required int casetaId,
    required Uint8List jpegBytes,
  }) async {
    final file = http.MultipartFile.fromBytes(
      'imagen',
      jpegBytes,
      filename: 'frame.jpg',
    );
    return _enviarMultipart(casetaId: casetaId, archivo: file);
  }

  static Future<dynamic> _enviarMultipart({
    required int casetaId,
    required http.MultipartFile archivo,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('auth_token');
    if (token == null) throw Exception('Sesión expirada');

    final request = http.MultipartRequest(
      'POST',
      Uri.parse('${AppConfig.baseUrl}/peaje/procesar'),
    );
    request.headers.addAll({
      'Authorization': 'Bearer $token',
      'Accept': 'application/json',
    });
    request.fields['caseta_id'] = casetaId.toString();
    request.files.add(archivo);

    final streamed = await request.send().timeout(const Duration(seconds: 65));
    final body = await streamed.stream.bytesToString();
    final data = jsonDecode(body);

    if (streamed.statusCode == 200) {
      return data;
    } else {
      throw Exception(
        data['error'] ?? data['message'] ?? 'Error al procesar paso',
      );
    }
  }

  static Future<void> cerrarSesion() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.clear();
  }

  static Future<Map<String, dynamic>> consultarAgenteTexto(
    String pregunta,
  ) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('auth_token');
    if (token == null) throw Exception('Sesión expirada');

    final res = await http
        .post(
          Uri.parse('${AppConfig.baseUrl}/v1/agente/chat'),
          headers: {
            'Authorization': 'Bearer $token',
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: jsonEncode({'pregunta': pregunta}),
        )
        .timeout(const Duration(seconds: 65));

    final data = jsonDecode(res.body) as Map<String, dynamic>;
    if (res.statusCode == 200) return data;
    throw Exception(
      data['error'] ?? data['message'] ?? 'Error al consultar al agente',
    );
  }

  static Future<Map<String, dynamic>> consultarAgenteImagen(
    File imagen,
  ) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('auth_token');
    if (token == null) throw Exception('Sesión expirada');

    final request = http.MultipartRequest(
      'POST',
      Uri.parse('${AppConfig.baseUrl}/v1/placas/consultar-imagen'),
    );
    request.headers.addAll({
      'Authorization': 'Bearer $token',
      'Accept': 'application/json',
    });
    request.files.add(await http.MultipartFile.fromPath('imagen', imagen.path));

    final streamed = await request.send().timeout(const Duration(seconds: 65));
    final body = await streamed.stream.bytesToString();
    final data = jsonDecode(body) as Map<String, dynamic>;
    if (streamed.statusCode == 200) return data;
    throw Exception(
      data['error'] ?? data['message'] ?? 'Error al consultar placa por imagen',
    );
  }
}
