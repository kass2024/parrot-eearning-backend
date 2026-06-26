<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Agent;
use App\Models\Student;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\StudentRegistrationEmailService;
use App\Support\PlatformUserService;

class AuthController extends Controller
{
    private function verifyPlainOrHashedPassword($record, string $password): bool
    {
        if (!$record instanceof \Illuminate\Database\Eloquent\Model) {
            return false;
        }

        return PlatformUserService::verifyPassword($record, $password);
    }

    private function resolveAccountByRole(string $role, string $email)
    {
        $normalized = strtolower(trim($role));

        if ($normalized === 'learner' || $normalized === 'student') {
            return Student::where('email', $email)->first();
        }

        if ($normalized === 'instructor' || $normalized === 'agent') {
            return Agent::where('email', $email)->first();
        }

        // admin / staff / other roles -> users table
        return User::where('email', $email)->first();
    }

    public function registerStudent(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:students,email',
            'password' => 'required|string|min:6',
            'country' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string',
            'selected_courses' => 'nullable|array',
            'selected_courses.*' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $student = new Student();
            $student->first_name = $data['first_name'];
            $student->last_name = $data['last_name'] ?? null;
            $student->name = trim($data['first_name'] . ' ' . ($data['last_name'] ?? ''));
            if ($student->name === '') {
                $student->name = $data['email'];
            }
            $student->email = $data['email'];
            // Table columns: status, phone, country, primary_goal are NOT NULL with no default
            // New learners start as Pending until approved in dashboard/students
            $student->status = 'Pending';
            $student->phone = $data['phone'] ?? '';
            $student->country = $data['country'] ?? '';
            $student->primary_goal = $data['primary_goal'] ?? '';
            $plainPassword = $data['password'];
            $student->password = Hash::make($plainPassword);
            $student->save();

            $selectedCourses = $data['selected_courses'] ?? [];
            $emailSent = app(StudentRegistrationEmailService::class)->sendWelcomeEmail(
                $student,
                $plainPassword,
                $selectedCourses
            );

            DB::commit();

            return response()->json([
                'message' => 'Student registered',
                'role' => 'learner',
                'user' => $student,
                'email_sent' => $emailSent,
                'pending_approval' => true,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Failed to register student or send email', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create account due to a server error. Please try again later.',
            ], 500);
        }
    }

    public function registerInstructor(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:500',
        ]);

        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'instructor',
                'status' => 'Pending',
                'phone' => $data['phone'] ?? '',
            ]);

            return response()->json([
                'message' => 'Instructor application submitted',
                'role' => 'instructor',
                'user' => $user->makeHidden(['password']),
                'pending_approval' => true,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to register instructor', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to submit instructor application. Please try again later.',
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $username = trim($data['username']);
        $password = $data['password'];
        $normalizedEmail = PlatformUserService::normalizeEmail($username);
        $isEmailLogin = filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) !== false;

        // Platform staff (admin / instructor / staff) — check before students so duplicate emails do not block dashboard login.
        try {
            $userCandidates = User::query()
                ->when($isEmailLogin, function ($query) use ($normalizedEmail, $username) {
                    $query->where(function ($inner) use ($normalizedEmail, $username) {
                        $inner->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
                            ->orWhere('name', $username);
                    });
                }, function ($query) use ($username) {
                    $query->where('name', $username);
                })
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Database schema error. Run: php artisan schema:rebuild-corrupted --seed --force',
            ], 503);
        }

        foreach ($userCandidates as $user) {
            if (!$this->verifyPlainOrHashedPassword($user, $password)) {
                continue;
            }

            $userStatus = strtolower(trim((string) ($user->status ?? 'active')));
            $userRole = strtolower(trim((string) ($user->role ?? 'admin')));

            if ($userRole === 'instructor' && in_array($userStatus, ['pending', 'inactive', 'rejected'], true)) {
                return response()->json([
                    'message' => 'Your instructor application is pending approval. We will email you when your account is activated.',
                ], 403);
            }

            if (in_array($userStatus, ['inactive', 'rejected'], true)) {
                return response()->json([
                    'message' => 'Your account is not active. Please contact support.',
                ], 403);
            }

            return response()->json([
                'message' => 'Login successful',
                'role' => $user->role ?? 'admin',
                'user' => $user,
            ]);
        }

        // Legacy agents table (instructors)
        $agentCandidates = Agent::query()
            ->when($isEmailLogin, function ($query) use ($normalizedEmail, $username) {
                $query->where(function ($inner) use ($normalizedEmail, $username) {
                    $inner->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
                        ->orWhere('name', $username);
                });
            }, function ($query) use ($username) {
                $query->where('name', $username);
            })
            ->orderByDesc('id')
            ->get();

        foreach ($agentCandidates as $agent) {
            if ($this->verifyPlainOrHashedPassword($agent, $password)) {
                return response()->json([
                    'message' => 'Login successful',
                    'role' => 'instructor',
                    'user' => $agent,
                ]);
            }
        }

        // Learners (students table)
        try {
            $studentCandidates = Student::query()
                ->when($isEmailLogin, function ($query) use ($normalizedEmail) {
                    $query->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail]);
                }, function ($query) use ($username) {
                    $query->where('first_name', $username)
                        ->orWhere('last_name', $username);
                })
                ->orderByDesc('id')
                ->get();
        } catch (QueryException $e) {
            $studentCandidates = collect();
        }

        foreach ($studentCandidates as $student) {
            if (!$this->verifyPlainOrHashedPassword($student, $password)) {
                continue;
            }

            $studentStatus = strtolower(trim((string) ($student->status ?? 'active')));
            if (in_array($studentStatus, ['pending', 'inactive', 'rejected'], true)) {
                return response()->json([
                    'message' => 'Your account is pending admin approval. You will be able to sign in once an administrator approves your registration.',
                ], 403);
            }

            return response()->json([
                'message' => 'Login successful',
                'role' => 'learner',
                'user' => $student,
            ]);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'role' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'new_email' => 'required|email|max:255',
            'current_password' => 'required|string|max:255',
        ]);

        $record = $this->resolveAccountByRole($data['role'], $data['email']);
        if (!$record) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        if (!$this->verifyPlainOrHashedPassword($record, $data['current_password'])) {
            return response()->json(['message' => 'Invalid current password'], 422);
        }

        $newEmail = (string) $data['new_email'];

        // Validate unique email in the correct table
        $normalized = strtolower(trim($data['role']));
        if ($normalized === 'learner' || $normalized === 'student') {
            $exists = Student::where('email', $newEmail)->where('id', '!=', $record->id)->exists();
            if ($exists) {
                return response()->json(['message' => 'Email is already taken'], 422);
            }

            $full = trim((string) $data['name']);
            $parts = preg_split('/\s+/', $full) ?: [];
            $first = (string) array_shift($parts);
            $last = trim(implode(' ', $parts));
            if ($first === '') {
                $first = $record->first_name ?? '';
            }

            $record->first_name = $first;
            $record->last_name = $last !== '' ? $last : null;
            $record->email = $newEmail;
            $record->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $record,
            ]);
        }

        if ($normalized === 'instructor' || $normalized === 'agent') {
            $exists = Agent::where('email', $newEmail)->where('id', '!=', $record->id)->exists();
            if ($exists) {
                return response()->json(['message' => 'Email is already taken'], 422);
            }

            $record->name = (string) $data['name'];
            $record->email = $newEmail;
            $record->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $record,
            ]);
        }

        $exists = User::where('email', $newEmail)->where('id', '!=', $record->id)->exists();
        if ($exists) {
            return response()->json(['message' => 'Email is already taken'], 422);
        }

        $record->name = (string) $data['name'];
        $record->email = $newEmail;
        $record->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $record->makeHidden(['password']),
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'role' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'current_password' => 'required|string|max:255',
            'new_password' => 'required|string|min:8|max:255',
            'new_password_confirmation' => 'required|string|same:new_password',
        ]);

        $record = $this->resolveAccountByRole($data['role'], $data['email']);
        if (!$record) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        if (!$this->verifyPlainOrHashedPassword($record, $data['current_password'])) {
            return response()->json(['message' => 'Invalid current password'], 422);
        }

        $record->password = Hash::make($data['new_password']);
        $record->save();

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }

    public function redirectToGoogle()
    {
        return response()->json([
            'message' => 'Google login redirect not yet fully configured on the server.',
        ], 501);
    }

    public function handleGoogleCallback(Request $request)
    {
        return response()->json([
            'message' => 'Google login callback not yet fully configured on the server.',
        ], 501);
    }
}
