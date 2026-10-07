<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chiffres_affaires', function (Blueprint $table) {
            $table->id();
            $table->date('periode');                 // 1er jour du mois concerné
            $table->decimal('montant', 14, 2);
            $table->foreignId('projet_id')->nullable()->constrained('projets')->nullOnDelete();
            $table->string('commentaire')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chiffres_affaires');
    }
};
