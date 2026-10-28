@extends('layouts.app')
@section('title', 'Activer mon compte')

@section('content')
<div class="card auth">
    @if ($user)
        <h1>Bienvenue {{ $user->prenom }} !</h1>
        <p class="muted">Choisissez le mot de passe de votre compte <strong>{{ $user->email }}</strong>
            (8 caractères minimum, avec au moins une lettre et un chiffre).</p>
        <form method="POST" action="{{ route('activation.store', $jeton) }}">
            @csrf
            <label for="password">Mot de passe</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="new-password">
            @error('password')<div class="err">{{ $message }}</div>@enderror

            <label for="password_confirmation">Confirmation</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

            <p><button class="btn" type="submit">Activer mon compte</button></p>
        </form>
    @else
        <h1>Lien expiré</h1>
        <p>Ce lien d'activation n'est plus valable (déjà utilisé, remplacé par un plus récent, ou plus de
            {{ \App\Models\User::VALIDITE_ACTIVATION_JOURS }} jours). Demandez un nouveau lien à votre administrateur.</p>
        <p><a class="btn sec" href="{{ route('login') }}">Aller à la connexion</a></p>
    @endif
</div>
@endsection
