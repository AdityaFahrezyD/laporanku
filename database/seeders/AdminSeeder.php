<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@laporanku.test'],
            [
                'name' => 'Admin LaporanKu',
                'username' => 'admin',
                'password' => Hash::make('AdminLaporanKu123!'),
                'role' => 'admin',
            ],
        );
    }
}
