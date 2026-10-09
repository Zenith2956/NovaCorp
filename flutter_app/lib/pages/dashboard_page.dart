import 'dart:async';

import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../main.dart';

/// Dashboard : temps moyen, volume, services sollicités.
/// Données : fonction Supabase `stats_workflow` (RH / direction / admin = entreprise, manager = son équipe).
/// Temps réel : abonnement à la table `stats_quotidiennes` (recalculée par trigger à chaque changement
/// de demande ou de tâche) → les graphiques se mettent à jour tout seuls.
class DashboardPage extends StatefulWidget {
  const DashboardPage({super.key});

  @override
  State<DashboardPage> createState() => _DashboardPageState();
}

const _types = {'conge': 'Congé', 'materiel': 'Matériel', 'formation': 'Formation', 'note_de_frais': 'Note de frais', 'autre': 'Autre'};
const _periodes = {7: '7 jours', 30: '30 jours', 90: '3 mois', 365: '12 mois'};
const _couleurs = [Color(0xFF3B5BDB), Color(0xFF2B8A3E), Color(0xFFE67700), Color(0xFFC2255C), Color(0xFF6741D9), Color(0xFF0C8599)];

class _DashboardPageState extends State<DashboardPage> {
  int _jours = 30;
  Map<String, dynamic>? _stats;
  String? _erreur;
  DateTime? _majTempsReel;
  StreamSubscription<List<Map<String, dynamic>>>? _abonnement;
  Timer? _attente;

  @override
  void initState() {
    super.initState();
    _charger();
    // Realtime : chaque changement de la ligne du jour relance le calcul (regroupé sur 1 s)
    _abonnement = supabase.from('stats_quotidiennes').stream(primaryKey: ['id']).listen((_) {
      _attente?.cancel();
      _attente = Timer(const Duration(seconds: 1), () {
        _majTempsReel = DateTime.now();
        _charger();
      });
    });
  }

  @override
  void dispose() {
    _abonnement?.cancel();
    _attente?.cancel();
    super.dispose();
  }

