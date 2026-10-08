@extends('layouts.app')
@section('title', 'Décision sur la demande #'.$demande->id)

@section('content')
<div class="card auth" style="max-width:640px">
    <h1>Demande #{{ $demande->id }} – {{ $demande->objet }}</h1>
    <p><strong>{{ $demande->demandeur->nom_complet }}</strong> · {{ $demande->type_libelle }}
       · envoyée le {{ ($demande->envoyee_at ?? $demande->created_at)?->format('d/m/Y') }}</p>
    <p style="white-space:pre-line">{{ $demande->message }}</p>
    @if ($demande->piecesJointes->isNotEmpty())
        <p class="muted">{{ $demande->piecesJointes->count() }} pièce(s) jointe(s), visibles dans l'application.</p>
    @endif

    <form method="POST" action="{{ route('decision.store', [$demande, $jeton]) }}" class="inline" style="margin-top:1rem">
        @csrf
        <button class="btn" name="choix" value="validee" @if($choix === 'refusee') style="opacity:.6" @endif>Valider la demande</button>
        <button class="btn sec" name="choix" value="refusee" @if($choix === 'validee') style="opacity:.6" @endif>Refuser la demande</button>
    </form>
    <p class="muted" style="margin-top:1rem">L'employé sera prévenu par mail de votre décision.</p>
</div>
@endsection
