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
        User::updateOrCreate(
            ['email' => 'admin@parrot.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('1234'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'instructor@parrot.com'],
            [
                'name' => 'Instructor User',
                'password' => bcrypt('1234'),
                'role' => 'instructor',
            ]
        );

        User::updateOrCreate(
            ['email' => 'staff@parrot.com'],
            [
                'name' => 'Staff User',
                'password' => bcrypt('1234'),
                'role' => 'staff',
            ]
        );

        User::updateOrCreate(
            ['email' => 'info@xanderglobalscholars.com'],
            [
                'name' => 'Xander Global Scholars',
                'password' => bcrypt('12345678'),
                'role' => 'admin',
                'status' => 'Active',
            ]
        );

        $this->call([
            AvailableScheduleSeeder::class,
            LearningHubDemoSeeder::class,
        ]);
    }
}
