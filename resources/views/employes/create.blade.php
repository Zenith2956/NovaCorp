@extends('layouts.app')
@section('title', 'Ajouter un employé')
@section('retour', route('employes.index'))

@section('content')
<div class="card" style="max-width:720px">
    <h1>Ajouter un employé</h1>
    <p class="muted">Le compte est créé sans mot de passe. L'employé reçoit le mail de bienvenue avec un lien
        pour choisir son mot de passe (valable {{ \App\Models\User::VALIDITE_ACTIVATION_JOURS }} jours) ; son manager est en copie.</p>
    <form method="POST" action="{{ route('employes.store') }}">
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
                @error('manager_id')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>
        <p><button class="btn" type="submit">Créer le compte et envoyer l'invitation</button></p>
    </form>
</div>
@endsection
