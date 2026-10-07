<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes envoyées par mail au manager.
 * Fonctionnement actuel (état initial du projet) :
 *  - le manager valide en répondant au mail (pas de traçabilité, pas de délai) ;
 *  - les relances se font par téléphone ;
 *  - le statut est mis à jour manuellement dans l'application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demandeur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);              // conge | materiel | formation | note_de_frais | autre
            $table->string('objet');
            $table->text('message');
            $table->string('statut', 20)->default('en_attente'); // en_attente | validee | refusee
            $table->timestamp('envoyee_at')->nullable();          // date d'envoi du mail
            $table->timestamps();
        });

        // Pièces jointes : document, audio (vocal), image (photo), vidéo
        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demandes')->cascadeOnDelete();
            $table->string('nom_original');
            $table->string('chemin');
            $table->string('mime_type', 150)->nullable();
            $table->string('categorie', 20);         // document | audio | image | video | autre
            $table->unsignedBigInteger('taille');    // en octets
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_jointes');
        Schema::dropIfExists('demandes');
    }
};
