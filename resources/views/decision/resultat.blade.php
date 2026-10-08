@extends('layouts.app')
@section('title', 'Décision')

@section('content')
<div class="card auth" style="max-width:640px">
    @if ($erreur)
        <h1>Lien expiré</h1>
        <p>Ce lien n'est plus valable : la demande #{{ $demande->id }} a déjà été traitée ou a changé d'étape
           (statut actuel : <strong>{{ $demande->statut_libelle }}</strong>).</p>
    @else
        <h1>Décision enregistrée</h1>
        <p>{{ $message ?? '' }}</p>
        <p>La demande #{{ $demande->id }} est <span class="badge b-{{ $demande->statut }}">{{ $demande->statut_libelle }}</span>.
           @if ($demande->statut === 'en_attente') Le valideur de l'étape suivante va recevoir un mail.
           @else {{ $demande->demandeur->prenom }} va recevoir un mail. @endif</p>
    @endif
    <p><a href="{{ route('login') }}">Ouvrir NovaCorp</a></p>
</div>
@endsection
