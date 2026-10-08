{{--
    Sélecteur de membres avec recherche, filtre par rôle et tri (sans rechargement de page).
    Paramètres : $employes (collection de User avec role), $selection (ids cochés), $nom (nom du champ, ex. "membres[]").
--}}
@php($selection = collect($selection ?? [])->map(fn ($id) => (int) $id)->all())
<div class="selecteur" data-selecteur>
    <div class="inline selecteur-outils">
        <input type="search" placeholder="Rechercher : nom, prénom, e-mail…" data-recherche style="flex:1;min-width:220px">
        <select data-role>
            <option value="">Tous les rôles</option>
            @foreach ($employes->pluck('role.libelle')->filter()->unique()->sort() as $libelle)
                <option value="{{ $libelle }}">{{ $libelle }}</option>
            @endforeach
        </select>
        <select data-tri>
            <option value="nom">Trier par nom</option>
            <option value="prenom">Trier par prénom</option>
            <option value="role">Trier par rôle</option>
        </select>
    </div>
    <div class="inline muted" style="font-size:.85rem;margin:.4rem 0">
        <span><strong data-compteur>{{ count($selection) }}</strong> sélectionné(s) · <span data-visibles>{{ $employes->count() }}</span> affiché(s)</span>
        <label class="inline" style="margin:0;font-weight:normal"><input type="checkbox" data-seulement> Seulement la sélection</label>
    </div>
    <div class="selecteur-liste" data-liste>
        @foreach ($employes as $e)
            <label class="selecteur-ligne"
                   data-nom="{{ $e->nom }}" data-prenom="{{ $e->prenom }}" data-role="{{ $e->role?->libelle }}"
                   data-texte="{{ $e->nom }} {{ $e->prenom }} {{ $e->email }} {{ $e->role?->libelle }}">
                <input type="checkbox" name="{{ $nom }}" value="{{ $e->id }}" @checked(in_array($e->id, $selection, true))>
                <span><strong>{{ $e->nom }}</strong> {{ $e->prenom }}</span>
                <span class="badge">{{ $e->role?->libelle }}</span>
                <span class="muted">{{ $e->email }}</span>
            </label>
        @endforeach
    </div>
</div>

@once
<style>
    .selecteur-liste{max-height:320px;overflow-y:auto;border:1px solid var(--line);border-radius:6px;margin-top:.3rem}
    .selecteur-ligne{display:grid;grid-template-columns:auto 1fr auto;gap:.2rem .7rem;align-items:center;margin:0;padding:.45rem .7rem;border-bottom:1px solid var(--line);font-weight:normal;cursor:pointer}
    .selecteur-ligne:hover{background:#f8f9fc}
    .selecteur-ligne input{width:auto}
    .selecteur-ligne .muted{grid-column:2 / 4;font-size:.82rem}
    .selecteur-ligne:has(input:checked){background:#edf2ff}
    .selecteur-outils>*{width:auto}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const sansAccents = (t) => (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    document.querySelectorAll('[data-selecteur]').forEach((bloc) => {
        const recherche = bloc.querySelector('[data-recherche]');
        const role = bloc.querySelector('[data-role]');
        const tri = bloc.querySelector('[data-tri]');
        const seulement = bloc.querySelector('[data-seulement]');
        const liste = bloc.querySelector('[data-liste]');
        const lignes = [...liste.querySelectorAll('.selecteur-ligne')];

        const rafraichir = () => {
            const mots = sansAccents(recherche.value).split(/\s+/).filter(Boolean);
            let visibles = 0;
            lignes.forEach((l) => {
                const texte = sansAccents(l.dataset.texte);
                const coche = l.querySelector('input').checked;
                const ok = mots.every((m) => texte.includes(m))
                    && (!role.value || l.dataset.role === role.value)
                    && (!seulement.checked || coche);
                l.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            bloc.querySelector('[data-visibles]').textContent = visibles;
            bloc.querySelector('[data-compteur]').textContent = lignes.filter((l) => l.querySelector('input').checked).length;
        };

        const trier = () => {
            const cle = tri.value;
            lignes.sort((a, b) => sansAccents(a.dataset[cle] + ' ' + a.dataset.nom)
                .localeCompare(sansAccents(b.dataset[cle] + ' ' + b.dataset.nom), 'fr'))
                .forEach((l) => liste.appendChild(l));
        };

        recherche.addEventListener('input', rafraichir);
        role.addEventListener('change', rafraichir);
        seulement.addEventListener('change', rafraichir);
        liste.addEventListener('change', rafraichir);
        tri.addEventListener('change', trier);
        // Entrée dans la recherche : ne pas envoyer le formulaire
        recherche.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
        trier();
    });
});
</script>
@endonce
