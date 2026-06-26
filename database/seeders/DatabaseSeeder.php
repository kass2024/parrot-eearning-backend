<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = bcrypt((string) config('platform.seed_password'));

        $this->migrateLegacyPlatformUsers($password);

        User::updateOrCreate(
            ['email' => 'infos@parrotglobalstudyacademy.ca'],
            [
                'name' => 'Parrot Canada Visa Consultant',
                'password' => $password,
                'role' => 'admin',
                'status' => 'Active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'instructor@parrotglobalstudyacademy.ca'],
            [
                'name' => 'Instructor User',
                'password' => $password,
                'role' => 'instructor',
                'status' => 'Active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'staff@parrotglobalstudyacademy.ca'],
            [
                'name' => 'Staff User',
                'password' => $password,
                'role' => 'staff',
                'status' => 'Active',
            ]
        );

        $this->call([
            AvailableScheduleSeeder::class,
            LearningHubDemoSeeder::class,
        ]);
    }

    private function migrateLegacyPlatformUsers(string $passwordHash): void
    {
        $legacyEmails = [
            'info@xanderglobalscholars.com',
            'admin@parrot.com',
            'instructor@parrot.com',
            'staff@parrot.com',
        ];

        User::whereIn('email', $legacyEmails)->delete();
    }
}
