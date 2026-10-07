<?php

namespace App\Providers;

use App\Subscribers\AuthEventSubscriber;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Évite l'erreur "key too long" sur certaines versions de MySQL/MariaDB
        Schema::defaultStringLength(191);

        Paginator::useBootstrapFive();

        Event::subscribe(AuthEventSubscriber::class);
    }
}
