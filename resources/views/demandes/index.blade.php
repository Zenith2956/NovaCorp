@extends('layouts.app')
@section('title', 'Demandes')

@section('content')
<div class="inline" style="justify-content:space-between">
    <h1>Demandes</h1>
    <a class="btn" href="{{ route('demandes.create') }}">+ Nouvelle demande</a>
</div>

<div class="card">
    <form class="inline" method="GET">
        <select name="statut">
            <option value="">Tous les statuts</option>
            @foreach (\App\Models\Demande::STATUTS as $k => $v)
                <option value="{{ $k }}" @selected(request('statut') === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <select name="type">
            <option value="">Tous les types</option>
            @foreach (\App\Models\Demande::TYPES as $k => $v)
                <option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <label class="inline" style="margin:0;font-weight:normal"><input type="checkbox" name="a_traiter" value="1" @checked(request()->boolean('a_traiter'))> À traiter par moi</label>
        <label class="inline" style="margin:0;font-weight:normal"><input type="checkbox" name="retard" value="1" @checked(request()->boolean('retard'))> En retard seulement</label>
        <button class="btn sec">Filtrer</button>
    </form>

    <table style="margin-top:1rem">
        <tr><th>#</th><th>Type</th><th>Objet</th><th>Demandeur</th><th>Manager</th><th>PJ</th><th>Envoyée le</th><th>Échéance</th><th>Statut</th></tr>
        @forelse ($demandes as $d)
            <tr>
                <td>{{ $d->id }}</td>
                <td>{{ $d->type_libelle }}</td>
                <td><a href="{{ route('demandes.show', $d) }}">{{ $d->objet }}</a> @if ($d->urgente)<span class="badge b-urgente">Urgente</span>@endif</td>
                <td>{{ $d->demandeur->nom_complet }}</td>
                <td>{{ $d->manager?->nom_complet }}</td>
                <td>{{ $d->pieces_jointes_count ?: '–' }}</td>
                <td>{{ $d->envoyee_at?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</td>
                <td>{{ $d->echeance_le?->format('d/m/Y') }}
                    @if ($d->indicateur_delai)<br><span class="badge i-{{ $d->indicateur_delai }}">{{ \App\Models\Demande::INDICATEURS[$d->indicateur_delai] }}</span>@endif</td>
                <td><span class="badge b-{{ $d->statut }}">{{ $d->statut_libelle }}</span>
                    @if (in_array($d->statut, ['en_attente', 'a_completer'], true) && $d->etape > 1)<br><span class="muted" style="font-size:.8rem">étape {{ $d->etape }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">Aucune demande.</td></tr>
        @endforelse
    </table>
    <div class="pagination">{{ $demandes->links() }}</div>
</div>
@endsection
