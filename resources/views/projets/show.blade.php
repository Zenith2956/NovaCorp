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

<div class="card">
    <h2>Tâches ({{ $projet->taches->count() }})</h2>
    <table>
        <tr><th>Tâche</th><th>Responsable</th><th>Deadline</th><th>Statut</th></tr>
        @forelse ($projet->taches->sortBy(fn ($t) => $t->deadline?->timestamp ?? 0) as $tache)
            <tr>
                <td><a href="{{ route('taches.show', $tache) }}">{{ $tache->titre }}</a></td>
                <td>{{ $tache->responsable->nom_complet }}</td>
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
                    <td><form method="POST" action="{{ route('projets.membres.destroy', [$projet, $m]) }}">@csrf @method('DELETE')<button class="btn sec">Retirer</button></form></td>
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
