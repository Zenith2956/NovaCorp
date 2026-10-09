<?php

namespace App\Services;

use App\Models\Demande;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * D3 – Aide à la rédaction : brouillon de motif de refus ou de demande de complément.
 * Laravel appelle le workflow n8n « NovaCorp – brouillon IA » (n8n tourne sur le même PC) et attend sa réponse.
 * Le texte n'est JAMAIS envoyé tel quel : il est placé dans le champ « Commentaire », le valideur le relit.
 */
class BrouillonIa
{
    public const INTENTIONS = [
        'refuser' => 'refus',
        'demander_complement' => 'demande de complément',
    ];

    public function estConfigure(): bool
    {
        return filled(config('novacorp.n8n.brouillon_url')) && filled(config('novacorp.n8n.secret'));
    }

    public function rediger(Demande $demande, User $valideur, string $intention, ?string $notes = null): string
    {
        $demande->loadMissing(['demandeur', 'piecesJointes']);

        $reponse = Http::timeout(30)
            ->withHeaders(['x-novacorp-secret' => config('novacorp.n8n.secret')])
            ->acceptJson()
            ->post(config('novacorp.n8n.brouillon_url'), [
                'intention' => $intention,
                'intention_libelle' => self::INTENTIONS[$intention],
                'valideur' => $valideur->prenom.' '.$valideur->nom,
                'demandeur_prenom' => $demande->demandeur?->prenom,
                'type' => $demande->type,
                'objet' => $demande->objet,
                'message' => $demande->message,
                'montant' => $demande->montant,
                'date_debut' => $demande->date_debut?->toDateString(),
                'date_fin' => $demande->date_fin?->toDateString(),
                'date_souhaitee' => $demande->date_souhaitee?->toDateString(),
                'pieces_jointes' => $demande->piecesJointes->pluck('nom_original')->all(),
                'points_attention' => $demande->analyse_ia['points_attention'] ?? [],
                'notes_valideur' => $notes,
            ]);

        if (! $reponse->successful()) {
            throw new RuntimeException("n8n a répondu {$reponse->status()}");
        }

        // n8n renvoie { "text": "..." } (Webhook « When Last Node Finishes » + Basic LLM Chain)
        $texte = trim((string) ($reponse->json('text') ?? $reponse->json('texte') ?? ''));
        if ($texte === '') {
            throw new RuntimeException('réponse vide');
        }

        return mb_substr($texte, 0, 2000);
    }
}
