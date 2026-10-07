@extends('layouts.app')
@section('title', 'Créer un compte')

@section('content')
<div class="card auth" style="max-width:600px">
    <h1>Créer un compte</h1>
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <div class="row">
            <div>
                <label for="prenom">Prénom</label>
                <input id="prenom" name="prenom" value="{{ old('prenom') }}" required>
                @error('prenom')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="nom">Nom</label>
                <input id="nom" name="nom" value="{{ old('nom') }}" required>
                @error('nom')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="row">
            <div>
                <label for="email">E-mail professionnel</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required>
                @error('email')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="telephone">Téléphone</label>
                <input id="telephone" name="telephone" value="{{ old('telephone') }}">
                @error('telephone')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="row">
            <div>
                <label for="role_id">Rôle</label>
                <select id="role_id" name="role_id" required>
                    <option value="">— Choisir —</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->libelle }}</option>
                    @endforeach
                </select>
                @error('role_id')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="manager_id">Manager</label>
                <select id="manager_id" name="manager_id">
                    <option value="">— Aucun —</option>
                    @foreach ($managers as $m)
                        <option value="{{ $m->id }}" @selected(old('manager_id') == $m->id)>{{ $m->nom_complet }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row">
            <div>
                <label for="password">Mot de passe (8 caractères min.)</label>
                <input id="password" type="password" name="password" required>
                @error('password')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="password_confirmation">Confirmation</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required>
            </div>
        </div>

        <p><button class="btn" type="submit">Créer mon compte</button></p>
    </form>
    <p class="muted">Déjà inscrit ? <a href="{{ route('login') }}">Se connecter</a></p>
</div>
@endsection
