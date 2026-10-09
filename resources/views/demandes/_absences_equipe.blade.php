{{-- A3 : collègues absents sur la période d'un congé (calcul automation.absences_equipe). Paramètre : $absencesEquipe (ou null). --}}
@if ($absencesEquipe)
    {{-- A3 : collègues absents sur la période --}}
    <div class="alert {{ $absencesEquipe['alerte'] ? 'ko' : 'info' }}">
        <strong>Équipe sur cette période :</strong>
        @if (empty($absencesEquipe['absents']))
            aucun autre congé dans l'équipe ({{ $absencesEquipe['taille_equipe'] }} personnes).
        @else
            avec ce congé, jusqu'à <strong>{{ $absencesEquipe['max_simultanes'] }} / {{ $absencesEquipe['taille_equipe'] }}</strong> personnes absentes
            @if ($absencesEquipe['jour_max']) le {{ \Illuminate\Support\Carbon::parse($absencesEquipe['jour_max'])->format('d/m/Y') }} @endif
            ({{ $absencesEquipe['pct'] }} %{{ $absencesEquipe['alerte'] ? ', seuil de '.$absencesEquipe['seuil'].' % atteint' : '' }}).
            <ul style="margin:.25rem 0 0 1.2rem">
                @foreach ($absencesEquipe['absents'] as $a)
                    <li>{{ $a['nom'] }} : du {{ \Illuminate\Support\Carbon::parse($a['debut'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($a['fin'])->format('d/m/Y') }}
                        @if ($a['statut'] === 'en_attente')<span class="muted">(demande en attente)</span>@endif</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
