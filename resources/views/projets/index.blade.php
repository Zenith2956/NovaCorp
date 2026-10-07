@extends('layouts.app')
@section('title', 'Projets & CA')

@section('content')
<h1>Projets</h1>
<div class="card">
    <table>
        <tr><th>Projet</th><th>Statut</th><th>Chef de projet</th><th>Membres</th><th>Budget</th><th>CA généré</th><th>Période</th></tr>
        @foreach ($projets as $p)
            <tr>
                <td><strong>{{ $p->nom }}</strong><br><small class="muted">{{ $p->description }}</small></td>
                <td><span class="badge">{{ str_replace('_', ' ', $p->statut) }}</span></td>
                <td>{{ $p->chefProjet?->nom_complet }}</td>
                <td>{{ $p->membres_count }}</td>
                <td>{{ number_format((float) $p->budget, 0, ',', ' ') }} €</td>
                <td>{{ number_format((float) $p->chiffres_affaires_sum_montant, 0, ',', ' ') }} €</td>
                <td>{{ $p->date_debut?->format('m/Y') }} → {{ $p->date_fin?->format('m/Y') }}</td>
            </tr>
        @endforeach
    </table>
</div>

<h1>Chiffre d'affaires mensuel</h1>
<div class="card">
    <table>
        <tr><th>Mois</th><th style="text-align:right">Montant</th></tr>
        @foreach ($caParMois as $ca)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($ca->periode)->translatedFormat('F Y') }}</td>
                <td style="text-align:right">{{ number_format((float) $ca->total, 0, ',', ' ') }} €</td>
            </tr>
        @endforeach
    </table>
</div>
@endsection
