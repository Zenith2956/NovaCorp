@if ($demande->montant !== null)
    <p><strong>Montant :</strong> {{ number_format((float) $demande->montant, 2, ',', ' ') }} €</p>
@endif
@if ($demande->date_debut && $demande->date_fin)
    <p><strong>Congé :</strong> du {{ $demande->date_debut->format('d/m/Y') }} au {{ $demande->date_fin->format('d/m/Y') }}
        ({{ $demande->nb_jours_ouvres }} jour{{ $demande->nb_jours_ouvres > 1 ? 's' : '' }} ouvré{{ $demande->nb_jours_ouvres > 1 ? 's' : '' }})</p>
@endif
