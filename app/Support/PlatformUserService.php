<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformUserService
{
    /** @var list<string> */
    public const PLATFORM_EMAILS = [
        'infos@parrotglobalstudyacademy.ca',
        'instructor@parrotglobalstudyacademy.ca',
        'staff@parrotglobalstudyacademy.ca',
        'instructor2@parrotglobalstudyacademy.ca',
    ];

    /** @var list<string> */
    public const LEGACY_EMAILS = [
        'info@xanderglobalscholars.com',
        'admin@parrot.com',
        'instructor@parrot.com',
        'staff@parrot.com',
    ];

    /** @var list<array{table: string, column: string}> */
    private const USER_FOREIGN_KEYS = [
        ['table' => 'assign_cours', 'column' => 'user_id'],
        ['table' => 'meeting_registrations', 'column' => 'user_id'],
        ['table' => 'livezoom_cohort', 'column' => 'created_by'],
        ['table' => 'available_schedules', 'column' => 'created_by'],
    ];

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function storedPasswordHash(Model $record): string
    {
        if (method_exists($record, 'getRawOriginal')) {
            $raw = $record->getRawOriginal('password');
            if ($raw !== null && $raw !== '') {
                return (string) $raw;
            }
        }

        return (string) ($record->password ?? '');
    }

    public static function verifyPassword(Model $record, string $password, string $defaultPassword = '12345678'): bool
    {
        $stored = self::storedPasswordHash($record);

        if ($stored === '') {
            return hash_equals($defaultPassword, $password);
        }

        if (password_get_info($stored)['algo'] !== 0) {
            return password_verify($password, $stored);
        }

        return hash_equals($stored, $password);
    }

    /**
     * Remove duplicate users that share the same email (keeps newest row).
     */
    public static function dedupeDuplicateEmails(): int
    {
        if (!Schema::hasTable('users')) {
            return 0;
        }

        $removed = 0;

        $duplicateEmails = DB::table('users')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email');

        foreach ($duplicateEmails as $email) {
            $rows = DB::table('users')
                ->where('email', $email)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            $keeper = $rows->first();
            if ($keeper === null) {
                continue;
            }

            foreach ($rows->slice(1) as $duplicate) {
                self::reassignUserForeignKeys((int) $duplicate->id, (int) $keeper->id);
                DB::table('users')->where('id', $duplicate->id)->delete();
                $removed++;
            }
        }

        return $removed;
    }

    public static function deleteLegacyEmails(): int
    {
        if (!Schema::hasTable('users')) {
            return 0;
        }

        return User::query()->whereIn('email', self::LEGACY_EMAILS)->delete();
    }

    public static function ensureUniqueEmailIndex(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM users'))
            ->filter(fn ($row) => ($row->Column_name ?? null) === 'email' && (int) ($row->Non_unique ?? 1) === 0);

        if ($indexes->isNotEmpty()) {
            return;
        }

        self::dedupeDuplicateEmails();

        Schema::table('users', function ($table) {
            $table->unique('email');
        });
    }

    public static function resetPlatformUserPasswords(?string $plainPassword = null): int
    {
        $plainPassword ??= (string) config('platform.seed_password');
        $updated = 0;

        foreach (self::PLATFORM_EMAILS as $email) {
            $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [self::normalizeEmail($email)])->first();
            if (!$user) {
                continue;
            }

            $user->password = $plainPassword;
            $user->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public static function debugLogin(string $username, string $password): array
    {
        $normalized = self::normalizeEmail($username);
        $report = [
            'username' => $username,
            'normalized_email' => $normalized,
            'expected_seed_password' => (string) config('platform.seed_password'),
            'users' => [],
            'students' => [],
            'agents' => [],
            'duplicate_emails' => [],
        ];

        if (Schema::hasTable('users')) {
            $report['duplicate_emails'] = DB::table('users')
                ->select('email', DB::raw('COUNT(*) as count'))
                ->groupBy('email')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->map(fn ($row) => ['email' => $row->email, 'count' => (int) $row->count])
                ->all();

            $users = User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->orWhere('name', $username)
                ->orderBy('id')
                ->get();

            foreach ($users as $user) {
                $stored = self::storedPasswordHash($user);
                $report['users'][] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'updated_at' => (string) $user->updated_at,
                    'hash_prefix' => substr($stored, 0, 7),
                    'password_matches' => self::verifyPassword($user, $password),
                ];
            }
        }

        if (Schema::hasTable('students')) {
            $students = \App\Models\Student::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->orderBy('id')
                ->get();

            foreach ($students as $student) {
                $stored = self::storedPasswordHash($student);
                $report['students'][] = [
                    'id' => $student->id,
                    'status' => $student->status,
                    'hash_prefix' => substr($stored, 0, 7),
                    'password_matches' => self::verifyPassword($student, $password),
                ];
            }
        }

        return $report;
    }

    private static function reassignUserForeignKeys(int $fromUserId, int $toUserId): void
    {
        foreach (self::USER_FOREIGN_KEYS as $fk) {
            if (!Schema::hasTable($fk['table']) || !Schema::hasColumn($fk['table'], $fk['column'])) {
                continue;
            }

            DB::table($fk['table'])
                ->where($fk['column'], $fromUserId)
                ->update([$fk['column'] => $toUserId]);
        }
    }
}
