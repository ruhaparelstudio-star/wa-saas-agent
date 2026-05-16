<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL', 'admin@platform.com');
        $password = env('SUPERADMIN_PASSWORD', 'Demo123!');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($password),
                'role' => UserRole::SUPERADMIN,
                'is_active' => true,
            ]
        );

        $this->command->info("Superadmin created: {$email}");
    }
}
