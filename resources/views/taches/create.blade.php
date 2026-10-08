@extends('layouts.app')
@section('title', 'Nouvelle tâche')
@section('retour', $projet ? route('projets.show', $projet) : route('taches.index'))

@section('content')
<div class="card">
    <h1>{{ $projet ? 'Nouvelle tâche – '.$projet->nom : 'Nouvelle tâche hors projet' }}</h1>
    <p class="muted">
        @if (! $projet)
            Vous choisissez librement la deadline. Si vous la modifiez ensuite, votre manager en est prévenu par mail.
        @elseif ($estChef)
            En tant que chef de projet, vous assignez la tâche à un membre et fixez sa deadline (au plus tôt le {{ $minimumProjet->format('d/m/Y') }}, soit création + 5 jours ouvrés).
        @else
            La tâche vous est attribuée. {{ $projet->chefProjet?->nom_complet ?? 'Le chef de projet' }} reçoit un mail pour fixer la deadline (au moins 5 jours ouvrés après la création).
        @endif
    </p>

    <form method="POST" action="{{ route('taches.store') }}">
        @csrf
        @if ($projet)<input type="hidden" name="projet_id" value="{{ $projet->id }}">@endif

        <label for="titre">Titre</label>
        <input id="titre" name="titre" value="{{ old('titre') }}" required>
        @error('titre')<div class="err">{{ $message }}</div>@enderror

        <label for="description">Description (facultatif)</label>
        <textarea id="description" name="description" style="min-height:90px">{{ old('description') }}</textarea>

        @if ($estChef)
            <label for="responsable_id">Responsable</label>
            <select id="responsable_id" name="responsable_id" required>
                @foreach ($projet->membres->sortBy('nom') as $m)
                    <option value="{{ $m->id }}" @selected(old('responsable_id') == $m->id)>{{ $m->nom_complet }}</option>
                @endforeach
                <option value="{{ $projet->chef_projet_id }}" @selected(old('responsable_id') == $projet->chef_projet_id)>Moi-même</option>
            </select>
            @error('responsable_id')<div class="err">{{ $message }}</div>@enderror
        @endif

        @if (! $projet || $estChef)
            <label for="deadline">Deadline</label>
            <input id="deadline" type="date" name="deadline" required style="max-width:220px" value="{{ old('deadline') }}"
                   min="{{ ($projet ? $minimumProjet : $aujourdhui)->toDateString() }}">
            @error('deadline')<div class="err">{{ $message }}</div>@enderror
        @endif

        <p><button class="btn">Créer la tâche</button>
           <a class="btn sec" href="{{ $projet ? route('projets.show', $projet) : route('taches.index') }}">Annuler</a></p>
    </form>
</div>
@endsection
