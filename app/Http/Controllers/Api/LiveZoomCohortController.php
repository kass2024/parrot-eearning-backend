<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveZoomCohort;
use App\Models\Student;
use App\Services\LiveZoomCohortQueueService;
use App\Services\LiveZoomCohortZoomService;
use App\Support\LiveZoomCohortHelper;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LiveZoomCohortController extends Controller
{
    public function __construct(
        protected LiveZoomCohortQueueService $queueService,
        protected LiveZoomCohortZoomService $zoomService,
    ) {
    }

    public function index()
    {
        return response()->json(
            LiveZoomCohort::query()
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get(),
            200
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'day_of_week' => 'required|integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'zoom_link' => 'nullable|url|max:2048',
        ]);

        $data['timezone'] = $data['timezone'] ?? 'Africa/Kigali';
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $data['session_status'] = 'idle';

        if ($request->user()) {
            $data['created_by'] = $request->user()->id;
        }

        $slot = LiveZoomCohort::create($data);

        return response()->json([
            'message' => 'Live Zoom cohort created',
            'slot' => $slot,
        ], 201);
    }

    public function update(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        $data = $request->validate([
            'day_of_week' => 'sometimes|required|integer|min:0|max:6',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'zoom_link' => 'nullable|url|max:2048',
        ]);

        if (array_key_exists('start_time', $data) && array_key_exists('end_time', $data)) {
            if ($data['end_time'] <= $data['start_time']) {
                return response()->json(['message' => 'end_time must be after start_time'], 422);
            }
        }

        $liveZoomCohort->fill($data);
        $liveZoomCohort->save();

        return response()->json([
            'message' => 'Live Zoom cohort updated',
            'slot' => $liveZoomCohort,
        ], 200);
    }

    public function destroy(LiveZoomCohort $liveZoomCohort)
    {
        $liveZoomCohort->delete();

        return response()->json([
            'message' => 'Live Zoom cohort deleted',
        ], 200);
    }

    public function startSession(LiveZoomCohort $liveZoomCohort)
    {
        try {
            $zoom = $this->zoomService->ensureZoomMeeting($liveZoomCohort);
            if (empty($zoom['ok'])) {
                return response()->json(['message' => $zoom['message'] ?? 'Could not create Zoom meeting.'], 422);
            }

            $liveZoomCohort->refresh();

            return response()->json([
                'message' => ($zoom['reused'] ?? false)
                    ? 'Live cohort session started.'
                    : 'Zoom meeting created and session started. Share the join details with learners.',
                'zoom' => $zoom['zoom'] ?? $this->zoomService->formatZoomPayload($liveZoomCohort),
                'session' => $this->queueService->startSession($liveZoomCohort),
                'slot' => $liveZoomCohort->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function zoomDetails(LiveZoomCohort $liveZoomCohort)
    {
        if (trim((string) ($liveZoomCohort->zoom_link ?? '')) === '') {
            return response()->json(['message' => 'No Zoom meeting has been created for this cohort yet. Start the session first.'], 404);
        }

        return response()->json([
            'zoom' => $this->zoomService->formatZoomPayload($liveZoomCohort),
            'slot' => $liveZoomCohort,
        ]);
    }

    public function endSession(LiveZoomCohort $liveZoomCohort)
    {
        try {
            return response()->json([
                'message' => 'Live cohort session ended.',
                'session' => $this->queueService->endSession($liveZoomCohort),
                'slot' => $liveZoomCohort->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function adminQueue(LiveZoomCohort $liveZoomCohort)
    {
        try {
            return response()->json($this->queueService->adminQueue($liveZoomCohort));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function releaseCurrent(LiveZoomCohort $liveZoomCohort)
    {
        try {
            $result = $this->queueService->releaseCurrent($liveZoomCohort);

            return response()->json([
                'message' => $result['admitted']
                    ? 'Previous participant released. Next person admitted.'
                    : 'Previous participant released.',
                ...$result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function joinQueue(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request);
            $entry = $this->queueService->joinQueue(
                $liveZoomCohort,
                $participant['student_id'],
                $participant['display_name'],
                $participant['guest_token'],
                $participant['guest_email'],
                $participant['guest_phone'],
            );

            return response()->json([
                'message' => $entry['is_waiting']
                    ? 'You are in the queue.'
                    : 'You can join now.',
                'entry' => $entry,
                'session' => $this->queueService->queueStatus(
                    $liveZoomCohort->fresh(),
                    $participant['student_id'],
                    $participant['guest_token'],
                )['session'] ?? null,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function publicSession(LiveZoomCohort $liveZoomCohort)
    {
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return response()->json([
            'cohort' => [
                'id' => $liveZoomCohort->id,
                'title' => $liveZoomCohort->notes ?: 'Live Zoom Cohort',
                'session_status' => $liveZoomCohort->session_status ?? 'idle',
                'is_live' => ($liveZoomCohort->session_status ?? 'idle') === 'live',
                'day' => $dayNames[(int) $liveZoomCohort->day_of_week] ?? null,
                'start_time' => $liveZoomCohort->start_time,
                'end_time' => $liveZoomCohort->end_time,
                'timezone' => $liveZoomCohort->timezone,
            ],
            'session' => $this->queueService->queueStatus($liveZoomCohort)['session'] ?? null,
            'public_join_url' => LiveZoomCohortHelper::publicJoinUrl($liveZoomCohort),
            'guest_join_allowed' => true,
        ]);
    }

    public function attendance(LiveZoomCohort $liveZoomCohort)
    {
        try {
            return response()->json($this->queueService->attendanceList($liveZoomCohort));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function queueStatus(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);

            return response()->json(
                $this->queueService->queueStatus(
                    $liveZoomCohort,
                    $participant['student_id'] ?? null,
                    $participant['guest_token'] ?? null,
                )
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function leaveQueue(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);

            return response()->json(
                $this->queueService->leaveQueue(
                    $liveZoomCohort,
                    $participant['student_id'] ?? null,
                    $participant['guest_token'] ?? null,
                )
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function markJoined(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);

            return response()->json([
                'entry' => $this->queueService->markJoined(
                    $liveZoomCohort,
                    $participant['student_id'] ?? null,
                    $participant['guest_token'] ?? null,
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function releaseParticipant(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        try {
            $participant = $this->resolveParticipant($request, false);
            $result = $this->queueService->releaseParticipantTurn(
                $liveZoomCohort,
                $participant['student_id'] ?? null,
                $participant['guest_token'] ?? null,
            );

            return response()->json([
                'message' => 'Thank you. The next person in the queue has been notified.',
                ...$result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array{student_id: ?int, guest_token: ?string, display_name: string, guest_email: ?string, guest_phone: ?string}
     */
    protected function resolveParticipant(Request $request, bool $requireIdentity = true): array
    {
        $data = $request->validate([
            'student_id' => 'nullable|integer',
            'guest_token' => 'nullable|string|max:64',
            'guest_name' => 'nullable|string|max:120',
            'guest_email' => 'nullable|email|max:190',
            'guest_phone' => 'nullable|string|max:30',
            'display_name' => 'nullable|string|max:120',
        ]);

        if (!empty($data['student_id'])) {
            $student = Student::query()->find($data['student_id']);
            if (!$student) {
                throw ValidationException::withMessages(['student_id' => 'Student not found.']);
            }

            $displayName = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
            if ($displayName === '') {
                $displayName = (string) ($student->email ?? 'Learner');
            }

            return [
                'student_id' => (int) $student->id,
                'guest_token' => null,
                'display_name' => $displayName,
                'guest_email' => null,
                'guest_phone' => null,
            ];
        }

        $guestToken = trim((string) ($data['guest_token'] ?? ''));
        $guestName = trim((string) ($data['guest_name'] ?? $data['display_name'] ?? ''));
        $guestEmail = trim((string) ($data['guest_email'] ?? ''));
        $guestPhone = trim((string) ($data['guest_phone'] ?? ''));

        if ($requireIdentity) {
            if ($guestName === '') {
                throw ValidationException::withMessages([
                    'guest_name' => 'Please enter your name to join (no account required).',
                ]);
            }

            if ($guestEmail === '') {
                throw ValidationException::withMessages([
                    'guest_email' => 'Please enter your email to join.',
                ]);
            }

            if ($guestPhone === '') {
                throw ValidationException::withMessages([
                    'guest_phone' => 'Please enter your phone number to join.',
                ]);
            }
        }

        if (!$requireIdentity && $guestToken === '' && $guestName === '') {
            return [
                'student_id' => null,
                'guest_token' => null,
                'display_name' => '',
                'guest_email' => null,
                'guest_phone' => null,
            ];
        }

        return [
            'student_id' => null,
            'guest_token' => $guestToken !== '' ? $guestToken : null,
            'display_name' => $guestName,
            'guest_email' => $guestEmail !== '' ? $guestEmail : null,
            'guest_phone' => $guestPhone !== '' ? $guestPhone : null,
        ];
    }
}
