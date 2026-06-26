<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public static function seedPassword(): string
    {
        $value = (string) config('platform.seed_password');

        return trim($value, " \t\n\r\0\x0B'\"");
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
     * @return list<array{normalized_email: string, count: int}>
     */
    public static function findNormalizedDuplicateGroups(): array
    {
        if (!Schema::hasTable('users')) {
            return [];
        }

        return DB::table('users')
            ->selectRaw('LOWER(TRIM(email)) as normalized_email, COUNT(*) as count')
            ->groupByRaw('LOWER(TRIM(email))')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('normalized_email')
            ->get()
            ->map(fn ($row) => [
                'normalized_email' => (string) $row->normalized_email,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    public static function normalizeStoredEmails(): int
    {
        if (!Schema::hasTable('users')) {
            return 0;
        }

        if (self::findNormalizedDuplicateGroups() !== []) {
            return 0;
        }

        $updated = 0;

        foreach (User::query()->get(['id', 'email']) as $user) {
            $normalized = self::normalizeEmail((string) $user->email);
            if ($normalized === '' || $normalized === (string) $user->email) {
                continue;
            }

            $conflict = DB::table('users')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->where('id', '!=', $user->id)
                ->exists();

            if ($conflict) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update(['email' => $normalized]);
            $updated++;
        }

        return $updated;
    }

    /**
     * Remove duplicate users that share the same normalized email (keeps newest row).
     */
    public static function dedupeDuplicateEmails(): int
    {
        if (!Schema::hasTable('users')) {
            return 0;
        }

        $removed = 0;

        while (self::findNormalizedDuplicateGroups() !== []) {
            foreach (self::findNormalizedDuplicateGroups() as $group) {
                $normalized = $group['normalized_email'];

                $rows = DB::table('users')
                    ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
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

                if ((string) $keeper->email !== $normalized) {
                    DB::table('users')->where('id', $keeper->id)->update(['email' => $normalized]);
                }
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
        $plainPassword = trim((string) ($plainPassword ?? self::seedPassword()), " \t\n\r\0\x0B'\"");
        $hash = Hash::make($plainPassword);
        $updated = 0;

        foreach (self::PLATFORM_EMAILS as $email) {
            $normalized = self::normalizeEmail($email);
            $count = DB::table('users')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                ->update([
                    'password' => $hash,
                    'updated_at' => now(),
                ]);

            $updated += $count;
        }

        return $updated;
    }

    public static function resetPasswordForEmail(string $email, string $plainPassword): int
    {
        $normalized = self::normalizeEmail($email);
        $hash = Hash::make(trim($plainPassword, " \t\n\r\0\x0B'\""));

        return DB::table('users')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->update([
                'password' => $hash,
                'updated_at' => now(),
            ]);
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
