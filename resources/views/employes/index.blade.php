@extends('layouts.app')
@section('title', 'Employés')

@section('content')
<h1>Employés ({{ $employes->total() }})</h1>
<div class="card">
    <form class="inline" method="GET">
        <input name="q" value="{{ request('q') }}" placeholder="Nom, prénom ou e-mail" style="min-width:260px">
        <select name="role">
            <option value="">Tous les rôles</option>
            @foreach ($roles as $r)
                <option value="{{ $r->id }}" @selected(request('role') == $r->id)>{{ $r->libelle }}</option>
            @endforeach
        </select>
        <button class="btn sec">Rechercher</button>
    </form>

    <table style="margin-top:1rem">
        <tr><th>Nom</th><th>Prénom</th><th>Rôle</th><th>E-mail</th><th>Téléphone</th><th>Manager</th><th>Suppléant (congés)</th></tr>
        @foreach ($employes as $e)
            <tr>
                <td>{{ $e->nom }}</td>
                <td>{{ $e->prenom }}</td>
                <td><span class="badge">{{ $e->role?->libelle }}</span></td>
                <td><a href="mailto:{{ $e->email }}">{{ $e->email }}</a></td>
                <td>{{ $e->telephone }}</td>
                <td>{{ $e->manager?->nom_complet }}</td>
                <td>
                    @if ($e->equipe_count > 0)
                        @if ($peutChoisirSuppleant)
                            <form method="POST" action="{{ route('suppleant.employe', $e) }}" class="inline" style="flex-wrap:nowrap">
                                @csrf @method('PUT')
                                <select name="suppleant_id" style="max-width:170px">
                                    <option value="">Par défaut (N+1)</option>
                                    @foreach ($candidats as $c)
                                        @continue($c->id === $e->id)
                                        <option value="{{ $c->id }}" @selected($e->suppleant_id === $c->id)>{{ $c->nom }} {{ $c->prenom }}</option>
                                    @endforeach
                                </select>
                                <button class="btn sec">OK</button>
                            </form>
                        @else
                            {{ $e->suppleant?->nom_complet ?? 'Par défaut (N+1)' }}
                        @endif
                    @else
                        <span class="muted">–</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
    <div class="pagination">{{ $employes->links() }}</div>
</div>
@endsection
