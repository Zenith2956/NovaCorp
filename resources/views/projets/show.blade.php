@extends('layouts.app')
@section('title', $projet->nom)
@section('retour', route('projets.index'))

@section('content')
<div class="inline" style="justify-content:space-between">
    <h1>{{ $projet->nom }}</h1>
    <span class="inline">
        @if ($peutGerer)<a class="btn sec" href="{{ route('projets.edit', $projet) }}">Modifier le projet</a>@endif
        @if ($estMembre)<a class="btn" href="{{ route('taches.create', ['projet' => $projet->id]) }}">+ Nouvelle tâche</a>@endif
    </span>
</div>
<p class="muted">{{ $projet->description }} · chef de projet : <strong>{{ $projet->chefProjet?->nom_complet ?? '—' }}</strong></p>
<p class="muted">Réunion : <strong>{{ $projet->description_reunion ?? 'aucune' }}</strong>
    @if ($projet->reunion_frequence && $projet->statut === 'en_cours' && ($prochaine = $projet->prochaineReunion()))
        · prochain récapitulatif le {{ $prochaine->format('d/m/Y') }} à 8 h
    @endif
    @if ($peutGerer && ! $projet->reunion_frequence) · <a href="{{ route('projets.edit', $projet) }}">choisir un jour de réunion</a>@endif
</p>

@php $enPause = $projet->taches->where('statut', 'en_pause'); @endphp
@if ($enPause->isNotEmpty())
<div class="alert pause">
    <h2 style="margin-top:0">⏸ {{ $enPause->count() }} tâche{{ $enPause->count() > 1 ? 's' : '' }} en pause à réattribuer</h2>
    <p style="margin:.2rem 0 .8rem">Leur responsable a été retiré du projet : personne n'y travaille.
        {{ $peutGerer ? 'Confiez-les à un membre (il est prévenu par mail).' : 'Le chef de projet doit les réattribuer.' }}</p>
    @foreach ($enPause as $tache)
        <div style="padding:.6rem 0;border-top:1px solid #ffd8a8">
            <a href="{{ route('taches.show', $tache) }}"><strong>{{ $tache->titre }}</strong></a>
            <span class="muted"> — {{ $tache->pause_motif }}, en pause depuis le {{ $tache->pause_le?->timezone('Europe/Paris')->format('d/m/Y') }}
                · deadline {{ $tache->deadline?->format('d/m/Y') ?? 'à fixer' }}</span>
            @if ($peutGerer)<div style="margin-top:.4rem">@include('taches._reattribuer', ['tache' => $tache])</div>@endif
        </div>
    @endforeach
</div>
@endif

<div class="card">
    <h2>Tâches ({{ $projet->taches->count() }})</h2>
    <table>
        <tr><th>Tâche</th><th>Responsable</th><th>Deadline</th><th>Statut</th></tr>
        @forelse ($projet->taches->sortBy(fn ($t) => $t->deadline?->timestamp ?? 0) as $tache)
            <tr>
                <td><a href="{{ route('taches.show', $tache) }}">{{ $tache->titre }}</a></td>
                <td>{{ $tache->statut === 'en_pause' ? '—' : $tache->responsable->nom_complet }}
                    @if ($tache->statut === 'en_pause')<span class="muted">(était {{ $tache->pauseAncienResponsable?->nom_complet }})</span>@endif</td>
                <td>{{ $tache->deadline?->format('d/m/Y') ?? '—' }}</td>
                <td>@include('taches._badge')</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Aucune tâche.</td></tr>
        @endforelse
    </table>
</div>

<div class="card">
    <h2>Membres ({{ $projet->membres->count() }})</h2>
    <table>
        <tr><th>Nom</th><th>Rôle</th><th>E-mail</th>@if ($peutGerer)<th></th>@endif</tr>
        @foreach ($projet->membres->sortBy('nom') as $m)
            <tr>
                <td>{{ $m->nom_complet }}</td>
                <td>{{ $m->role?->libelle }}</td>
                <td>{{ $m->email }}</td>
                @if ($peutGerer)
                    <td>
                        @if (in_array($m->id, $membresAvecTaches))
                            {{-- R2 : a des tâches non terminées → page d'avertissement avant le retrait --}}
                            <a class="btn sec" href="{{ route('projets.membres.confirmer', [$projet, $m]) }}">Retirer…</a>
                        @else
                            <form method="POST" action="{{ route('projets.membres.destroy', [$projet, $m]) }}">@csrf @method('DELETE')
                                <button class="btn sec" onclick="return confirm('Retirer {{ $m->nom_complet }} du projet ?')">Retirer</button></form>
                        @endif
                    </td>
                @endif
            </tr>
        @endforeach
    </table>

    @if ($peutGerer)
        <h2 style="margin-top:1.5rem">Ajouter des membres</h2>
        <form method="POST" action="{{ route('projets.membres.store', $projet) }}">
            @csrf
            @include('projets._selecteur-membres', ['employes' => $candidats, 'selection' => [], 'nom' => 'user_ids[]'])
            @error('user_ids')<div class="err">{{ $message }}</div>@enderror
            <p><button class="btn">Ajouter les membres cochés</button></p>
        </form>
    @endif
</div>
@endsection
