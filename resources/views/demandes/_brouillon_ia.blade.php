{{-- D3 : boutons « Proposer un texte » (brouillon IA à relire). Paramètres : $intentions (refuser / demander_complement), $url. --}}
@if ($intentions && config('novacorp.n8n.brouillon_url'))
    {{-- D3 : brouillon rédigé par l'IA, à relire avant d'envoyer --}}
    <p class="inline" style="margin-top:.5rem">
        @foreach ($intentions as $intention)
            <button type="button" class="btn sec" data-brouillon-ia="{{ $intention }}">✨ Proposer {{ $intention === 'refuser' ? 'un motif de refus' : 'une demande de complément' }}</button>
        @endforeach
        <span class="muted" id="brouillon-ia-etat" style="font-size:.85rem">Brouillon rédigé par l'IA : relisez-le avant d'envoyer. Ce que vous avez déjà écrit sert d'indication.</span>
    </p>
    <script>
        document.querySelectorAll('[data-brouillon-ia]').forEach((bouton) => bouton.addEventListener('click', async () => {
            const champ = document.getElementById('commentaire'), etat = document.getElementById('brouillon-ia-etat')
            const boutons = document.querySelectorAll('[data-brouillon-ia]')
            boutons.forEach((b) => b.disabled = true)
            etat.textContent = 'Rédaction en cours…'
            try {
                const reponse = await fetch(@json($url), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                               'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value },
                    body: JSON.stringify({ intention: bouton.dataset.brouillonIa, notes: champ.value }),
                })
                const json = await reponse.json().catch(() => ({}))
                if (!reponse.ok) throw new Error(json.erreur || json.message || 'Erreur ' + reponse.status)
                champ.value = json.texte
                champ.rows = 6
                champ.focus()
                etat.textContent = "Brouillon proposé par l'IA : relisez et modifiez-le avant d'envoyer."
            } catch (e) {
                etat.textContent = e.message
            } finally {
                boutons.forEach((b) => b.disabled = false)
            }
        }))
    </script>
@endif
