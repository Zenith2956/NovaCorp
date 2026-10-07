@extends('layouts.app')
@section('title', 'Connexion')

@section('content')
<div class="card auth">
    <h1>Connexion à NovaCorp</h1>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Adresse e-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        @error('email')<div class="err">{{ $message }}</div>@enderror

        <label for="password">Mot de passe</label>
        <input id="password" type="password" name="password" required>

        <label class="inline"><input type="checkbox" name="remember"> Se souvenir de moi</label>

        <p><button class="btn" type="submit">Se connecter</button></p>
    </form>
    <p class="muted">Pas encore de compte ? <a href="{{ route('register') }}">Créer un compte</a></p>
</div>
@endsection
