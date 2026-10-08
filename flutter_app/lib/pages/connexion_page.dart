import 'package:flutter/material.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../main.dart';

/// Connexion avec un compte Supabase Auth dont l'e-mail (confirmé) est celui d'un employé NovaCorp.
class ConnexionPage extends StatefulWidget {
  const ConnexionPage({super.key});

  @override
  State<ConnexionPage> createState() => _ConnexionPageState();
}

class _ConnexionPageState extends State<ConnexionPage> {
  final _email = TextEditingController();
  final _motDePasse = TextEditingController();
  bool _enCours = false;
  String? _erreur;

  Future<void> _connecter() async {
    setState(() {
      _enCours = true;
      _erreur = null;
    });
    try {
      await supabase.auth.signInWithPassword(email: _email.text.trim(), password: _motDePasse.text);
    } on AuthException catch (e) {
      setState(() => _erreur = e.message == 'Invalid login credentials' ? 'E-mail ou mot de passe incorrect.' : e.message);
    } finally {
      if (mounted) setState(() => _enCours = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 380),
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text('NovaCorp', style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(height: 4),
                const Text('Dashboard du workflow'),
                const SizedBox(height: 24),
                TextField(controller: _email, decoration: const InputDecoration(labelText: 'E-mail'), keyboardType: TextInputType.emailAddress),
                TextField(controller: _motDePasse, decoration: const InputDecoration(labelText: 'Mot de passe'), obscureText: true, onSubmitted: (_) => _connecter()),
                const SizedBox(height: 16),
                if (_erreur != null) Text(_erreur!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                const SizedBox(height: 8),
                FilledButton(onPressed: _enCours ? null : _connecter, child: Text(_enCours ? 'Connexion…' : 'Se connecter')),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
