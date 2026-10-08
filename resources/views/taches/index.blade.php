@extends('layouts.app')
@section('title', 'Tâches')

@section('content')
<div class="inline" style="justify-content:space-between">
    <h1>Mes tâches</h1>
    <a class="btn" href="{{ route('taches.create') }}">+ Tâche hors projet</a>
</div>

<div class="card">
    <form class="inline" method="GET">
        <label class="inline" style="margin:0;font-weight:normal"><input type="checkbox" name="toutes" value="1" @checked(request()->boolean('toutes')) onchange="this.form.submit()"> Afficher aussi les tâches terminées</label>
    </form>
    <table style="margin-top:1rem">
        <tr><th>Tâche</th><th>Projet</th><th>Deadline</th><th>Statut</th></tr>
        @forelse ($mesTaches as $tache)
            <tr>
                <td><a href="{{ route('taches.show', $tache) }}">{{ $tache->titre }}</a></td>
                <td>{{ $tache->projet?->nom ?? 'Hors projet' }}</td>
                <td>{{ $tache->deadline?->format('d/m/Y') ?? '—' }}@if ($tache->nb_reports) <small class="muted">({{ $tache->nb_reports }} report{{ $tache->nb_reports > 1 ? 's' : '' }})</small>@endif</td>
                <td>@include('taches._badge')</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Aucune tâche.</td></tr>
        @endforelse
    </table>
</div>

@if ($tachesProjets->isNotEmpty())
<h1>Tâches de mes projets (chef de projet)</h1>
<div class="card">
    <table>
        <tr><th>Tâche</th><th>Projet</th><th>Responsable</th><th>Deadline</th><th>Statut</th></tr>
        @foreach ($tachesProjets as $tache)
            <tr>
                <td><a href="{{ route('taches.show', $tache) }}">{{ $tache->titre }}</a></td>
                <td><a href="{{ route('projets.show', $tache->projet) }}">{{ $tache->projet->nom }}</a></td>
                <td>{{ $tache->responsable->nom_complet }}</td>
                <td>{{ $tache->deadline?->format('d/m/Y') ?? '—' }}</td>
                <td>@include('taches._badge')</td>
            </tr>
        @endforeach
    </table>
</div>
@endif
@endsection
