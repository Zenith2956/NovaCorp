<span class="badge t-{{ $tache->statut }}">{{ $tache->statut_libelle }}</span>
@if ($tache->indicateur_delai)<span class="badge i-{{ $tache->indicateur_delai }}">{{ \App\Models\Demande::INDICATEURS[$tache->indicateur_delai] }}</span>@endif
@if ($tache->projet_id && ! $tache->deadline)<span class="badge i-echeance_proche">Deadline à fixer</span>@endif
