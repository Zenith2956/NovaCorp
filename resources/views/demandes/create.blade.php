@extends('layouts.app')
@section('title', 'Nouvelle demande')

@section('content')
<div class="card">
    <h1>{{ $modele ? 'Refaire la demande #'.$modele->id : 'Nouvelle demande' }}</h1>
    @if ($modele)
        <p class="alert ko">La demande #{{ $modele->id }} a expiré le {{ $modele->deadline?->format('d/m/Y') }} sans réponse. Ses informations sont reprises ci-dessous (les pièces jointes sont à joindre de nouveau).</p>
    @endif
    <p class="muted">La demande est envoyée par e-mail à votre manager, avec les pièces jointes.
        Il vous répondra par retour de mail.</p>

    <form method="POST" action="{{ route('demandes.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <div>
                <label for="type">Type de demande</label>
                <select id="type" name="type" required>
                    @foreach ($types as $k => $v)
                        <option value="{{ $k }}" @selected(old('type', $modele?->type) === $k)>{{ $v }}@isset($delais[$k]) – réponse sous {{ $delais[$k]->delai_escalade }} j ouvrés @endisset</option>
                    @endforeach
                </select>
                @error('type')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="manager_id">Destinataire (manager)</label>
                <select id="manager_id" name="manager_id" required>
                    @foreach ($managers as $m)
                        <option value="{{ $m->id }}" @selected(old('manager_id', $modele?->manager_id ?? $managerParDefaut) == $m->id)>{{ $m->nom_complet }} – {{ $m->email }}</option>
                    @endforeach
                </select>
                @error('manager_id')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <label for="objet">Objet</label>
        <input id="objet" name="objet" value="{{ old('objet', $modele?->objet) }}" required>
        @error('objet')<div class="err">{{ $message }}</div>@enderror

        <label for="message">Message</label>
        <textarea id="message" name="message" required>{{ old('message', $modele?->message) }}</textarea>
        @error('message')<div class="err">{{ $message }}</div>@enderror

        <label for="date_souhaitee">Date souhaitée (facultatif)</label>
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
@endsection
