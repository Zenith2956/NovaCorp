@extends('layouts.app')
@section('title', 'Retirer un membre – '.$projet->nom)
@section('retour', route('projets.show', $projet))

@section('content')
<div class="card" style="max-width:760px">
    <h1>Retirer {{ $membre->nom_complet }} du projet ?</h1>
    <div class="alert pause">
        <strong>⚠️ {{ $membre->prenom }} a encore {{ $taches->count() }} tâche{{ $taches->count() > 1 ? 's' : '' }} non terminée{{ $taches->count() > 1 ? 's' : '' }} dans « {{ $projet->nom }} ».</strong><br>
        Si vous confirmez, {{ $taches->count() > 1 ? 'ces tâches passent' : 'cette tâche passe' }} <strong>en pause</strong> :
        plus de relances ni d'expiration, et {{ $taches->count() > 1 ? 'elles restent affichées' : 'elle reste affichée' }} en haut du projet et sur votre tableau de bord
        jusqu'à ce que vous {{ $taches->count() > 1 ? 'les confiiez' : 'la confiiez' }} à un autre membre. Sans réattribution sous 3 jours ouvrés, vous recevez un rappel par mail.
    </div>
    <table>
        <tr><th>Tâche</th><th>Deadline</th><th>Statut</th></tr>
        @foreach ($taches as $tache)
            <tr>
                <td><a href="{{ route('taches.show', $tache) }}">{{ $tache->titre }}</a></td>
                <td>{{ $tache->deadline?->format('d/m/Y') ?? '—' }}</td>
                <td>@include('taches._badge')</td>
            </tr>
        @endforeach
    </table>
    <form method="POST" action="{{ route('projets.membres.destroy', [$projet, $membre]) }}" class="inline" style="margin-top:1.2rem">
        @csrf @method('DELETE')
        <input type="hidden" name="confirmer" value="1">
        <button class="btn" style="background:var(--ko)">Oui, retirer et mettre {{ $taches->count() > 1 ? 'les tâches' : 'la tâche' }} en pause</button>
        <a class="btn sec" href="{{ route('projets.show', $projet) }}">Annuler</a>
    </form>
</div>
@endsection
