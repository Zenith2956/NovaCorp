// Test de base du dashboard NovaCorp : l'écran de connexion s'affiche.
// (Le dashboard lui-même a besoin d'une connexion Supabase : il se teste en lançant l'application.)
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:novacorp_dashboard/pages/connexion_page.dart';

void main() {
  testWidgets("L'écran de connexion affiche les champs et le bouton", (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: ConnexionPage()));

    expect(find.text('NovaCorp'), findsOneWidget);
    expect(find.text('E-mail'), findsOneWidget);
    expect(find.text('Mot de passe'), findsOneWidget);
    expect(find.text('Se connecter'), findsOneWidget);
  });
}
