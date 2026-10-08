@extends('layouts.app')
@section('title', 'Demande #'.$demande->id)
@section('retour', route('demandes.index'))

@section('content')
<div class="card">
    <div class="inline" style="justify-content:space-between">
        <h1>Demande #{{ $demande->id }} – {{ $demande->objet }}</h1>
        <span>
            @if ($demande->urgente)<span class="badge b-urgente">Urgente</span>@endif
            @if ($demande->indicateur_delai)<span class="badge i-{{ $demande->indicateur_delai }}">{{ \App\Models\Demande::INDICATEURS[$demande->indicateur_delai] }}</span>@endif
            <span class="badge b-{{ $demande->statut }}">{{ $demande->statut_libelle }}</span>
        </span>
    </div>

    @if ($demande->statut === 'expiree' && auth()->id() === $demande->demandeur_id)
        <div class="alert ko inline" style="justify-content:space-between">
            <span>Cette demande a expiré le {{ $demande->deadline?->format('d/m/Y') }} sans réponse.</span>
            <a class="btn" href="{{ route('demandes.create', ['refaire' => $demande->id]) }}">Refaire la demande</a>
        </div>
    @endif

    <table>
        <tr><th style="width:200px">Type</th><td>{{ $demande->type_libelle }}</td></tr>
        <tr><th>Demandeur</th><td>{{ $demande->demandeur->nom_complet }} ({{ $demande->demandeur->role?->libelle }}) – {{ $demande->demandeur->email }} – {{ $demande->demandeur->telephone }}</td></tr>
        <tr><th>Manager</th><td>{{ $demande->manager?->nom_complet }} – {{ $demande->manager?->email }} – {{ $demande->manager?->telephone }}</td></tr>
        <tr><th>Envoyée le</th><td>{{ $demande->envoyee_at?->timezone('Europe/Paris')->format('d/m/Y à H:i') ?? 'Non envoyée' }}</td></tr>
        @if ($demande->date_souhaitee)<tr><th>Date souhaitée</th><td>{{ $demande->date_souhaitee->format('d/m/Y') }}</td></tr>@endif
        <tr><th>Délais</th><td>
            Relance le {{ $demande->relance_le?->format('d/m/Y') ?? '–' }} ·
            échéance le <strong>{{ $demande->echeance_le?->format('d/m/Y') ?? '–' }}</strong> ·
            expiration après le {{ $demande->deadline?->format('d/m/Y') ?? '–' }}
        </td></tr>
        @if ($demande->decision_at)
            <tr><th>Décision</th><td>le {{ $demande->decision_at->timezone('Europe/Paris')->format('d/m/Y à H:i') }}
                ({{ $demande->delai_traitement }} j ouvrés{{ $demande->traitee_dans_les_temps === false ? ', après l\'échéance' : '' }})</td></tr>
        @endif
    </table>

    <h2 style="margin-top:1.5rem">Message</h2>
    <p style="white-space:pre-line">{{ $demande->message }}</p>

    @if ($demande->piecesJointes->isNotEmpty())
        <h2>Pièces jointes</h2>
        <ul>
            @foreach ($demande->piecesJointes as $pj)
                <li>
                    <a href="{{ route('pieces-jointes.download', $pj) }}">{{ $pj->nom_original }}</a>
                    <span class="muted">({{ $pj->categorie }}, {{ number_format($pj->taille / 1024, 0, ',', ' ') }} Ko)</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>

@if (($demande->statut !== 'expiree' && auth()->id() === $demande->manager_id) || auth()->user()->hasRole('rh', 'admin'))
<div class="card">
    <h2>Mise à jour manuelle du statut</h2>
    <p class="muted">À renseigner après la réponse du manager par mail ou après la relance téléphonique.</p>
    <form class="inline" method="POST" action="{{ route('demandes.statut', $demande) }}">
        @csrf @method('PATCH')
        <select name="statut">
            @foreach (\App\Models\Demande::STATUTS_MANUELS as $k)
                <option value="{{ $k }}" @selected($demande->statut === $k)>{{ \App\Models\Demande::STATUTS[$k] }}</option>
            @endforeach
        </select>
        <button class="btn">Enregistrer</button>
    </form>
</div>
@endif
@endsection
