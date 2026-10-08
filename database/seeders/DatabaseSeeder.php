<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Pas de mail de bienvenue pour les 120 employés générés (trigger automation.mail_bienvenue)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET novacorp.sans_mails = 'on'");
        }

        $this->call([
            RoleSeeder::class,
            EmployeSeeder::class,
            ProjetSeeder::class,
        ]);
    }
}
