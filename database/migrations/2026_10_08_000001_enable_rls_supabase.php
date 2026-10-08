<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sécurité Supabase : toutes les tables du schéma "public" sont exposées par l'API
 * REST de Supabase (clé publique). On active le Row Level Security SANS politique :
 * l'API publique ne voit donc rien, tandis que Laravel (propriétaire des tables) garde l'accès.
 *
 * ⚠ Toute nouvelle table créée plus tard doit aussi avoir :
 *     DB::statement('ALTER TABLE ma_table ENABLE ROW LEVEL SECURITY');
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tablesPubliques() as $table) {
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tablesPubliques() as $table) {
            DB::statement("ALTER TABLE public.\"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }

    private function tablesPubliques(): array
    {
        return array_column(
            DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"),
            'tablename'
        );
    }
};
