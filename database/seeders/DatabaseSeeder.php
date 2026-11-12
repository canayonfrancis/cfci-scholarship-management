<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a repeatable Coordinator account for local testing.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function () {
            $coordinator = User::firstOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test Coordinator',
                    'email_verified_at' => now(),
                    'password' => 'admin123',
                ],
            );

            if ($coordinator->wasRecentlyCreated) {
                $coordinator->roles()->firstOrCreate(['role' => 'coordinator']);
            }
        });
    }
}
