import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import '../services/api_service.dart';
import 'caseta_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  bool _cargando = false;
  String? _error;

  Future<void> _iniciarSesion() async {
    setState(() {
      _cargando = true;
      _error = null;
    });

    try {
      final account = await GoogleSignIn.instance.authenticate();
      final idToken = account.authentication.idToken;

      if (idToken == null) {
        throw Exception('No se obtuvo idToken. Revisa webClientId y SHA-1.');
      }

      await ApiService.loginConGoogle(idToken);

      if (!mounted) return;
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const CasetaScreen()),
      );
    } on GoogleSignInException catch (e) {
      if (mounted) {
        setState(() {
          _error =
              'Google (${e.code.name}): ${e.description ?? 'Operación cancelada o bloqueada'}';
          _cargando = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e.toString().replaceFirst('Exception: ', '');
          _cargando = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF020617),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Container(
            padding: const EdgeInsets.all(28),
            decoration: BoxDecoration(
              color: const Color(0xFF0F172A),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFF1E293B)),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(
                  Icons.shield_outlined,
                  size: 54,
                  color: Color(0xFF6366F1),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Peaje Inteligente',
                  style: TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(height: 8),
                const Text(
                  'Terminal móvil de captura en caseta',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
                ),
                const SizedBox(height: 24),
                if (_error != null)
                  Container(
                    margin: const EdgeInsets.only(bottom: 16),
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFF450A0A),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: const Color(0xFF991B1B)),
                    ),
                    child: Text(
                      _error!,
                      style: const TextStyle(
                        color: Color(0xFFFCA5A5),
                        fontSize: 12,
                      ),
                    ),
                  ),
                SizedBox(
                  width: double.infinity,
                  height: 48,
                  child: ElevatedButton.icon(
                    onPressed: _cargando ? null : _iniciarSesion,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.white,
                      foregroundColor: const Color(0xFF0F172A),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    icon: _cargando
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.login),
                    label: Text(
                      _cargando ? 'Conectando...' : 'Continuar con Google',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
