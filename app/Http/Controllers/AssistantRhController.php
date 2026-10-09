<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TP séance 6 – Relais entre la bulle « Assistant RH » et le workflow n8n.
 *
 * Le navigateur ne connaît jamais l'adresse de n8n ni ses identifiants : il parle à Laravel (connexion + CSRF + 20 messages/min),
 * qui transmet à n8n avec une authentification Basic Auth gardée côté serveur.
 * L'identité envoyée à l'agent (email, nom) vient de la session Laravel, jamais du navigateur :
 * un employé ne peut pas se faire passer pour un autre pour consulter ses demandes (partie 5).
 */
class AssistantRhController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'in:sendMessage,loadPreviousSession'],
            'sessionId' => ['nullable', 'string', 'max:100'],
            'chatInput' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();

        $corps = [
            'action' => $data['action'] ?? 'sendMessage',
            // Session de conversation propre à l'utilisateur : impossible de lire la mémoire d'un autre
            'sessionId' => 'u'.$user->id.'-'.preg_replace('/[^A-Za-z0-9-]/', '', $data['sessionId'] ?? 'defaut'),
            'chatInput' => $data['chatInput'] ?? '',
            'metadata' => ['email' => $user->email, 'nom' => $user->nom_complet, 'role' => $user->role?->slug],
        ];

        try {
            $reponse = Http::timeout(60)
                ->withBasicAuth((string) config('novacorp.n8n.chat_user'), (string) config('novacorp.n8n.chat_password'))
                ->acceptJson()
                ->post(config('novacorp.n8n.chat_url'), $corps);

            if ($reponse->successful()) {
                return response()->json($reponse->json() ?? ['output' => trim($reponse->body())]);
            }
            Log::warning('Assistant RH : n8n a répondu '.$reponse->status());
        } catch (Throwable $e) {
            Log::warning('Assistant RH indisponible : '.$e->getMessage());
        }

        // Le widget affiche « output » comme un message de l'assistant
        return response()->json($corps['action'] === 'loadPreviousSession'
            ? ['data' => []]
            : ['output' => "L'assistant RH est indisponible pour le moment. Réessayez plus tard ou contactez le service RH (rh@novacorp.fr)."]);
    }
}
