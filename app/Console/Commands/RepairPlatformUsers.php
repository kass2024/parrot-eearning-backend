<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Student;
use App\Models\User;
use App\Support\PlatformUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairPlatformUsers extends Command
{
    protected $signature = 'users:repair-platform
                            {--password= : Plain password to set on platform accounts}
                            {--debug-email= : Email to test against --debug-password}
                            {--debug-password= : Password to test with --debug-email}';

    protected $description = 'Deduplicate platform users, enforce unique emails, and reset admin passwords';

    public function handle(): int
    {
        if (!Schema::hasTable('users')) {
            $this->error('users table does not exist.');

            return self::FAILURE;
        }

        $debugEmail = (string) ($this->option('debug-email') ?? '');
        $debugPassword = (string) ($this->option('debug-password') ?? '');
        if ($debugEmail !== '' && $debugPassword !== '') {
            $this->printLoginDebug($debugEmail, $debugPassword);

            return self::SUCCESS;
        }

        $duplicates = DB::table('users')
            ->select('email', DB::raw('COUNT(*) as count'))
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $this->warn('Duplicate emails found:');
            foreach ($duplicates as $row) {
                $this->line("  {$row->email} ({$row->count} rows)");
            }
        }

        $removed = PlatformUserService::dedupeDuplicateEmails();
        $legacyRemoved = PlatformUserService::deleteLegacyEmails();
        $this->info("Removed {$removed} duplicate user row(s) and {$legacyRemoved} legacy account(s).");

        try {
            PlatformUserService::ensureUniqueEmailIndex();
            $this->info('Unique index on users.email is in place.');
        } catch (\Throwable $e) {
            $this->warn('Could not add unique email index: ' . $e->getMessage());
        }

        $plain = (string) ($this->option('password') ?: config('platform.seed_password'));
        $updated = PlatformUserService::resetPlatformUserPasswords($plain);
        $this->info("Reset password on {$updated} platform account(s).");

        $this->newLine();
        $this->line('Platform logins:');
        foreach (PlatformUserService::PLATFORM_EMAILS as $email) {
            $this->line("  {$email}");
        }
        $this->line("  password: {$plain}");

        return self::SUCCESS;
    }

    private function printLoginDebug(string $email, string $password): void
    {
        $normalized = PlatformUserService::normalizeEmail($email);

        $this->info("Login debug for: {$email}");
        $this->line('Normalized email: ' . $normalized);
        $this->line('Seed password config: ' . (string) config('platform.seed_password'));
        $this->newLine();

        $dupes = DB::table('users')
            ->select('email', DB::raw('COUNT(*) as count'))
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($dupes->isEmpty()) {
            $this->info('No duplicate user emails.');
        } else {
            $this->error('Duplicate user emails (login may hit the wrong row):');
            foreach ($dupes as $row) {
                $this->line("  {$row->email}: {$row->count} rows");
            }
        }

        $this->newLine();
        $this->line('users table:');
        $users = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('  No user rows for this email.');
        }

        foreach ($users as $user) {
            $stored = PlatformUserService::storedPasswordHash($user);
            $matches = PlatformUserService::verifyPassword($user, $password) ? 'YES' : 'NO';
            $this->line("  id={$user->id} role={$user->role} status={$user->status} updated={$user->updated_at} hash={$stored} matches={$matches}");
        }

        if (Schema::hasTable('students')) {
            $this->newLine();
            $this->line('students table (checked before users in old login — can block admin):');
            $students = Student::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->orderBy('id')
                ->get();

            if ($students->isEmpty()) {
                $this->line('  No student rows for this email.');
            }

            foreach ($students as $student) {
                $stored = PlatformUserService::storedPasswordHash($student);
                $matches = PlatformUserService::verifyPassword($student, $password) ? 'YES' : 'NO';
                $this->line("  id={$student->id} status={$student->status} hash={$stored} matches={$matches}");
            }
        }

        if (Schema::hasTable('agents')) {
            $this->newLine();
            $this->line('agents table:');
            $agents = Agent::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->orderBy('id')
                ->get();

            if ($agents->isEmpty()) {
                $this->line('  No agent rows for this email.');
            }

            foreach ($agents as $agent) {
                $stored = PlatformUserService::storedPasswordHash($agent);
                $matches = PlatformUserService::verifyPassword($agent, $password) ? 'YES' : 'NO';
                $this->line("  id={$agent->id} hash={$stored} matches={$matches}");
            }
        }

        $firstUser = User::query()
            ->where('email', $email)
            ->orWhere('name', $email)
            ->first();

        $this->newLine();
        $this->line('Old login query (first() only): id=' . ($firstUser?->id ?? 'none'));
    }
}
