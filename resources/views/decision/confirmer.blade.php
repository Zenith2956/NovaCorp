@extends('layouts.app')
@section('title', 'Décision sur la demande #'.$demande->id)

@section('content')
<div class="card auth" style="max-width:680px">
    <h1>Demande #{{ $demande->id }} – {{ $demande->objet }}</h1>
    <p><strong>{{ $demande->demandeur->nom_complet }}</strong> · {{ $demande->type_libelle }}
       · envoyée le {{ ($demande->envoyee_at ?? $demande->created_at)?->format('d/m/Y') }}</p>
    @if ($etape)
        <p class="muted">Étape {{ $etape->ordre }} du circuit : <strong>{{ $etape->libelle }}</strong></p>
    @endif
    @include('demandes._details', ['demande' => $demande])
    <p style="white-space:pre-line">{{ $demande->message }}</p>
    @if ($demande->piecesJointes->isNotEmpty())
        <p class="muted">{{ $demande->piecesJointes->count() }} pièce(s) jointe(s), visibles dans l'application.</p>
    @endif

    <form method="POST" action="{{ route('decision.store', [$demande, $jeton]) }}" style="margin-top:1rem">
        @csrf
        <label for="commentaire">Commentaire <span class="muted" style="font-weight:normal">(obligatoire pour refuser ou demander un complément)</span></label>
        <textarea id="commentaire" name="commentaire" rows="3">{{ old('commentaire') }}</textarea>
        @error('commentaire')<div class="err">{{ $message }}</div>@enderror
        <p class="inline" style="margin-top:1rem">
            <button class="btn" name="choix" value="validee" @if($choix && $choix !== 'validee') style="opacity:.6" @endif>Valider</button>
            <button class="btn sec" name="choix" value="a_completer" @if($choix && $choix !== 'a_completer') style="opacity:.6" @endif>Demander un complément</button>
            <button class="btn sec" name="choix" value="refusee" @if($choix && $choix !== 'refusee') style="opacity:.6" @endif>Refuser</button>
        </p>
    </form>
    <p class="muted" style="margin-top:1rem">L'employé (ou le valideur de l'étape suivante) sera prévenu par mail. La décision est inscrite dans l'historique de la demande.</p>
</div>
@endsection
