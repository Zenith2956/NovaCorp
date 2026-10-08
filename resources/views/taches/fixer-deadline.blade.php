@extends('layouts.app')
@section('title', 'Fixer la deadline')

@section('content')
<div class="card auth" style="max-width:640px">
    @if (! empty($fixee))
        <h1>Deadline enregistrée</h1>
        <p>La tâche « {{ $tache->titre }} » doit être terminée le <strong>{{ $tache->deadline?->format('d/m/Y') }}</strong>.
           {{ $tache->responsable->prenom }} va recevoir un mail.</p>
    @elseif (! $valide)
        <h1>Lien expiré</h1>
        <p>La deadline de cette tâche a déjà été fixée{{ $tache->deadline ? ' au '.$tache->deadline->format('d/m/Y') : '' }}.
           Pour la repousser, passez par l'application.</p>
    @else
        <h1>Fixer la deadline</h1>
        <p><strong>{{ $tache->titre }}</strong> · projet {{ $tache->projet->nom }} · responsable {{ $tache->responsable->nom_complet }}</p>
        @if ($tache->description)<p style="white-space:pre-line" class="muted">{{ $tache->description }}</p>@endif
        <form method="POST" action="{{ route('taches.jeton.store', [$tache, $jeton]) }}">
            @csrf
            <label for="deadline">Deadline (au plus tôt le {{ $minimum->format('d/m/Y') }} : création + 5 jours ouvrés)</label>
            <input id="deadline" type="date" name="deadline" min="{{ $minimum->toDateString() }}" value="{{ old('deadline') }}" required style="max-width:220px">
            @error('deadline')<div class="err">{{ $message }}</div>@enderror
            <p><button class="btn">Enregistrer la deadline</button></p>
        </form>
    @endif
    <p><a href="{{ route('login') }}">Ouvrir NovaCorp</a></p>
</div>
@endsection
