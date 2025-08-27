<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\WechatClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->withPersonalTeam()->create();
        $email = "admin@admin.com";
        User::factory()->withPersonalTeam()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($email),
        ]);

        WechatClient::create([
            'token' => 'win11',
            'endpoint' => 'http://100.96.141.89:8001',
            'file_url' => 'http://localhost:8004',
        ]);
    }
}
