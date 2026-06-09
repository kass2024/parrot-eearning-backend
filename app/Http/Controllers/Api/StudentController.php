<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\StudentRegisteredMail;

class StudentController extends Controller
{
    public function index()
    {
        return response()->json(Student::orderByDesc('id')->get(), 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email',
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:255',
            'selected_courses' => 'nullable|array',
            'selected_courses.*' => 'nullable|string|max:255',
        ]);

        $student = Student::create([
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'email'      => $validated['email'],
            'status'     => $validated['status'] ?? 'Active',
            // ensure NOT NULL columns always get a value
            'phone'      => $validated['phone'] ?? '',
            'country'    => $validated['country'] ?? '',
            'primary_goal' => $validated['primary_goal'] ?? '',
            // default password for admin-created students
            'password'   => '12345678',
        ]);

        // Try to send welcome email with default password and any selected courses
        try {
            $selectedCourses = $validated['selected_courses'] ?? [];
            Mail::to($student->email)->send(new StudentRegisteredMail($student, '12345678', $selectedCourses));
        } catch (\Throwable $e) {
            Log::error('Failed to send student registration email from StudentController@store', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
            // Do not fail the request if email fails here
        }

        return response()->json(['message' => 'Student created', 'student' => $student], 201);
    }

    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email,' . $student->id,
            'status' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:255',
        ]);

        // Apply all validated fields (only columns that exist in the table)
        $student->fill($validated);
        $student->save();
        return response()->json(['message' => 'Student updated', 'student' => $student]);
    }

    public function destroy($id)
    {
        Student::findOrFail($id)->delete();
        return response()->json(['message' => 'Student deleted']);
    }

    public function uploadDocument(Request $request)
    {
        $validated = $request->validate([
            'document' => 'required|file|max:10240|mimes:png,jpg,jpeg,pdf', // 10MB
            'student_id' => 'nullable|integer|exists:students,id',
        ]);

        $file = $validated['document'];
        // store under public/uploads so path begins with 'uploads/...'
        $path = $file->store('uploads', 'public');
        $url = asset('storage/' . $path);

        // If a student_id is provided, persist on that student row
        if (!empty($validated['student_id'])) {
            $student = Student::find($validated['student_id']);
            if ($student) {
                $student->document_path = $path;
                $student->document_url = $url;
                $student->save();
            }
        }

        return response()->json([
            'message' => 'Document uploaded',
            'path' => $path,
            'url' => $url,
        ], 201);
    }

    public function testEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $toEmail = $request->input('email');

        try {
            Mail::send('emails.welcome', ['student' => null, 'password' => null], function ($message) use ($toEmail) {
                $message->to($toEmail)
                    ->subject('Test email from Parrot-Canada');
            });

            return response()->json([
                'message' => 'Email send request done (check inbox/spam).',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send test email', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send email.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
