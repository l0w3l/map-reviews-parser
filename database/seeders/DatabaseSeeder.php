<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = config('yandex.seed_password');
        if (! is_string($password) || $password === '') {
            throw new \RuntimeException('Set SEED_USER_PASSWORD before seeding.');
        }
        User::updateOrCreate(['email' => config('yandex.seed_email')], [
            'name' => 'Demo User', 'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);
    }
}
