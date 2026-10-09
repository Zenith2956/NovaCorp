<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Webhook des mails : un seul appel en attente à la fois (voir le fichier SQL). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_08_000008_webhook_anti_rafale.sql')));
        }
    }

    public function down(): void
    {
        // Rien : la version précédente (sans anti-rafale) n'est pas à restaurer.
    }
};
