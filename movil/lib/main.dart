import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'config.dart';
import 'screens/caseta_screen.dart';
import 'screens/login_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await GoogleSignIn.instance.initialize(serverClientId: AppConfig.webClientId);
  final prefs = await SharedPreferences.getInstance();
  final tieneToken = prefs.getString('auth_token') != null;

  runApp(PeajeApp(autenticado: tieneToken));
}

class PeajeApp extends StatelessWidget {
  final bool autenticado;
  const PeajeApp({super.key, required this.autenticado});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Peaje Inteligente',
      debugShowCheckedModeBanner: false,
      theme: ThemeData.dark(useMaterial3: true),
      home: autenticado ? const CasetaScreen() : const LoginScreen(),
    );
  }
}
