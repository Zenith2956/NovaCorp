@extends('layouts.app')
@section('title', 'Tableau de bord')

@section('content')
<h1>Bonjour {{ auth()->user()->prenom }}</h1>

<div class="stats">
    <div class="stat"><div class="v">{{ $nbEmployes }}</div><div class="l">Employés actifs</div></div>
    <div class="stat"><div class="v">{{ $nbProjetsEnCours }}</div><div class="l">Projets en cours</div></div>
    <div class="stat"><div class="v">{{ number_format($caDouzeMois, 0, ',', ' ') }} €</div><div class="l">CA sur 12 mois</div></div>
    <div class="stat"><div class="v">{{ $mesDemandesEnAttente }}</div><div class="l">Mes demandes en attente</div></div>
    @if ($demandesARecevoir > 0)
        <div class="stat"><div class="v">{{ $demandesARecevoir }}</div><div class="l">Demandes à valider (manager)</div></div>
    @endif
    <div class="stat"><div class="v">{{ $tachesAFaire }}</div><div class="l"><a href="{{ route('taches.index') }}">Mes tâches en cours</a></div></div>
    @if ($deadlinesAFixer > 0)
        <div class="stat"><div class="v" style="color:var(--wait)">{{ $deadlinesAFixer }}</div><div class="l"><a href="{{ route('taches.index') }}">Deadlines à fixer (chef de projet)</a></div></div>
    @endif
    @if ($demandesEnRetard > 0)
        <div class="stat"><div class="v" style="color:var(--ko)">{{ $demandesEnRetard }}</div><div class="l"><a href="{{ route('demandes.index', ['retard' => 1]) }}">Demandes en retard</a></div></div>
    @endif
</div>

<div class="card">
    <div class="inline" style="justify-content:space-between">
        <h2>Mes dernières demandes</h2>
        <a class="btn" href="{{ route('demandes.create') }}">+ Nouvelle demande</a>
    </div>
    @forelse ($dernieresDemandes as $d)
        @if ($loop->first)<table><tr><th>Objet</th><th>Manager</th><th>Envoyée le</th><th>Statut</th></tr>@endif
        <tr>
            <td><a href="{{ route('demandes.show', $d) }}">{{ $d->objet }}</a></td>
            <td>{{ $d->manager?->nom_complet }}</td>
            <td>{{ $d->envoyee_at?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</td>
            <td><span class="badge b-{{ $d->statut }}">{{ $d->statut_libelle }}</span></td>
        </tr>
        @if ($loop->last)</table>@endif
    @empty
        <p class="muted">Aucune demande pour le moment.</p>
    @endforelse
</div>
@endsection
