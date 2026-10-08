<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks : envoi immédiat des mails (webhook base de données) et suivi de livraison Resend.
 * Voir docs/architecture-technique.md et database/sql/2026_10_08_000007_webhooks.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mails_sortants', function (Blueprint $table) {
            $table->string('livraison', 20)->nullable();        // envoye | retarde | delivre | echec | rebond | plainte
            $table->timestamp('livraison_at')->nullable();
            $table->text('livraison_detail')->nullable();       // motif du rebond…
            $table->index('fournisseur_id');
        });

        // Journal des événements reçus de Resend (svix_id unique : un événement rejoué n'est traité qu'une fois)
        Schema::create('mails_evenements', function (Blueprint $table) {
            $table->id();
            $table->string('svix_id', 100)->unique();
            $table->string('type', 50);
            $table->string('fournisseur_id', 100)->nullable()->index();
            $table->foreignId('mail_id')->nullable()->constrained('mails_sortants')->nullOnDelete();
            $table->jsonb('donnees')->nullable();
            $table->timestamp('recu_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_08_000007_webhooks.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS mails_sortants_webhook ON public.mails_sortants;
DROP FUNCTION IF EXISTS automation.webhook_mails_sortants(), automation.rang_livraison(text);
SQL);
        }
        Schema::dropIfExists('mails_evenements');
        Schema::table('mails_sortants', function (Blueprint $table) {
            $table->dropIndex(['fournisseur_id']);
            $table->dropColumn(['livraison', 'livraison_at', 'livraison_detail']);
        });
    }
};
