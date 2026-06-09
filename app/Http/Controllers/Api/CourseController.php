<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\User;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\CourseMaterial;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\CourseAppliedMail;
use App\Mail\CourseEnrollmentApprovedMail;
use App\Mail\CourseEnrollmentRejectedMail;
use App\Mail\CourseClassScheduledMail;
use App\Mail\StaffClassScheduledMail;
use App\Services\ZoomService;

class CourseController extends Controller
{
    protected ZoomService $zoom;

    public function __construct(ZoomService $zoom)
    {
        $this->zoom = $zoom;
    }
    public function index()
    {
        return response()->json(Course::orderByDesc('id')->get(), 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            // accept any nullable value; we'll only treat it as an upload if it's a real file
            'image' => 'nullable',
        ]);

        $payload = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'duration' => $data['duration'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'status' => $data['status'] ?? 'Active',
        ];

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $payload['image'] = asset('storage/' . $path);
        }

        $course = Course::create($payload);

        return response()->json([
            'message' => 'Course created',
            'course' => $course,
        ], 201);
    }

    public function update(Request $request, Course $course)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            // accept any nullable value; only handle as file when present as upload
            'image' => 'nullable',
        ]);

        $updateData = $data;
        // Remove image from mass-assign data; handle file separately
        unset($updateData['image']);

        $course->fill($updateData);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $course->image = asset('storage/' . $path);
        }

        $course->save();

        return response()->json([
            'message' => 'Course updated',
            'course' => $course,
        ]);
    }

    public function destroy(Course $course)
    {
        $course->delete();
        return response()->json(['message' => 'Course deleted']);
    }

    public function assignToUser(Request $request, Course $course)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        $user->assignedCourses()->syncWithoutDetaching([$course->id]);

        return response()->json([
            'message' => 'Course assigned to user',
        ]);
    }

    public function unassignFromUser(Request $request, Course $course)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        $user->assignedCourses()->detach($course->id);

        return response()->json([
            'message' => 'Course unassigned from user',
        ]);
    }

    public function enroll(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'level' => 'nullable|string|max:255',
        ]);

        // Check if the student is already enrolled in this course
        $existing = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You have already applied for this course.',
                'enrollment' => $existing,
            ], 200);
        }

        $enrollment = CourseEnrollment::create([
            'student_id' => $data['student_id'],
            'course_id' => $course->id,
            'status' => 'enrolled',
            'level' => $data['level'] ?? null,
        ]);

        // Send notification email to the student about the course application
        try {
            $student = Student::find($data['student_id']);
            if ($student && $student->email) {
                Mail::to($student->email)->send(new CourseAppliedMail($student, $course, $enrollment->level));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send course application email', [
                'student_id' => $data['student_id'],
                'course_id' => $course->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Enrolled successfully',
            'enrollment' => $enrollment,
        ], 201);
    }

    public function scheduleClass(Request $request, Course $course)
    {
        $data = $request->validate([
            'start_time' => 'required|date',
            'zoom_link' => 'nullable|url',
            'notes' => 'nullable|string',
            'staff_id' => 'nullable|exists:users,id',
            'notify_only' => 'nullable|boolean',
        ]);

        $zoomJoinLink = $data['zoom_link'] ?? null;
        $zoomStartUrl = null;

        // If no Zoom link provided, create a Zoom meeting using the logged-in instructor as host
        if (!$zoomJoinLink) {
            $user = $request->user();
            $hostId = $user && !empty($user->email)
                ? (string) $user->email
                : (string) config('services.zoom.host_user_id', 'me');

            $zoomPayload = [
                'topic'      => $course->title ?? 'Course Class',
                'start_time' => $data['start_time'],
                'agenda'     => $data['notes'] ?? '',
            ];

            $zoomData = $this->zoom->createMeeting($zoomPayload, $hostId);

            if ($zoomData === null) {
                return response()->json([
                    'message' => 'Unable to create Zoom meeting for this class.',
                ], 500);
            }

            if (isset($zoomData['error']) && !empty($zoomData['error'])) {
                $body = $zoomData['body'] ?? [];
                $message = $body['message'] ?? 'Zoom returned an error while creating the meeting.';

                return response()->json([
                    'message' => $message,
                    'zoom' => $body,
                ], 422);
            }

            $zoomJoinLink = $zoomData['join_url'] ?? null;
            $zoomStartUrl = $zoomData['start_url'] ?? null;

            if (!$zoomJoinLink) {
                return response()->json([
                    'message' => 'Zoom meeting created but join link was not returned.',
                    'zoom' => $zoomData,
                ], 500);
            }
        }

        // Notify staff / instructor about this scheduled class (no learner emails here)
        try {
            $staff = null;
            if (!empty($data['staff_id'])) {
                $staff = User::find($data['staff_id']);
            } elseif ($request->user()) {
                $staff = $request->user();
            }

            if ($staff && !empty($staff->email)) {
                Mail::to($staff->email)->send(
                    new StaffClassScheduledMail(
                        $staff,
                        $course,
                        $data['start_time'],
                        $zoomJoinLink,
                        $data['notes'] ?? null
                    )
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send staff class schedule email', [
                'course_id' => $course->id,
                'staff_id' => $data['staff_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        // Optionally avoid creating a CourseMaterial entry if this is just a notification
        $notifyOnly = !empty($data['notify_only']);
        if (!$notifyOnly) {
            $materialTitle = 'Zoom session - ' . $data['start_time'];
            CourseMaterial::create([
                'course_id'    => $course->id,
                'title'        => $materialTitle,
                'description'  => $data['notes'] ?? null,
                'type'         => 'zoom',
                'resource_url' => $zoomStartUrl ?? $zoomJoinLink,
                'sort_order'   => 0,
            ]);
        }

        return response()->json([
            'message' => 'Class scheduled, Zoom meeting prepared, and staff notified (where possible).',
            'zoom_join_url' => $zoomJoinLink,
            'zoom_start_url' => $zoomStartUrl,
        ]);
    }

    public function enrolledStudents(Course $course)
    {
        $enrollments = CourseEnrollment::with('student')
            ->where('course_id', $course->id)
            ->get();

        $students = $enrollments->map(function ($enrollment) {
            $student = $enrollment->student;
            if (!$student) {
                return null;
            }

            return [
                'id' => $student->id,
                'first_name' => $student->first_name ?? null,
                'last_name' => $student->last_name ?? null,
                'name' => $student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')),
                'email' => $student->email,
            ];
        })->filter()->values();

        return response()->json([
            'students' => $students,
        ]);
    }

    public function markPaid(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        $enrollment->status = 'paid';
        $enrollment->save();

        // Notify learner that their enrollment has been approved/activated
        try {
            $student = Student::find($data['student_id']);
            if ($student && $student->email) {
                Mail::to($student->email)->send(new CourseEnrollmentApprovedMail($student, $course));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send course enrollment approved email', [
                'student_id' => $data['student_id'],
                'course_id' => $course->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Enrollment marked as paid.',
            'enrollment' => $enrollment,
        ]);
    }

    public function rejectEnrollment(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'reason' => 'nullable|string|max:2000',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        $enrollment->status = 'rejected';
        $enrollment->save();

        // Notify learner that their enrollment was rejected with optional reason
        try {
            $student = Student::find($data['student_id']);
            if ($student && $student->email) {
                Mail::to($student->email)->send(
                    new CourseEnrollmentRejectedMail($student, $course, $data['reason'] ?? null)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send course enrollment rejected email', [
                'student_id' => $data['student_id'],
                'course_id' => $course->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Enrollment rejected.',
            'enrollment' => $enrollment,
        ]);
    }

    public function studentEnrollments(Student $student)
    {
        $enrollments = CourseEnrollment::where('student_id', $student->id)
            ->get(['course_id', 'status', 'level']);

        return response()->json([
            'enrollments' => $enrollments->map(function ($enrollment) {
                return [
                    'course_id' => $enrollment->course_id,
                    'status' => $enrollment->status,
                    'level' => $enrollment->level,
                ];
            }),
        ]);
    }
}
