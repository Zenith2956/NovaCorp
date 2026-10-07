<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projets', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('statut', 30)->default('en_cours'); // a_venir | en_cours | termine
            $table->foreignId('chef_projet_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('budget', 12, 2)->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->timestamps();
        });

        // Employés affectés aux projets (plusieurs à plusieurs)
        Schema::create('projet_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['projet_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projet_user');
        Schema::dropIfExists('projets');
    }
};
