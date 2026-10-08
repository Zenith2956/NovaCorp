@extends('layouts.app')
@section('title', 'Statistiques')

@section('content')
<div class="inline" style="justify-content:space-between">
    <h1>Statistiques du workflow</h1>
    <form class="inline" id="filtres" onsubmit="return false">
        <select name="jours" id="jours">
            @foreach ($periodes as $j => $libelle)
                <option value="{{ $j }}" @selected($j === 30)>{{ $libelle }}</option>
            @endforeach
        </select>
        @if ($toutVoir)
            <select name="manager" id="manager">
                <option value="">Toute l'entreprise</option>
                @foreach ($managers as $m)
                    <option value="{{ $m->id }}">Équipe de {{ $m->nom_complet }}</option>
                @endforeach
            </select>
        @endif
    </form>
</div>
<p class="muted" id="perimetre">{{ $toutVoir ? 'Toute l\'entreprise' : 'Votre équipe' }} · mise à jour automatique toutes les minutes · <span id="maj">chargement…</span></p>

<div class="stats" id="kpi">
    <div class="stat"><div class="v" data-k="creees">–</div><div class="l">Demandes créées</div></div>
    <div class="stat"><div class="v" data-k="decisions">–</div><div class="l">Décisions (validées / refusées)</div></div>
    <div class="stat"><div class="v" data-k="temps">–</div><div class="l">Temps moyen de décision</div></div>
    <div class="stat"><div class="v" data-k="pct">–</div><div class="l">Réponses avant l'échéance</div></div>
    <div class="stat"><div class="v" data-k="attente">–</div><div class="l">En attente (dont en retard)</div></div>
    <div class="stat"><div class="v" data-k="traiter">–</div><div class="l">À traiter / en traitement</div></div>
</div>

<div class="graphes">
    <div class="card"><h2>Volume : demandes créées et décisions par jour</h2><canvas id="g-volume" height="120"></canvas></div>
    <div class="card"><h2>Temps moyen de décision par type (jours ouvrés)</h2><canvas id="g-temps" height="180"></canvas></div>
    <div class="card"><h2>Services sollicités (temps moyen passé chez chacun, en jours)</h2><canvas id="g-services" height="180"></canvas></div>
    <div class="card"><h2>Demandes créées par type</h2><canvas id="g-types" height="180"></canvas></div>
</div>
<p class="muted" style="font-size:.85rem">Mêmes chiffres que le dashboard Flutter (fonction Supabase <code>stats_workflow</code>). Temps des services : durée entre l'arrivée de la demande chez l'acteur et l'action suivante.</p>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    const TYPES = @json(\App\Models\Demande::TYPES);
    const COULEURS = ['#3b5bdb', '#2b8a3e', '#e67700', '#c2255c', '#6741d9', '#0c8599'];
    const url = @json(route('statistiques.donnees'));
    const graphes = {};
    const fr = (n, d = 1) => n === null || n === undefined ? '–' : Number(n).toLocaleString('fr-FR', { maximumFractionDigits: d });

    function graphe(id, config) {
        if (graphes[id]) { graphes[id].data = config.data; graphes[id].update(); return; }
        graphes[id] = new Chart(document.getElementById(id), config);
    }

    function afficher(s) {
        const kpi = (k, v) => document.querySelector(`[data-k="${k}"]`).textContent = v;
        kpi('creees', fr(s.volume.creees, 0));
        kpi('decisions', `${fr(s.decisions.total, 0)} (${fr(s.decisions.validees, 0)} / ${fr(s.decisions.refusees, 0)})`);
        kpi('temps', s.decisions.temps_moyen_jours_ouvres === null ? '–' : fr(s.decisions.temps_moyen_jours_ouvres) + ' j');
        kpi('pct', s.decisions.dans_les_temps_pct === null ? '–' : fr(s.decisions.dans_les_temps_pct) + ' %');
        kpi('attente', `${fr(s.en_cours.en_attente, 0)} (${fr(s.en_cours.en_retard, 0)})`);
        kpi('traiter', `${fr(s.en_cours.a_traiter, 0)} / ${fr(s.en_cours.en_traitement, 0)}`);
        document.getElementById('maj').textContent = 'calculé à ' + new Date(s.calcule_le).toLocaleTimeString('fr-FR');

        const jours = s.par_jour.map(j => new Date(j.jour + 'T12:00:00').toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }));
        graphe('g-volume', { type: 'bar', data: { labels: jours, datasets: [
            { label: 'Créées', data: s.par_jour.map(j => j.creees), backgroundColor: COULEURS[0] },
            { label: 'Décisions', data: s.par_jour.map(j => j.decisions), backgroundColor: COULEURS[1] },
        ] }, options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });

        const tt = s.decisions.temps_moyen_par_type || {};
        graphe('g-temps', { type: 'bar', data: { labels: Object.keys(tt).map(t => TYPES[t] || t), datasets: [
            { label: 'Jours ouvrés', data: Object.values(tt), backgroundColor: COULEURS[2] },
        ] }, options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } } });

        graphe('g-services', { type: 'bar', data: { labels: s.services.map(x => `${x.libelle} (${x.passages})`), datasets: [
            { label: 'Jours en moyenne', data: s.services.map(x => x.jours_moyens), backgroundColor: COULEURS[4] },
        ] }, options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } } });

        const pt = s.volume.par_type || {};
        graphe('g-types', { type: 'doughnut', data: { labels: Object.keys(pt).map(t => TYPES[t] || t), datasets: [
            { data: Object.values(pt), backgroundColor: COULEURS },
        ] } });
    }

    async function charger() {
        const params = new URLSearchParams({ jours: document.getElementById('jours').value });
        const m = document.getElementById('manager');
        if (m && m.value) params.set('manager', m.value);
        const r = await fetch(url + '?' + params, { headers: { Accept: 'application/json' } });
        if (r.ok) afficher(await r.json());
    }

    document.getElementById('filtres').addEventListener('change', charger);
    charger();
    setInterval(charger, 60000);
})();
</script>
@endsection
