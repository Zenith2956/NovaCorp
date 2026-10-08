@extends('layouts.app')
@section('title', 'Nouvelle demande')
@section('retour', route('demandes.index'))

@section('content')
<div class="card">
    <h1>{{ $modele ? 'Refaire la demande #'.$modele->id : 'Nouvelle demande' }}</h1>
    @if ($modele)
        <p class="alert ko">La demande #{{ $modele->id }} a expiré le {{ $modele->deadline?->format('d/m/Y') }} sans réponse. Ses informations sont reprises ci-dessous (les pièces jointes sont à joindre de nouveau).</p>
    @endif
    <p class="muted">La demande est assignée automatiquement à
        <strong>{{ $managerPrevu?->nom_complet ?? 'la direction' }}</strong>{{ $managerPrevu ? ' ('.$managerPrevu->email.')' : '' }},
        puis suit le circuit de validation de son type. Vous êtes prévenu par mail à chaque décision.</p>

    <form method="POST" action="{{ route('demandes.store') }}" enctype="multipart/form-data">
        @csrf
        <label for="type">Type de demande</label>
        <select id="type" name="type" required style="max-width:420px">
            @foreach ($types as $k => $v)
                <option value="{{ $k }}" @selected(old('type', $modele?->type) === $k)>{{ $v }}@isset($delais[$k]) – réponse sous {{ $delais[$k]->delai_escalade }} j ouvrés @endisset</option>
            @endforeach
        </select>
        @error('type')<div class="err">{{ $message }}</div>@enderror

        {{-- Circuit de validation de chaque type (affiché selon le type choisi) --}}
        @foreach ($types as $k => $v)
            <p class="muted circuit" data-type="{{ $k }}" style="font-size:.88rem;margin:.4rem 0 0">
                Circuit :
                @forelse ($circuits[$k] ?? [] as $etape)
                    {{ $etape->ordre }}. {{ $etape->libelle }}@if ($etape->description_condition) <em>({{ $etape->description_condition }})</em>@endif{{ $loop->last ? '' : ' → ' }}
                @empty
                    1. Manager
                @endforelse
            </p>
        @endforeach

        <div class="champ-type" data-types="{{ implode(',', \App\Models\Demande::TYPES_AVEC_MONTANT) }}">
            <label for="montant">Montant (€)</label>
            <input id="montant" type="number" name="montant" min="0" step="0.01" value="{{ old('montant', $modele?->montant) }}" style="max-width:220px">
            @error('montant')<div class="err">{{ $message }}</div>@enderror
        </div>

        <div class="champ-type row" data-types="{{ implode(',', \App\Models\Demande::TYPES_AVEC_DATES) }}">
            <div>
                <label for="date_debut">Premier jour de congé</label>
                <input id="date_debut" type="date" name="date_debut" value="{{ old('date_debut', $modele?->date_debut?->toDateString()) }}">
                @error('date_debut')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="date_fin">Dernier jour de congé</label>
                <input id="date_fin" type="date" name="date_fin" value="{{ old('date_fin', $modele?->date_fin?->toDateString()) }}">
                @error('date_fin')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <label for="objet">Objet</label>
        <input id="objet" name="objet" value="{{ old('objet', $modele?->objet) }}" required>
        @error('objet')<div class="err">{{ $message }}</div>@enderror

        <label for="message">Message</label>
        <textarea id="message" name="message" required>{{ old('message', $modele?->message) }}</textarea>
        @error('message')<div class="err">{{ $message }}</div>@enderror

        <label for="date_souhaitee">Date souhaitée pour la réponse (facultatif)</label>
        <input id="date_souhaitee" type="date" name="date_souhaitee" value="{{ old('date_souhaitee') }}"
               min="{{ \App\Support\Calendrier::aujourdhui()->toDateString() }}" style="max-width:220px">
        <div class="muted" style="font-size:.85rem">Si la réponse vous est nécessaire avant le délai normal, la demande est marquée <strong>urgente</strong>.
            Sans réponse à cette date, la demande expire.</div>
        @error('date_souhaitee')<div class="err">{{ $message }}</div>@enderror

        <label for="pieces_jointes">Pièces jointes (documents, vocaux, photos, vidéos – 5 fichiers, 50 Mo max chacun)</label>
        <input id="pieces_jointes" type="file" name="pieces_jointes[]" multiple
               accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.txt,.csv,image/*,audio/*,video/*">
        @error('pieces_jointes')<div class="err">{{ $message }}</div>@enderror
        @error('pieces_jointes.*')<div class="err">{{ $message }}</div>@enderror

        <p><button class="btn" type="submit">Envoyer la demande</button>
           <a class="btn sec" href="{{ route('demandes.index') }}">Annuler</a></p>
    </form>
</div>

<script>
    // Affiche le montant / les dates de congé et le circuit selon le type choisi
    (function () {
        const type = document.getElementById('type');
        function maj() {
            document.querySelectorAll('.champ-type').forEach(function (bloc) {
                const visible = bloc.dataset.types.split(',').includes(type.value);
                bloc.style.display = visible ? '' : 'none';
                bloc.querySelectorAll('input').forEach(function (i) { i.required = visible; });
            });
            document.querySelectorAll('.circuit').forEach(function (p) {
                p.style.display = p.dataset.type === type.value ? '' : 'none';
            });
        }
        type.addEventListener('change', maj);
        maj();
    })();
</script>
@endsection
