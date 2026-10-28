<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ConnexionLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * R1 – Activation d'un compte créé par l'admin : le nouvel employé choisit son mot de passe
 * avec le lien reçu dans le mail de bienvenue (valable 7 jours, usage unique).
 */
class ActivationController extends Controller
{
    public function create(string $jeton)
    {
        return view('auth.activation', ['jeton' => $jeton, 'user' => $this->utilisateur($jeton)]);
    }

    public function store(Request $request, string $jeton)
    {
        $user = $this->utilisateur($jeton);
        abort_unless($user, 403, 'Lien d\'activation invalide ou expiré : demandez-en un nouveau à votre administrateur.');

        $request->validate(['password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]],
            [], ['password' => 'mot de passe']);

        $user->forceFill([
            'password' => $request->password,               // haché par le cast « hashed »
            'jeton_activation' => null,
            'jeton_activation_expire_at' => null,
        ])->save();

        ConnexionLog::enregistrer('activation', $user);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Compte activé, bienvenue chez NovaCorp !');
    }

    private function utilisateur(string $jeton): ?User
    {
        return User::where('jeton_activation', $jeton)
            ->where('jeton_activation_expire_at', '>', now())
            ->where('actif', true)
            ->first();
    }
}