  Future<void> _charger() async {
    final fin = DateTime.now();
    final debut = fin.subtract(Duration(days: _jours - 1));
    final f = DateFormat('yyyy-MM-dd');
    try {
      final res = await supabase.rpc('stats_workflow', params: {'p_debut': f.format(debut), 'p_fin': f.format(fin)});
      if (!mounted) return;
      setState(() {
        _stats = Map<String, dynamic>.from(res as Map);
        _erreur = null;
      });
    } on PostgrestException catch (e) {
      if (!mounted) return;
      setState(() => _erreur = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _stats;
    return Scaffold(
      appBar: AppBar(
        title: Text(s == null ? 'Statistiques' : 'Statistiques · ${s['perimetre'] == 'equipe' ? 'mon équipe' : 'entreprise'}'),
        actions: [
          DropdownButton<int>(
            value: _jours,
            underline: const SizedBox(),
            items: _periodes.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
            onChanged: (v) {
              setState(() => _jours = v!);
              _charger();
            },
          ),
          IconButton(tooltip: 'Se déconnecter', icon: const Icon(Icons.logout), onPressed: () => supabase.auth.signOut()),
        ],
      ),
      body: _erreur != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_erreur!, textAlign: TextAlign.center)))
          : s == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(onRefresh: _charger, child: _contenu(context, s)),
    );
  }

  Widget _contenu(BuildContext context, Map<String, dynamic> s) {
    final dec = Map<String, dynamic>.from(s['decisions'] as Map);
    final cours = Map<String, dynamic>.from(s['en_cours'] as Map);
    final vol = Map<String, dynamic>.from(s['volume'] as Map);
    String nb(dynamic v, [String suffixe = '']) => v == null ? '–' : '${NumberFormat.decimalPattern('fr_FR').format(v)}$suffixe';

    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(_majTempsReel == null ? 'Temps réel actif' : 'Mis à jour en temps réel à ${DateFormat.Hms('fr_FR').format(_majTempsReel!)}',
          style: Theme.of(context).textTheme.bodySmall),
      const SizedBox(height: 8),
      Wrap(spacing: 12, runSpacing: 12, children: [
        _kpi('Demandes créées', nb(vol['creees'])),
        _kpi('Décisions', '${nb(dec['total'])} (${nb(dec['validees'])} ✓ / ${nb(dec['refusees'])} ✗)'),
        _kpi('Temps moyen de décision', nb(dec['temps_moyen_jours_ouvres'], ' j ouvrés')),
        _kpi("Réponses avant l'échéance", nb(dec['dans_les_temps_pct'], ' %')),
        _kpi('En attente (en retard)', '${nb(cours['en_attente'])} (${nb(cours['en_retard'])})'),
        _kpi('À traiter / en traitement', '${nb(cours['a_traiter'])} / ${nb(cours['en_traitement'])}'),
      ]),
      _carte('Volume : créées et décisions par jour', SizedBox(height: 220, child: _volume(s['par_jour'] as List))),
      _carte('Temps moyen par type (jours ouvrés)',
          SizedBox(height: 200, child: _barres(Map<String, dynamic>.from((dec['temps_moyen_par_type'] ?? {}) as Map)))),
      _carte('Services sollicités (temps moyen passé chez chacun)', _services(s['services'] as List)),
      _carte('Demandes par type', SizedBox(height: 220, child: _camembert(Map<String, dynamic>.from((vol['par_type'] ?? {}) as Map)))),
    ]);
  }

  Widget _kpi(String libelle, String valeur) => SizedBox(
        width: 220,
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(valeur, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
              Text(libelle, style: Theme.of(context).textTheme.bodySmall),
            ]),
          ),
        ),
      );

  Widget _carte(String titre, Widget contenu) => Card(
        margin: const EdgeInsets.only(top: 16),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(titre, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 12),
            contenu,
          ]),
        ),
      );

  static const _sansTitres = AxisTitles(sideTitles: SideTitles(showTitles: false));

  Widget _volume(List parJour) {
    final pas = (parJour.length / 8).ceil().clamp(1, 1000);
    return BarChart(BarChartData(
      gridData: const FlGridData(drawVerticalLine: false),
      borderData: FlBorderData(show: false),
      titlesData: FlTitlesData(
        topTitles: _sansTitres,
        rightTitles: _sansTitres,
        bottomTitles: AxisTitles(
          sideTitles: SideTitles(
            showTitles: true,
            reservedSize: 24,
            getTitlesWidget: (v, meta) => v.toInt() % pas != 0
                ? const SizedBox()
                : Text(DateFormat('dd/MM').format(DateTime.parse(parJour[v.toInt()]['jour'] as String)), style: const TextStyle(fontSize: 10)),
          ),
        ),
      ),
      barGroups: [
        for (var i = 0; i < parJour.length; i++)
          BarChartGroupData(x: i, barRods: [
            BarChartRodData(toY: (parJour[i]['creees'] as num).toDouble(), color: _couleurs[0], width: 4),
            BarChartRodData(toY: (parJour[i]['decisions'] as num).toDouble(), color: _couleurs[1], width: 4),
          ]),
      ],
    ));
  }

  Widget _barres(Map<String, dynamic> valeurs) {
    if (valeurs.isEmpty) return const Center(child: Text('Aucune décision sur la période'));
    final cles = valeurs.keys.toList();
    return BarChart(BarChartData(
      gridData: const FlGridData(drawVerticalLine: false),
      borderData: FlBorderData(show: false),
      titlesData: FlTitlesData(
        topTitles: _sansTitres,
        rightTitles: _sansTitres,
        bottomTitles: AxisTitles(
          sideTitles: SideTitles(
            showTitles: true,
            reservedSize: 24,
            getTitlesWidget: (v, meta) => Text(_types[cles[v.toInt()]] ?? cles[v.toInt()], style: const TextStyle(fontSize: 11)),
          ),
        ),
      ),
      barGroups: [
        for (var i = 0; i < cles.length; i++)
          BarChartGroupData(x: i, barRods: [BarChartRodData(toY: (valeurs[cles[i]] as num).toDouble(), color: _couleurs[2], width: 22)]),
      ],
    ));
  }

  Widget _services(List services) {
    if (services.isEmpty) return const Text('Aucun passage terminé sur la période');
    final max = services.map((e) => (e['jours_moyens'] as num).toDouble()).fold<double>(0.1, (a, b) => a > b ? a : b);
    return Column(children: [
      for (final x in services)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(children: [
            SizedBox(width: 200, child: Text('${x['libelle']} (${x['passages']})')),
            Expanded(
              child: LinearProgressIndicator(
                value: (x['jours_moyens'] as num).toDouble() / max,
                minHeight: 14,
                color: _couleurs[4],
                backgroundColor: const Color(0xFFEEF1F6),
              ),
            ),
            SizedBox(width: 70, child: Text('  ${x['jours_moyens']} j')),
          ]),
        ),
    ]);
  }

  Widget _camembert(Map<String, dynamic> parType) {
    if (parType.isEmpty) return const Center(child: Text('Aucune demande sur la période'));
    final cles = parType.keys.toList();
    return PieChart(PieChartData(sectionsSpace: 2, centerSpaceRadius: 40, sections: [
      for (var i = 0; i < cles.length; i++)
        PieChartSectionData(
          value: (parType[cles[i]] as num).toDouble(),
          title: '${_types[cles[i]] ?? cles[i]}\n${parType[cles[i]]}',
          color: _couleurs[i % _couleurs.length],
          radius: 70,
          titleStyle: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
        ),
    ]));
  }
}
