<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use App\Services\BrouillonIa;
use App\Services\WorkflowDemande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/** D3 – « Proposer un texte » : brouillon IA du motif de refus ou de la demande de complément. */
class BrouillonIaController extends Controller
{
    public function __construct(private WorkflowDemande $workflow, private BrouillonIa $brouillon) {}

    public function __invoke(Request $request, Demande $demande): JsonResponse
    {
        $data = $request->validate([
            'intention' => ['required', Rule::in(array_keys(BrouillonIa::INTENTIONS))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Seulement pour qui peut refuser / demander un complément sur cette demande, maintenant
        abort_unless(in_array($data['intention'], $this->workflow->actionsPossibles($request->user(), $demande), true), 403);

        if (! $this->brouillon->estConfigure()) {
            return response()->json(['erreur' => "L'assistant IA n'est pas configuré (N8N_BROUILLON_URL / N8N_SECRET)."], 503);
        }

        try {
            $texte = $this->brouillon->rediger($demande, $request->user(), $data['intention'], $data['notes'] ?? null);
        } catch (Throwable $e) {
            Log::warning('Brouillon IA indisponible', ['demande' => $demande->id, 'erreur' => $e->getMessage()]);

            return response()->json(['erreur' => "L'assistant IA est indisponible pour le moment (n8n éteint ?). Rédigez le commentaire vous-même."], 503);
        }

        return response()->json(['texte' => $texte]);
    }
}
