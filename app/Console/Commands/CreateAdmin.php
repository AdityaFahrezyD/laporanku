<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Buat akun administrator secara interaktif';

    public function handle(): int
    {
        $data = [
            'name' => $this->ask('Nama'),
            'username' => $this->ask('Username'),
            'email' => strtolower((string) $this->ask('Email')),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Konfirmasi password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        unset($data['password_confirmation']);
        User::create([...$data, 'role' => 'admin']);
        $this->info('Admin berhasil dibuat.');

        return self::SUCCESS;
    }
}
