<?php

namespace App\Subscribers;

use App\Models\ConnexionLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

/**
 * Écoute les évènements d'authentification de Laravel et les enregistre
 * dans la table connexion_logs (+ fichier storage/logs/laravel.log).
 */
class AuthEventSubscriber
{
    public function handleLogin(Login $event): void
    {
        ConnexionLog::enregistrer('connexion', $event->user);
        logger()->info('Connexion', ['user_id' => $event->user->id]);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user) {
            ConnexionLog::enregistrer('deconnexion', $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        ConnexionLog::enregistrer('echec', $event->user, $event->credentials['email'] ?? null);
        logger()->warning('Échec de connexion', ['email' => $event->credentials['email'] ?? null, 'ip' => request()->ip()]);
    }

    public function handleRegistered(Registered $event): void
    {
        ConnexionLog::enregistrer('inscription', $event->user);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            Registered::class => 'handleRegistered',
        ];
    }
}
