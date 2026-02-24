<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer un super administrateur par défaut
        Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@cabinet-immigration.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Créer un admin de test
        Admin::create([
            'name' => 'Admin Test',
            'email' => 'test@cabinet-immigration.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}


