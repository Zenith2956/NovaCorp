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
    @if (in_array($demande->statut, ['refusee', 'a_completer'], true) && $demande->commentaire_decision)
        <div class="alert {{ $demande->statut === 'refusee' ? 'ko' : 'info' }}">
            <strong>{{ $demande->statut === 'refusee' ? 'Motif du refus' : 'Complément demandé' }} :</strong>
            <span style="white-space:pre-line">{{ $demande->commentaire_decision }}</span>
        </div>
    @endif

    {{-- Circuit de validation --}}
    <h2>Circuit de validation</h2>
    <ol class="circuit-etapes">
        @foreach ($etapes as $etape)
            @php
                $applique = in_array($etape->ordre, $applicables, true);
                $etat = match (true) {
                    ! $applique => 'sautee',
                    in_array($demande->statut, ['validee', 'en_traitement', 'terminee'], true) || $etape->ordre < $demande->etape => 'faite',
                    $etape->ordre === $demande->etape && in_array($demande->statut, ['en_attente', 'a_completer'], true) => 'courante',
                    $etape->ordre === $demande->etape && $demande->statut === 'refusee' => 'refusee',
                    default => 'a_venir',
                };
            @endphp
            <li class="e-{{ $etat }}">
                <strong>{{ $etape->libelle }}</strong>
                @if ($etape->valideur === 'manager') <span class="muted">– {{ $demande->manager?->nom_complet }}</span>@endif
                @if ($etape->description_condition) <span class="muted">({{ $etape->description_condition }})</span>@endif
                <br><span class="muted" style="font-size:.82rem">{{ ['faite' => 'Validée', 'courante' => 'En cours', 'refusee' => 'Refusée', 'sautee' => 'Non concernée', 'a_venir' => 'À venir'][$etat] }}</span>
            </li>
        @endforeach
        @if ($roleTraitement)
            <li class="e-{{ $demande->statut === 'terminee' ? 'faite' : ($demande->statut === 'en_traitement' ? 'courante' : 'a_venir') }}">
                <strong>Traitement</strong> <span class="muted">– {{ \App\Models\Role::where('slug', $roleTraitement)->value('libelle') }}</span>
                <br><span class="muted" style="font-size:.82rem">{{ $demande->traitePar ? 'par '.$demande->traitePar->nom_complet : '' }}
                    {{ $demande->traite_at ? 'le '.$demande->traite_at->timezone('Europe/Paris')->format('d/m/Y') : '' }}</span>
            </li>
        @endif
    </ol>

    <table style="margin-top:1rem">
        <tr><th style="width:200px">Type</th><td>{{ $demande->type_libelle }}</td></tr>
        @if ($demande->montant !== null)<tr><th>Montant</th><td>{{ number_format((float) $demande->montant, 2, ',', ' ') }} €</td></tr>@endif
        @if ($demande->date_debut)<tr><th>Congé</th><td>du {{ $demande->date_debut->format('d/m/Y') }} au {{ $demande->date_fin?->format('d/m/Y') }} ({{ $demande->nb_jours_ouvres }} j ouvrés)</td></tr>@endif
        <tr><th>Demandeur</th><td>{{ $demande->demandeur->nom_complet }} ({{ $demande->demandeur->role?->libelle }}) – {{ $demande->demandeur->email }} – {{ $demande->demandeur->telephone }}</td></tr>
        <tr><th>Manager</th><td>{{ $demande->manager?->nom_complet }} – {{ $demande->manager?->email }} – {{ $demande->manager?->telephone }}</td></tr>
        <tr><th>Envoyée le</th><td>{{ ($demande->envoyee_at ?? $demande->created_at)?->timezone('Europe/Paris')->format('d/m/Y à H:i') }}</td></tr>
        @if ($demande->date_souhaitee)<tr><th>Date souhaitée</th><td>{{ $demande->date_souhaitee->format('d/m/Y') }}</td></tr>@endif
        @if (in_array($demande->statut, ['en_attente', 'a_completer'], true))
        <tr><th>Délais (étape en cours)</th><td>
            Relance le {{ $demande->relance_le?->format('d/m/Y') ?? '–' }} ·
            échéance le <strong>{{ $demande->echeance_le?->format('d/m/Y') ?? '–' }}</strong> ·
            expiration après le {{ $demande->deadline?->format('d/m/Y') ?? '–' }}
        </td></tr>
        @endif
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

@if ($actions)
<div class="card">
    <h2>Actions</h2>
    <form method="POST" action="{{ route('demandes.action', $demande) }}">
        @csrf
        @if (array_intersect($actions, ['refuser', 'demander_complement', 'completer']))
            <label for="commentaire">{{ in_array('completer', $actions, true) ? 'Complément apporté' : 'Commentaire' }}
                @unless (in_array('completer', $actions, true))<span class="muted" style="font-weight:normal">(obligatoire pour refuser ou demander un complément)</span>@endunless</label>
            <textarea id="commentaire" name="commentaire" rows="3">{{ old('commentaire') }}</textarea>
            @error('commentaire')<div class="err">{{ $message }}</div>@enderror
        @endif
        <p class="inline" style="margin-top:1rem">
            @foreach ($actions as $action)
                <button class="btn {{ in_array($action, ['valider', 'completer', 'prendre_en_charge', 'terminer'], true) ? '' : 'sec' }}"
                        name="action" value="{{ $action }}"
                        @if (in_array($action, ['annuler', 'corriger'], true)) onclick="return confirm('Confirmer : {{ \App\Services\WorkflowDemande::ACTIONS[$action] }} ?')" @endif>
                    {{ \App\Services\WorkflowDemande::ACTIONS[$action] }}</button>
            @endforeach
        </p>
    </form>
</div>
@endif

<div class="card">
    <h2>Historique</h2>
    <table>
        <tr><th>Date</th><th>Changement</th><th>Par</th><th>Canal</th><th>Commentaire</th></tr>
        @forelse ($demande->historique as $h)
            <tr>
                <td>{{ $h->created_at?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</td>
                <td>
                    @if ($h->ancien_statut === null) Création
                    @elseif ($h->ancien_statut === $h->nouveau_statut) Étape {{ $h->etape }} ({{ $etapes->firstWhere('ordre', $h->etape)?->libelle }})
                    @else {{ \App\Models\Demande::STATUTS[$h->ancien_statut] ?? $h->ancien_statut }} → <span class="badge b-{{ $h->nouveau_statut }}">{{ \App\Models\Demande::STATUTS[$h->nouveau_statut] ?? $h->nouveau_statut }}</span>
                    @endif
                </td>
                <td>{{ $h->auteur?->nom_complet ?? 'Système' }}</td>
                <td>{{ \App\Models\Demande::CANAUX[$h->canal] ?? $h->canal }}</td>
                <td style="white-space:pre-line">{{ $h->commentaire }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Aucun événement enregistré.</td></tr>
        @endforelse
    </table>
</div>
@endsection
