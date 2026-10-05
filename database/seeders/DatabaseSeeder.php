<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles are created by the migration; ensure they exist for fresh seeds.
        foreach ([
            Role::SUPER_ADMIN,
            Role::Ministry_ADMIN,
            Role::Ministry_USER,
            Role::Ministry_GUEST,
        ] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'guid' => Str::uuid()->getHex()->toString(),
                'first_name' => 'Test',
                'last_name' => 'Admin',
                'name' => 'Test Admin',
                'disabled' => false,
                'idir_username' => 'TESTADMIN',
                'idir_user_guid' => Str::uuid()->getHex()->toString(),
            ]
        );

        $superAdmin = Role::where('name', Role::SUPER_ADMIN)->first();
        if ($superAdmin !== null) {
            $admin->roles()->syncWithoutDetaching([$superAdmin->id]);
        }
    }
}
