<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        if (! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->superAdmin()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        if (! User::query()->where('email', 'support@example.com')->exists()) {
            User::factory()->support()->create([
                'name' => 'Support Agent',
                'email' => 'support@example.com',
            ]);
        }

        $this->call(DemoTenantSeeder::class);
    }
}
