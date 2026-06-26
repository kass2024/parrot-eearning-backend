<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PlatformUserService;
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
        $plainPassword = PlatformUserService::seedPassword();

        PlatformUserService::dedupeDuplicateEmails();
        PlatformUserService::deleteLegacyEmails();

        User::updateOrCreate(
            ['email' => 'infos@parrotglobalstudyacademy.ca'],
            [
                'name' => 'Parrot Canada Visa Consultant',
                'password' => $plainPassword,
                'role' => 'admin',
                'status' => 'Active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'instructor@parrotglobalstudyacademy.ca'],
            [
                'name' => 'Instructor User',
                'password' => $plainPassword,
                'role' => 'instructor',
                'status' => 'Active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'staff@parrotglobalstudyacademy.ca'],
            [
                'name' => 'Staff User',
                'password' => $plainPassword,
                'role' => 'staff',
                'status' => 'Active',
            ]
        );

        $this->call([
            AvailableScheduleSeeder::class,
            LearningHubDemoSeeder::class,
        ]);
    }
}
