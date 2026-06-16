<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\Student;
use App\Models\User;
use App\Models\WebinarSetting;
use App\Services\ZoomMeetingSdkService;
use App\Services\ZoomService;
use App\Support\CourseMaterialHelper;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoomEmbedController extends Controller
{
    public function __construct(
        protected ZoomMeetingSdkService $sdkService,
        protected ZoomService $zoomService,
    ) {
    }

    public function config(): JsonResponse
    {
        $embed = $this->sdkService->configurationStatus();
        $api = $this->zoomService->configurationStatus();

        return response()->json([
            'embed_enabled' => $embed['embed_ready'],
            'sdk_key' => $embed['embed_ready'] ? config('services.zoom.sdk_key') : null,
            'sdk_key_preview' => $embed['sdk_key_preview'] ?? null,
            'api_ready' => $api['api_ready'],
            'host_user_id' => $api['host_user_id'] ?? null,
            'frontend_base' => FrontendUrl::base(),
            'platforms' => ['web', 'android'],
        ]);
    }

    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'meeting_number' => 'nullable|string|max:32',
            'user_name' => 'nullable|string|max:120',
            'role' => 'nullable|integer|in:0,1',
            'password' => 'nullable|string|max:64',
            'instructor_email' => 'nullable|email',
            'student_id' => 'nullable|integer|exists:students,id',
            'webinar_host' => 'nullable|boolean',
        ]);

        $role = (int) ($data['role'] ?? 0);

        if (!empty($data['material_id'])) {
            return $this->materialAuth(
                CourseMaterial::query()->findOrFail((int) $data['material_id']),
                $role,
                $data
            );
        }

        if (!empty($data['webinar_host'])) {
            return $this->buildWebinarHostAuth($data);
        }

        $meetingNumber = preg_replace('/\D+/', '', (string) ($data['meeting_number'] ?? ''));
        if ($meetingNumber === '') {
            return response()->json(['message' => 'Provide material_id, meeting_number, or webinar_host.'], 422);
        }

        $userName = trim((string) ($data['user_name'] ?? ''));
        if ($userName === '') {
            $userName = $role === 1 ? 'Host' : 'Guest';
        }

        try {
            $payload = $this->sdkService->buildJoinPayload(
                $meetingNumber,
                $userName,
                $role,
                $data['password'] ?? '',
                $this->hostZakForRole($role),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sdk' => $payload]);
    }

    public function learnerMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
        ]);

        return $this->materialAuth($material, 0, $data);
    }

    public function instructorMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        return $this->materialAuth($material, 1, $data);
    }

    public function webinarHostAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_name' => 'nullable|string|max:120',
        ]);

        return $this->buildWebinarHostAuth($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function materialAuth(CourseMaterial $material, int $role, array $data): JsonResponse
    {
        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class.'], 422);
        }

        $meetingId = CourseMaterialHelper::meetingId($material);
        if (!$meetingId) {
            return response()->json(['message' => 'No Zoom meeting ID for this session.'], 422);
        }

        $password = CourseMaterialHelper::meetingPassword($material) ?? '';

        if ($role === 1) {
            $email = trim((string) ($data['instructor_email'] ?? ''));
            if ($email === '') {
                return response()->json(['message' => 'Instructor email is required to host.'], 422);
            }

            $instructor = User::query()->where('email', $email)->where('role', 'instructor')->first();
            if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $material->course_id)->exists()) {
                return response()->json(['message' => 'You are not authorized to host this session.'], 403);
            }

            $userName = trim((string) ($instructor->name ?? '')) ?: 'Instructor';
        } else {
            $studentId = (int) ($data['student_id'] ?? 0);
            if ($studentId <= 0) {
                return response()->json(['message' => 'Student ID is required to join.'], 422);
            }

            $enrolled = CourseEnrollment::query()
                ->where('course_id', $material->course_id)
                ->where('student_id', $studentId)
                ->whereIn('status', ['paid', 'completed'])
                ->exists();

            if (!$enrolled) {
                return response()->json(['message' => 'You are not enrolled in this course.'], 403);
            }

            $state = CourseMaterialHelper::liveSessionState($material);
            if (empty($state['can_join'])) {
                return response()->json(['message' => 'This class is not live yet. Wait for the instructor to start.'], 403);
            }

            $student = Student::query()->find($studentId);
            if (!$student) {
                return response()->json(['message' => 'Student not found.'], 404);
            }

            $userName = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
            if ($userName === '') {
                $userName = (string) ($student->email ?? 'Learner');
            }
        }

        try {
            $payload = $this->sdkService->buildJoinPayload(
                $meetingId,
                $userName,
                $role,
                $password,
                $this->hostZakForRole($role),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sdk' => $payload]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildWebinarHostAuth(array $data): JsonResponse
    {
        $settings = WebinarSetting::current();
        $meetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        if ($meetingId === '') {
            return response()->json(['message' => 'No webinar meeting configured. Prepare the webinar first.'], 422);
        }

        $userName = trim((string) ($data['user_name'] ?? 'Host'));

        try {
            $zakResult = $this->zoomService->fetchHostZakToken();
            $payload = $this->sdkService->buildJoinPayload(
                $meetingId,
                $userName !== '' ? $userName : 'Host',
                1,
                '',
                !empty($zakResult['ok']) ? ($zakResult['token'] ?? null) : null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sdk' => $payload]);
    }

    protected function hostZakForRole(int $role): ?string
    {
        if ($role !== 1) {
            return null;
        }

        $result = $this->zoomService->fetchHostZakToken();
        if (empty($result['ok'])) {
            return null;
        }

        $token = $result['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
