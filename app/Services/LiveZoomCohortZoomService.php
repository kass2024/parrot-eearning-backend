<?php

namespace App\Services;

use App\Models\LiveZoomCohort;
use App\Support\LiveZoomCohortHelper;
use Illuminate\Support\Facades\Schema;

class LiveZoomCohortZoomService
{
    /** @var array<int, string> */
    protected array $dayNames = [
        'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
    ];

    public function __construct(protected ZoomService $zoom)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureZoomMeeting(LiveZoomCohort $cohort, bool $forceNew = false): array
    {
        if (!$this->zoom->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Zoom API credentials are missing. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET on the server.',
            ];
        }

        if (
            !$forceNew
            && trim((string) ($cohort->zoom_link ?? '')) !== ''
            && trim((string) ($cohort->zoom_meeting_id ?? '')) !== ''
            && ($cohort->session_status ?? 'idle') === 'live'
        ) {
            return [
                'ok' => true,
                'reused' => true,
                'message' => 'Using the active Zoom meeting for this cohort.',
                'zoom' => $this->formatZoomPayload($cohort),
            ];
        }

        $dayLabel = $this->dayNames[(int) $cohort->day_of_week] ?? 'Cohort';
        $topic = trim((string) ($cohort->notes ?? ''));
        if ($topic === '') {
            $topic = "Live Zoom Cohort — {$dayLabel}";
        }

        $duration = $this->durationMinutes($cohort);
        $timezone = (string) ($cohort->timezone ?: 'Africa/Kigali');
        $agenda = $this->buildAgenda($cohort, $dayLabel, $duration, $timezone);

        $meeting = $this->zoom->createInstantMeeting([
            'topic' => $topic,
            'duration' => $duration,
            'agenda' => $agenda,
            'join_before_host' => false,
            'waiting_room' => true,
            'mute_upon_entry' => true,
            'auto_recording' => false,
        ], $this->zoom->hostUserId());

        if ($meeting === null) {
            return [
                'ok' => false,
                'message' => 'Unable to contact Zoom to create the cohort meeting.',
            ];
        }

        if (!empty($meeting['error'])) {
            return [
                'ok' => false,
                'message' => data_get($meeting, 'body.message', 'Zoom rejected meeting creation.'),
                'details' => $meeting['body'] ?? null,
            ];
        }

        $meetingId = isset($meeting['id']) ? (string) $meeting['id'] : null;
        $joinUrl = $meeting['join_url'] ?? null;
        $startUrl = $meeting['start_url'] ?? null;
        $password = $meeting['password'] ?? null;

        if (!$meetingId || !$joinUrl) {
            return [
                'ok' => false,
                'message' => 'Zoom created a meeting but did not return join links.',
            ];
        }

        $description = $this->buildShareDescription($cohort, $dayLabel, $topic, $meetingId, $joinUrl, $password, $duration, $timezone);

        $cohort->zoom_meeting_id = $meetingId;
        $cohort->zoom_link = $joinUrl;
        if (Schema::hasColumn('livezoom_cohort', 'zoom_start_url')) {
            $cohort->zoom_start_url = $startUrl;
        }
        if (Schema::hasColumn('livezoom_cohort', 'zoom_password')) {
            $cohort->zoom_password = $password;
        }
        if (Schema::hasColumn('livezoom_cohort', 'zoom_description')) {
            $cohort->zoom_description = $description;
        }
        $cohort->save();

        return [
            'ok' => true,
            'reused' => false,
            'message' => 'Zoom meeting created automatically.',
            'zoom' => $this->formatZoomPayload($cohort->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatZoomPayload(LiveZoomCohort $cohort): array
    {
        $dayLabel = $this->dayNames[(int) $cohort->day_of_week] ?? 'Cohort';

        return [
            'topic' => trim((string) ($cohort->notes ?? '')) ?: "Live Zoom Cohort — {$dayLabel}",
            'meeting_id' => $cohort->zoom_meeting_id ?? null,
            'join_url' => $cohort->zoom_link ?? null,
            'start_url' => $cohort->zoom_start_url ?? null,
            'password' => $cohort->zoom_password ?? null,
            'description' => $cohort->zoom_description ?? null,
            'share_text' => $cohort->zoom_description ?? null,
            'public_join_url' => LiveZoomCohortHelper::publicJoinUrl($cohort),
            'schedule' => [
                'day' => $dayLabel,
                'start_time' => $cohort->start_time,
                'end_time' => $cohort->end_time,
                'timezone' => $cohort->timezone,
            ],
        ];
    }

    protected function durationMinutes(LiveZoomCohort $cohort): int
    {
        try {
            $start = \Carbon\Carbon::createFromFormat('H:i:s', substr((string) $cohort->start_time, 0, 8));
            $end = \Carbon\Carbon::createFromFormat('H:i:s', substr((string) $cohort->end_time, 0, 8));
            $minutes = $start->diffInMinutes($end);

            return max(15, min(240, $minutes > 0 ? $minutes : 60));
        } catch (\Throwable) {
            return 60;
        }
    }

    protected function buildAgenda(LiveZoomCohort $cohort, string $dayLabel, int $duration, string $timezone): string
    {
        $lines = [
            'Xander Learning Hub — Live Zoom Cohort',
            "Day: {$dayLabel}",
            'Time: ' . substr((string) $cohort->start_time, 0, 5) . ' – ' . substr((string) $cohort->end_time, 0, 5) . " ({$timezone})",
            "Duration: {$duration} minutes",
            'Joiners enter a queue — one participant at a time.',
        ];

        if (trim((string) ($cohort->notes ?? '')) !== '') {
            $lines[] = 'Notes: ' . trim((string) $cohort->notes);
        }

        return implode("\n", $lines);
    }

    protected function buildShareDescription(
        LiveZoomCohort $cohort,
        string $dayLabel,
        string $topic,
        string $meetingId,
        string $joinUrl,
        ?string $password,
        int $duration,
        string $timezone
    ): string {
        $lines = [
            'Xander Learning Hub — Live Zoom Cohort',
            "Topic: {$topic}",
            "Meeting ID: {$meetingId}",
            "Join link: {$joinUrl}",
        ];

        if ($password) {
            $lines[] = "Passcode: {$password}";
        }

        $lines[] = "Schedule: {$dayLabel}, " . substr((string) $cohort->start_time, 0, 5) . ' – ' . substr((string) $cohort->end_time, 0, 5) . " ({$timezone})";
        $lines[] = "Duration: {$duration} minutes";
        $lines[] = 'Public join page (no account required): ' . LiveZoomCohortHelper::publicJoinUrl($cohort);
        $lines[] = 'Please join through the queue when the session is live.';

        return implode("\n", $lines);
    }
}
