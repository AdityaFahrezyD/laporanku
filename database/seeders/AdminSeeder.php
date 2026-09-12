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
            ['email' => 'adityafahrezyd01@gmail.com'],
            [
                'name' => 'Admin Adit',
                'username' => 'adit',
                'password' => Hash::make('@Adit08062004'),
                'role' => 'admin',
            ],
        );
    }
}
