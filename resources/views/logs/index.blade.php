@extends('layouts.app')
@section('title', 'Journal des connexions')

@section('content')
<h1>Journal des connexions</h1>
<div class="card">
    <table>
        <tr><th>Date</th><th>Évènement</th><th>Utilisateur</th><th>E-mail saisi</th><th>IP</th></tr>
        @foreach ($logs as $log)
            <tr>
                <td>{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                <td><span @class(['badge', 'b-refusee' => $log->evenement === 'echec', 'b-validee' => $log->evenement === 'connexion'])>{{ $log->evenement }}</span></td>
                <td>{{ $log->user?->nom_complet ?? '—' }}</td>
                <td>{{ $log->email }}</td>
                <td>{{ $log->ip_address }}</td>
            </tr>
        @endforeach
    </table>
    <div class="pagination">{{ $logs->links() }}</div>
</div>
@endsection
