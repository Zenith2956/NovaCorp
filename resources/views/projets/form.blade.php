@extends('layouts.app')
@section('title', $projet->exists ? 'Modifier le projet' : 'Nouveau projet')
@section('retour', $projet->exists ? route('projets.show', $projet) : route('projets.index'))

@section('content')
<div class="card">
    <h1>{{ $projet->exists ? 'Modifier « '.$projet->nom.' »' : 'Nouveau projet' }}</h1>
    @unless ($choisitChef)
        <p class="muted">{{ $projet->exists ? '' : 'Vous serez le chef de projet : vous fixerez les deadlines des tâches et gérerez les membres.' }}</p>
    @endunless

    <form method="POST" action="{{ $projet->exists ? route('projets.update', $projet) : route('projets.store') }}">
        @csrf
        @if ($projet->exists) @method('PUT') @endif

        <label for="nom">Nom du projet</label>
        <input id="nom" name="nom" value="{{ old('nom', $projet->nom) }}" required>
        @error('nom')<div class="err">{{ $message }}</div>@enderror

        <label for="description">Description</label>
        <textarea id="description" name="description" style="min-height:90px">{{ old('description', $projet->description) }}</textarea>

        <div class="row">
            <div>
                <label for="statut">Statut</label>
                <select id="statut" name="statut">
                    @foreach ($statuts as $k => $v)
                        <option value="{{ $k }}" @selected(old('statut', $projet->statut) === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="budget">Budget (€)</label>
                <input id="budget" type="number" min="0" step="100" name="budget" value="{{ old('budget', $projet->budget) }}">
                @error('budget')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="row">
            <div>
                <label for="date_debut">Début</label>
                <input id="date_debut" type="date" name="date_debut" value="{{ old('date_debut', $projet->date_debut?->toDateString()) }}">
            </div>
            <div>
                <label for="date_fin">Fin prévue</label>
                <input id="date_fin" type="date" name="date_fin" value="{{ old('date_fin', $projet->date_fin?->toDateString()) }}">
                @error('date_fin')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <h2 style="margin-top:1.5rem">Réunion du projet</h2>
        <p class="muted" style="font-size:.88rem">Le jour de la réunion à 8 h, le chef de projet et les membres reçoivent un récapitulatif
            du projet depuis la réunion précédente (le chef reçoit aussi les demandes de son équipe). Jour férié : pas de récapitulatif.</p>
        <div class="row">
            <div>
                <label for="reunion_frequence">Rythme</label>
                <select id="reunion_frequence" name="reunion_frequence">
                    <option value="">Pas de réunion</option>
                    @foreach (\App\Models\Projet::FREQUENCES as $k => $v)
                        <option value="{{ $k }}" @selected(old('reunion_frequence', $projet->reunion_frequence) === $k)>{{ $v }}</option>
                    @endforeach
                </select>
                @error('reunion_frequence')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="reunion-jour">
                <label for="reunion_jour">Jour</label>
                <select id="reunion_jour" name="reunion_jour">
                    @foreach (\App\Models\Projet::JOURS as $k => $v)
                        <option value="{{ $k }}" @selected((int) old('reunion_jour', $projet->reunion_jour ?? 1) === $k)>{{ ucfirst($v) }}</option>
                    @endforeach
                </select>
                @error('reunion_jour')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="reunion-depuis">
                <label for="reunion_depuis">Prochaine réunion le</label>
                <input id="reunion_depuis" type="date" name="reunion_depuis" value="{{ old('reunion_depuis', $projet->reunion_depuis?->toDateString()) }}">
                @error('reunion_depuis')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>
        <script>
            (function () {
                const f = document.getElementById('reunion_frequence');
                function maj() {
                    document.querySelector('.reunion-jour').style.display = ['hebdomadaire', 'mensuelle'].includes(f.value) ? '' : 'none';
                    document.querySelector('.reunion-depuis').style.display = f.value === 'bimensuelle' ? '' : 'none';
                }
                f.addEventListener('change', maj); maj();
            })();
        </script>

        @if ($choisitChef)
            <label for="chef_projet_id">Chef de projet</label>
            <select id="chef_projet_id" name="chef_projet_id" required>
                <option value="">— Choisir —</option>
                @foreach ($chefs as $c)
                    <option value="{{ $c->id }}" @selected(old('chef_projet_id', $projet->chef_projet_id) == $c->id)>{{ $c->nom_complet }} ({{ $c->role?->libelle }})</option>
                @endforeach
            </select>
            @error('chef_projet_id')<div class="err">{{ $message }}</div>@enderror
        @endif

        @unless ($projet->exists)
            <label>Membres (modifiable ensuite sur la page du projet)</label>
            @include('projets._selecteur-membres', ['employes' => $employes, 'selection' => old('membres', []), 'nom' => 'membres[]'])
        @endunless

        <p><button class="btn">{{ $projet->exists ? 'Enregistrer' : 'Créer le projet' }}</button>
           <a class="btn sec" href="{{ $projet->exists ? route('projets.show', $projet) : route('projets.index') }}">Annuler</a></p>
    </form>
</div>
@endsection
