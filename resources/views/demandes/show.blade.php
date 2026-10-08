@extends('layouts.app')
@section('title', 'Demande #'.$demande->id)

@section('content')
<div class="card">
    <div class="inline" style="justify-content:space-between">
        <h1>Demande #{{ $demande->id }} – {{ $demande->objet }}</h1>
        <span class="badge b-{{ $demande->statut }}">{{ $demande->statut_libelle }}</span>
    </div>

    <table>
        <tr><th style="width:200px">Type</th><td>{{ $demande->type_libelle }}</td></tr>
        <tr><th>Demandeur</th><td>{{ $demande->demandeur->nom_complet }} ({{ $demande->demandeur->role?->libelle }}) – {{ $demande->demandeur->email }} – {{ $demande->demandeur->telephone }}</td></tr>
        <tr><th>Manager</th><td>{{ $demande->manager?->nom_complet }} – {{ $demande->manager?->email }} – {{ $demande->manager?->telephone }}</td></tr>
        <tr><th>Envoyée le</th><td>{{ $demande->envoyee_at?->timezone('Europe/Paris')->format('d/m/Y à H:i') ?? 'Non envoyée' }}</td></tr>
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

@if (auth()->id() === $demande->manager_id || auth()->user()->hasRole('rh', 'admin'))
<div class="card">
    <h2>Mise à jour manuelle du statut</h2>
    <p class="muted">À renseigner après la réponse du manager par mail ou après la relance téléphonique.</p>
    <form class="inline" method="POST" action="{{ route('demandes.statut', $demande) }}">
        @csrf @method('PATCH')
        <select name="statut">
            @foreach (\App\Models\Demande::STATUTS as $k => $v)
                <option value="{{ $k }}" @selected($demande->statut === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <button class="btn">Enregistrer</button>
    </form>
</div>
@endif
@endsection
