@extends('layouts.app')
@section('title', 'Délais')

@section('content')
<h1>Délais de traitement</h1>
<p class="muted">{{ $toutVoir ? 'Toutes les demandes' : 'Demandes adressées à votre équipe' }} · jours ouvrés (week-ends et jours fériés exclus) · au {{ $aujourdhui->format('d/m/Y') }}</p>

<div class="stats">
    <div class="stat"><div class="v">{{ $global['en_attente'] }}</div><div class="l">En attente</div></div>
    <div class="stat"><div class="v" style="color:var(--ko)">{{ $global['en_retard'] }}</div><div class="l">En retard (échéance dépassée)</div></div>
    <div class="stat"><div class="v">{{ $global['expirees'] }}</div><div class="l">Expirées</div></div>
    <div class="stat"><div class="v">{{ $global['delai_moyen'] ?? '–' }}</div><div class="l">Délai moyen de réponse (j. ouvrés)</div></div>
    <div class="stat"><div class="v">{{ $global['pct_dans_les_temps'] !== null ? $global['pct_dans_les_temps'].' %' : '–' }}</div><div class="l">Traitées dans les temps</div></div>
</div>

@foreach (['En retard' => $enRetard, 'Échéance proche' => $bientot] as $titre => $liste)
<div class="card">
    <h2>{{ $titre }} ({{ $liste->count() }})</h2>
    @if ($liste->isEmpty())
        <p class="muted">Aucune demande.</p>
    @else
    <table>
        <tr><th>#</th><th>Objet</th><th>Demandeur</th><th>Manager</th><th>Échéance</th><th>Deadline</th></tr>
        @foreach ($liste as $d)
            <tr>
                <td>{{ $d->id }}</td>
                <td><a href="{{ route('demandes.show', $d) }}">{{ $d->objet }}</a> @if ($d->urgente)<span class="badge b-urgente">Urgente</span>@endif</td>
                <td>{{ $d->demandeur->nom_complet }}</td>
                <td>{{ $d->manager?->nom_complet }}</td>
                <td>{{ $d->echeance_le?->format('d/m/Y') }}</td>
                <td>{{ $d->deadline?->format('d/m/Y') }}</td>
            </tr>
        @endforeach
    </table>
    @endif
</div>
@endforeach

<div class="card">
    <h2>Par type de demande</h2>
    <table>
        <tr><th>Type</th><th>Total</th><th>En attente</th><th>En retard</th><th>Expirées</th><th>Délai moyen</th><th>Dans les temps</th></tr>
        @foreach ($parType as $code => $i)
            <tr>
                <td>{{ \App\Models\Demande::TYPES[$code] ?? $code }}</td>
                <td>{{ $i['total'] }}</td><td>{{ $i['en_attente'] }}</td><td>{{ $i['en_retard'] }}</td><td>{{ $i['expirees'] }}</td>
                <td>{{ $i['delai_moyen'] !== null ? $i['delai_moyen'].' j' : '–' }}</td>
                <td>{{ $i['pct_dans_les_temps'] !== null ? $i['pct_dans_les_temps'].' %' : '–' }}</td>
            </tr>
        @endforeach
    </table>
</div>

@if ($toutVoir)
<div class="card">
    <h2>Par manager</h2>
    <table>
        <tr><th>Manager</th><th>Total</th><th>En attente</th><th>En retard</th><th>Expirées</th><th>Délai moyen</th><th>Dans les temps</th></tr>
        @foreach ($parManager as $i)
            <tr>
                <td>{{ $i['manager']?->nom_complet ?? '—' }}</td>
                <td>{{ $i['total'] }}</td><td>{{ $i['en_attente'] }}</td><td>{{ $i['en_retard'] }}</td><td>{{ $i['expirees'] }}</td>
                <td>{{ $i['delai_moyen'] !== null ? $i['delai_moyen'].' j' : '–' }}</td>
                <td>{{ $i['pct_dans_les_temps'] !== null ? $i['pct_dans_les_temps'].' %' : '–' }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

<div class="card">
    <h2>Délais par type (jours ouvrés)</h2>
    <p class="muted">Relance au manager, puis échéance (escalade RH + N+2). La deadline (expiration) = échéance + {{ \App\Models\TypeDemande::MARGE_DEADLINE }} jours ouvrés, ou la date souhaitée par l'employé.</p>
    @if ($peutRegler)
    <form method="POST" action="{{ route('delais.types') }}">
        @csrf @method('PUT')
    @endif
    <table>
        <tr><th>Type</th><th>Relance après</th><th>Échéance / escalade après</th></tr>
        @foreach ($types as $t)
            <tr>
                <td>{{ $t->libelle }}</td>
                @if ($peutRegler)
                    <td><input type="number" min="1" max="60" name="types[{{ $t->id }}][delai_relance]" value="{{ old("types.$t->id.delai_relance", $t->delai_relance) }}" style="width:90px"> j</td>
                    <td><input type="number" min="1" max="60" name="types[{{ $t->id }}][delai_escalade]" value="{{ old("types.$t->id.delai_escalade", $t->delai_escalade) }}" style="width:90px"> j</td>
                @else
                    <td>{{ $t->delai_relance }} j</td><td>{{ $t->delai_escalade }} j</td>
                @endif
            </tr>
        @endforeach
    </table>
    @if ($peutRegler)
        @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
        <p><button class="btn">Enregistrer les délais</button></p>
    </form>
    @endif
</div>
@endsection
