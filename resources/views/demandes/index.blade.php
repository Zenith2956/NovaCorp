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
        <button class="btn sec">Filtrer</button>
    </form>

    <table style="margin-top:1rem">
        <tr><th>#</th><th>Type</th><th>Objet</th><th>Demandeur</th><th>Manager</th><th>PJ</th><th>Envoyée le</th><th>Statut</th></tr>
        @forelse ($demandes as $d)
            <tr>
                <td>{{ $d->id }}</td>
                <td>{{ $d->type_libelle }}</td>
                <td><a href="{{ route('demandes.show', $d) }}">{{ $d->objet }}</a></td>
                <td>{{ $d->demandeur->nom_complet }}</td>
                <td>{{ $d->manager?->nom_complet }}</td>
                <td>{{ $d->pieces_jointes_count ?: '–' }}</td>
                <td>{{ $d->envoyee_at?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</td>
                <td><span class="badge b-{{ $d->statut }}">{{ $d->statut_libelle }}</span></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Aucune demande.</td></tr>
        @endforelse
    </table>
    <div class="pagination">{{ $demandes->links() }}</div>
</div>
@endsection
