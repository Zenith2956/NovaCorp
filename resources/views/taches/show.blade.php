@extends('layouts.app')
@section('title', 'Tâche – '.$tache->titre)
@section('retour', $tache->projet ? route('projets.show', $tache->projet) : route('taches.index'))

@section('content')
<div class="card">
    <div class="inline" style="justify-content:space-between">
        <h1>{{ $tache->titre }}</h1>
        <span>@include('taches._badge')</span>
    </div>
    <table>
        <tr><th style="width:200px">Projet</th><td>@if ($tache->projet)<a href="{{ route('projets.show', $tache->projet) }}">{{ $tache->projet->nom }}</a> (chef : {{ $tache->projet->chefProjet?->nom_complet }})@else Hors projet @endif</td></tr>
        <tr><th>Responsable</th><td>{{ $tache->responsable->nom_complet }}</td></tr>
        <tr><th>Créée par</th><td>{{ $tache->createur?->nom_complet }} le {{ $tache->created_at?->timezone('Europe/Paris')->format('d/m/Y') }}</td></tr>
        <tr><th>Deadline</th><td>
            @if ($tache->deadline)
                <strong>{{ $tache->deadline->format('d/m/Y') }}</strong>
                @if ($tache->nb_reports) <span class="muted">— initialement le {{ $tache->deadline_initiale?->format('d/m/Y') }}, {{ $tache->nb_reports }} report{{ $tache->nb_reports > 1 ? 's' : '' }}</span>@endif
            @else
                <span class="muted">À fixer par le chef de projet</span>
            @endif
        </td></tr>
        @if ($tache->termine_at)<tr><th>Terminée le</th><td>{{ $tache->termine_at->timezone('Europe/Paris')->format('d/m/Y à H:i') }}</td></tr>@endif
    </table>
    @if ($tache->description)<p style="white-space:pre-line;margin-top:1rem">{{ $tache->description }}</p>@endif
</div>

@if ($peutChangerStatut)
<div class="card">
    <h2>Avancement</h2>
    <form class="inline" method="POST" action="{{ route('taches.statut', $tache) }}">
        @csrf @method('PATCH')
        <select name="statut">
            @foreach (\App\Models\Tache::STATUTS_MANUELS as $k)
                <option value="{{ $k }}" @selected($tache->statut === $k)>{{ \App\Models\Tache::STATUTS[$k] }}</option>
            @endforeach
        </select>
        <button class="btn">Enregistrer</button>
    </form>
</div>
@endif

@if ($peutModifierDeadline)
<div class="card">
    <h2>{{ $tache->deadline ? ($tache->projet_id ? 'Repousser la deadline' : 'Modifier la deadline') : 'Fixer la deadline' }}</h2>
    <p class="muted">
        @if (! $tache->projet_id)
            Votre manager sera prévenu par mail du changement.
        @elseif (! $tache->deadline)
            Au plus tôt le {{ $minimum->format('d/m/Y') }} (création + 5 jours ouvrés). Le responsable est prévenu par mail.
        @else
            Une deadline de projet ne peut être que repoussée : au plus tôt le {{ $minimum->format('d/m/Y') }}. Le responsable est prévenu par mail.
        @endif
    </p>
    <form class="inline" method="POST" action="{{ route('taches.deadline', $tache) }}">
        @csrf @method('PATCH')
        <input type="date" name="deadline" min="{{ $minimum->toDateString() }}" value="{{ old('deadline') }}" required style="max-width:220px">
        <button class="btn">Enregistrer</button>
    </form>
    @error('deadline')<div class="err">{{ $message }}</div>@enderror
</div>
@endif
@endsection
