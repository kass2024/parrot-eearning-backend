<?php

namespace App\Services;

use App\Models\LiveZoomCohort;
use App\Models\LiveZoomCohortQueueEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LiveZoomCohortQueueService
{
    /** @var array<int, string> */
    protected array $activeStatuses = ['waiting', 'admitted', 'in_meeting'];

    public function isQueueEnabled(): bool
    {
        return Schema::hasTable('livezoom_cohort_queue_entries')
            && Schema::hasColumn('livezoom_cohort', 'session_status');
    }

    /**
     * @return array<string, mixed>
     */
    public function startSession(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        if (trim((string) ($cohort->zoom_link ?? '')) === '') {
            throw new \RuntimeException('Add a Zoom link before starting this cohort session.');
        }

        $cohort->session_status = 'live';
        $cohort->session_started_at = now();
        $cohort->session_ended_at = null;
        $cohort->current_queue_entry_id = null;
        $cohort->save();

        return $this->sessionPayload($cohort);
    }

    /**
     * @return array<string, mixed>
     */
    public function endSession(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        DB::transaction(function () use ($cohort) {
            LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->where('status', 'waiting')
                ->update(['status' => 'skipped', 'released_at' => now()]);

            LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->whereIn('status', ['admitted', 'in_meeting'])
                ->update(['status' => 'left', 'released_at' => now()]);

            $cohort->session_status = 'ended';
            $cohort->session_ended_at = now();
            $cohort->current_queue_entry_id = null;
            $cohort->save();
        });

        return $this->sessionPayload($cohort->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function joinQueue(
        LiveZoomCohort $cohort,
        ?int $studentId,
        string $displayName,
        ?string $guestToken = null,
        ?string $guestEmail = null,
        ?string $guestPhone = null
    ): array {
        $this->assertQueueEnabled();

        if (($cohort->session_status ?? 'idle') !== 'live') {
            throw new \RuntimeException('This cohort session has not started yet. Please wait for the host.');
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new \RuntimeException('Please enter your name to join the queue.');
        }

        if ($studentId === null) {
            $guestToken = $guestToken ?: (string) Str::uuid();
            $guestEmail = trim((string) $guestEmail);
            $guestPhone = trim((string) $guestPhone);

            if ($guestEmail === '') {
                throw new \RuntimeException('Please enter your email to join the queue.');
            }

            if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Please enter a valid email address.');
            }

            if ($guestPhone === '') {
                throw new \RuntimeException('Please enter your phone number to join the queue.');
            }
        }

        $existing = $this->findActiveEntry($cohort, $studentId, $guestToken);
        if ($existing) {
            return $this->entryPayload($cohort, $existing);
        }

        return DB::transaction(function () use ($cohort, $studentId, $displayName, $guestToken, $guestEmail, $guestPhone) {
            $hasActiveParticipant = LiveZoomCohortQueueEntry::query()
                ->where('livezoom_cohort_id', $cohort->id)
                ->whereIn('status', ['admitted', 'in_meeting'])
                ->exists();

            $entry = LiveZoomCohortQueueEntry::create([
                'livezoom_cohort_id' => $cohort->id,
                'student_id' => $studentId,
                'guest_token' => $studentId === null ? $guestToken : null,
                'guest_email' => $studentId === null ? $guestEmail : null,
                'guest_phone' => $studentId === null ? $guestPhone : null,
                'display_name' => $displayName,
                'status' => $hasActiveParticipant ? 'waiting' : 'admitted',
                'queue_position' => $hasActiveParticipant
                    ? $this->nextWaitingPosition($cohort)
                    : 0,
                'joined_at' => now(),
                'admitted_at' => $hasActiveParticipant ? null : now(),
            ]);

            if (!$hasActiveParticipant) {
                $cohort->current_queue_entry_id = $entry->id;
                $cohort->save();
            }

            return $this->entryPayload($cohort->fresh(), $entry->fresh());
        });
    }

    /**
     * Backwards-compatible learner join.
     *
     * @return array<string, mixed>
     */
    public function joinQueueAsStudent(LiveZoomCohort $cohort, int $studentId, string $displayName): array
    {
        return $this->joinQueue($cohort, $studentId, $displayName, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function queueStatus(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $this->assertQueueEnabled();

        $entry = null;
        if ($studentId || $guestToken) {
            $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);
        }

        $payload = $this->sessionPayload($cohort);
        $payload['my_entry'] = $entry ? $this->entryPayload($cohort, $entry) : null;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function leaveQueue(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $this->assertQueueEnabled();

        $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);

        if (!$entry) {
            return ['message' => 'You are not in the queue.', 'session' => $this->sessionPayload($cohort)];
        }

        DB::transaction(function () use ($cohort, $entry) {
            $wasActive = in_array($entry->status, ['admitted', 'in_meeting'], true);
            $entry->status = 'cancelled';
            $entry->released_at = now();
            $entry->save();

            if ($wasActive) {
                $cohort->current_queue_entry_id = null;
                $cohort->save();
                $this->admitNextWaiting($cohort);
            } else {
                $this->recalculateWaitingPositions($cohort);
            }
        });

        return [
            'message' => 'Removed from queue.',
            'session' => $this->sessionPayload($cohort->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function markJoined(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);
        if (!$entry || $entry->status !== 'admitted') {
            throw new \RuntimeException('You are not currently admitted to join.');
        }

        $entry->status = 'in_meeting';
        if (!$entry->attended_at) {
            $entry->attended_at = now();
        }
        $entry->save();

        return $this->entryPayload($cohort, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    public function releaseParticipantTurn(LiveZoomCohort $cohort, ?int $studentId = null, ?string $guestToken = null): array
    {
        $entry = $this->findActiveEntry($cohort, $studentId, $guestToken);
        if (!$entry || !in_array($entry->status, ['admitted', 'in_meeting'], true)) {
            throw new \RuntimeException('You are not the active participant.');
        }

        $current = $this->currentParticipant($cohort);
        if (!$current || $current->id !== $entry->id) {
            throw new \RuntimeException('You are not the active participant.');
        }

        $result = $this->releaseCurrent($cohort);

        return $result ?? [
            'released' => $this->entryPayload($cohort, $entry),
            'admitted' => null,
            'session' => $this->sessionPayload($cohort->fresh()),
        ];
    }

    protected function findActiveEntry(
        LiveZoomCohort $cohort,
        ?int $studentId,
        ?string $guestToken
    ): ?LiveZoomCohortQueueEntry {
        $query = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereIn('status', $this->activeStatuses);

        if ($studentId) {
            return $query->where('student_id', $studentId)->first();
        }

        if ($guestToken) {
            return $query
                ->whereNull('student_id')
                ->where('guest_token', $guestToken)
                ->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function releaseCurrent(LiveZoomCohort $cohort): ?array
    {
        $this->assertQueueEnabled();

        return DB::transaction(function () use ($cohort) {
            $current = $this->currentParticipant($cohort);
            if ($current) {
                $current->status = 'left';
                $current->released_at = now();
                $current->save();
            }

            $cohort->current_queue_entry_id = null;
            $cohort->save();

            $next = $this->admitNextWaiting($cohort);

            return [
                'released' => $current ? $this->entryPayload($cohort, $current) : null,
                'admitted' => $next ? $this->entryPayload($cohort, $next) : null,
                'session' => $this->sessionPayload($cohort->fresh()),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function adminQueue(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        $waiting = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (LiveZoomCohortQueueEntry $entry) => $this->entryPayload($cohort, $entry));

        $current = $this->currentParticipant($cohort);

        return [
            'session' => $this->sessionPayload($cohort),
            'current' => $current ? $this->entryPayload($cohort, $current) : null,
            'waiting' => $waiting,
            'waiting_count' => $waiting->count(),
        ];
    }

    protected function admitNextWaiting(LiveZoomCohort $cohort): ?LiveZoomCohortQueueEntry
    {
        $next = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->orderBy('joined_at')
            ->lockForUpdate()
            ->first();

        if (!$next) {
            $this->recalculateWaitingPositions($cohort);

            return null;
        }

        $next->status = 'admitted';
        $next->queue_position = 0;
        $next->admitted_at = now();
        $next->save();

        $cohort->current_queue_entry_id = $next->id;
        $cohort->save();

        $this->recalculateWaitingPositions($cohort);

        return $next;
    }

    protected function recalculateWaitingPositions(LiveZoomCohort $cohort): void
    {
        $waiting = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->orderBy('joined_at')
            ->get();

        $position = 1;
        foreach ($waiting as $entry) {
            $entry->queue_position = $position;
            $entry->save();
            $position++;
        }
    }

    protected function nextWaitingPosition(LiveZoomCohort $cohort): int
    {
        return (int) LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->count() + 1;
    }

    protected function currentParticipant(LiveZoomCohort $cohort): ?LiveZoomCohortQueueEntry
    {
        if ($cohort->current_queue_entry_id) {
            $entry = LiveZoomCohortQueueEntry::query()->find($cohort->current_queue_entry_id);
            if ($entry && in_array($entry->status, ['admitted', 'in_meeting'], true)) {
                return $entry;
            }
        }

        return LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereIn('status', ['admitted', 'in_meeting'])
            ->orderBy('admitted_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function attendanceList(LiveZoomCohort $cohort): array
    {
        $this->assertQueueEnabled();

        $entries = LiveZoomCohortQueueEntry::query()
            ->with('student:id,first_name,last_name,email,phone')
            ->where('livezoom_cohort_id', $cohort->id)
            ->whereNotIn('status', ['cancelled', 'skipped'])
            ->orderBy('joined_at')
            ->get()
            ->map(fn (LiveZoomCohortQueueEntry $entry) => $this->attendancePayload($entry));

        return [
            'cohort_id' => $cohort->id,
            'cohort_title' => $cohort->notes ?: 'Live Zoom Cohort',
            'session_status' => $cohort->session_status ?? 'idle',
            'session_started_at' => $cohort->session_started_at?->toIso8601String(),
            'session_ended_at' => $cohort->session_ended_at?->toIso8601String(),
            'total' => $entries->count(),
            'attended_count' => $entries->where('attended', true)->count(),
            'entries' => $entries->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function attendancePayload(LiveZoomCohortQueueEntry $entry): array
    {
        $isGuest = $entry->student_id === null;
        $email = $isGuest ? $entry->guest_email : ($entry->student?->email ?? null);
        $phone = $isGuest ? $entry->guest_phone : ($entry->student?->phone ?? null);
        $attended = $entry->attended_at !== null
            || in_array($entry->status, ['in_meeting', 'left'], true);

        return [
            'id' => $entry->id,
            'display_name' => $entry->display_name,
            'email' => $email,
            'phone' => $phone,
            'is_guest' => $isGuest,
            'student_id' => $entry->student_id,
            'status' => $entry->status,
            'attended' => $attended,
            'joined_at' => $entry->joined_at?->toIso8601String(),
            'admitted_at' => $entry->admitted_at?->toIso8601String(),
            'attended_at' => $entry->attended_at?->toIso8601String(),
            'released_at' => $entry->released_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function entryPayload(LiveZoomCohort $cohort, LiveZoomCohortQueueEntry $entry): array
    {
        $ahead = max(0, (int) $entry->queue_position - 1);
        $canJoin = in_array($entry->status, ['admitted', 'in_meeting'], true)
            && ($cohort->session_status ?? 'idle') === 'live';

        return [
            'id' => $entry->id,
            'student_id' => $entry->student_id,
            'guest_token' => $entry->guest_token,
            'guest_email' => $entry->guest_email,
            'guest_phone' => $entry->guest_phone,
            'is_guest' => $entry->student_id === null,
            'display_name' => $entry->display_name,
            'status' => $entry->status,
            'queue_position' => (int) $entry->queue_position,
            'ahead_count' => $ahead,
            'is_waiting' => $entry->status === 'waiting',
            'is_admitted' => in_array($entry->status, ['admitted', 'in_meeting'], true),
            'can_join' => $canJoin,
            'join_url' => $canJoin ? ($cohort->zoom_link ?? null) : null,
            'joined_at' => $entry->joined_at?->toIso8601String(),
            'admitted_at' => $entry->admitted_at?->toIso8601String(),
            'message' => $this->statusMessage($entry),
        ];
    }

    protected function statusMessage(LiveZoomCohortQueueEntry $entry): string
    {
        return match ($entry->status) {
            'waiting' => $entry->queue_position <= 1
                ? 'You are next in line. Please stay on this page.'
                : 'You are #' . $entry->queue_position . ' in the queue. Waiting for the previous participant to finish.',
            'admitted' => 'It is your turn. Click Join Zoom to enter the session.',
            'in_meeting' => 'You are in the session.',
            default => 'Queue status updated.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionPayload(LiveZoomCohort $cohort): array
    {
        $waitingCount = LiveZoomCohortQueueEntry::query()
            ->where('livezoom_cohort_id', $cohort->id)
            ->where('status', 'waiting')
            ->count();

        $current = $this->currentParticipant($cohort);

        return [
            'cohort_id' => $cohort->id,
            'session_status' => $cohort->session_status ?? 'idle',
            'session_started_at' => $cohort->session_started_at?->toIso8601String(),
            'session_ended_at' => $cohort->session_ended_at?->toIso8601String(),
            'is_live' => ($cohort->session_status ?? 'idle') === 'live',
            'waiting_count' => $waitingCount,
            'has_active_participant' => $current !== null,
            'current_participant' => $current?->display_name,
            'queue_enabled' => true,
        ];
    }

    protected function assertQueueEnabled(): void
    {
        if (!$this->isQueueEnabled()) {
            throw new \RuntimeException('Live cohort queue is not available. Run database migrations.');
        }
    }
}
