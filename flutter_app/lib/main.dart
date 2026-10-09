import 'package:flutter/material.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import 'pages/connexion_page.dart';
import 'pages/dashboard_page.dart';

/// Paramètres passés au lancement (jamais écrits dans le code) :
/// flutter run --dart-define=SUPABASE_URL=https://xxx.supabase.co --dart-define=SUPABASE_ANON_KEY=...
const supabaseUrl = String.fromEnvironment('SUPABASE_URL');
const supabaseAnonKey = String.fromEnvironment('SUPABASE_ANON_KEY');

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('fr_FR');
  await Supabase.initialize(url: supabaseUrl, publishableKey: supabaseAnonKey);
  runApp(const NovaCorpApp());
}

final supabase = Supabase.instance.client;

class NovaCorpApp extends StatelessWidget {
  const NovaCorpApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'NovaCorp',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFF3B5BDB), useMaterial3: true),
      home: const PorteEntree(),
    );
  }
}

/// Affiche la connexion ou le dashboard selon la session Supabase Auth.
class PorteEntree extends StatelessWidget {
  const PorteEntree({super.key});

  @override
  Widget build(BuildContext context) {
    return StreamBuilder<AuthState>(
      stream: supabase.auth.onAuthStateChange,
      builder: (context, _) =>
          supabase.auth.currentSession == null ? const ConnexionPage() : const DashboardPage(),
    );
  }
}
