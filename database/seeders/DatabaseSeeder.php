<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesYPermisosSeeder::class);
        $this->call(CatalogosSeeder::class);
        $this->call(MenuSeeder::class);

        if (app()->environment(['local', 'staging'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
