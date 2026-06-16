<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\User;
use App\Services\Quiz\QuizAnalyticsService;
use App\Services\Quiz\QuizAntiCheatService;
use App\Services\QuizAiService;
use App\Support\QuizMaterialHelper;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function __construct(
        protected QuizAiService $quizAi,
        protected QuizAntiCheatService $antiCheat,
        protected QuizAnalyticsService $analytics,
    ) {
    }

    public function aiStatus()
    {
        return response()->json([
            'configured' => $this->quizAi->isConfigured(),
            'claude' => $this->quizAi->hasClaude(),
            'gemini' => $this->quizAi->hasGemini(),
            'generation_provider' => config('services.quiz_ai.generation_provider', 'gemini'),
            'generation_model' => config('services.quiz_ai.generation_provider', 'gemini') === 'gemini'
                ? (config('services.quiz_ai.generation_model') ?: config('services.gemini.model', 'gemini-2.0-flash'))
                : (config('services.quiz_ai.claude_generation_model') ?: config('services.anthropic.model', 'claude-sonnet-4-6')),
            'fallback_provider' => config('services.quiz_ai.generation_provider', 'gemini') === 'gemini' ? 'claude' : 'gemini',
            'fallback_model' => config('services.quiz_ai.generation_provider', 'gemini') === 'gemini'
                ? (config('services.quiz_ai.claude_generation_model') ?: config('services.anthropic.model', 'claude-sonnet-4-6'))
                : (config('services.quiz_ai.generation_model') ?: config('services.gemini.model', 'gemini-2.0-flash')),
            'marking_primary' => config('services.quiz_ai.marking_primary', 'gemini'),
            'marking_secondary' => config('services.quiz_ai.marking_secondary', 'claude'),
            'local_document_extraction' => !filter_var(config('services.quiz_ai.use_ai_knowledge_map', false), FILTER_VALIDATE_BOOL),
            'embeddings_enabled' => filter_var(config('services.quiz_ai.enable_embeddings', false), FILTER_VALIDATE_BOOL),
            'quiz_modes' => [
                'quick' => 5,
                'standard' => 10,
                'comprehensive' => 20,
                'final_exam' => 50,
            ],
            'supported_types' => [
                'multiple_choice', 'multiple_response', 'true_false', 'matching',
                'fill_blank', 'short_answer', 'long_answer', 'essay',
                'case_study', 'problem_solving', 'scenario', 'hots',
            ],
            'bloom_levels' => ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'],
        ]);
    }

    public function analyzeMaterial(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'material_id' => 'required|integer|exists:course_materials,id',
            'force' => 'nullable|boolean',
        ]);

        $material = CourseMaterial::findOrFail($data['material_id']);
        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $material->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        try {
            $analysis = app(\App\Services\Quiz\QuizMaterialAnalysisService::class)
                ->analyze($material, (bool) ($data['force'] ?? false));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($analysis);
    }

    public function courseTopics(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'instructor_email' => 'nullable|email',
        ]);

        $course = Course::findOrFail($data['course_id']);

        if (!empty($data['instructor_email'])) {
            $instructor = User::query()
                ->where('email', $data['instructor_email'])
                ->where('role', 'instructor')
                ->first();

            if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $course->id)->exists()) {
                return response()->json(['message' => 'You are not assigned to this course.'], 403);
            }
        }

        $studyMaterials = CourseMaterial::query()
            ->where('course_id', $course->id)
            ->whereNotIn('type', ['quiz', 'assessment', 'zoom'])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        $topicGroups = QuizMaterialHelper::buildTopicGroups($studyMaterials);
        $pdfMaterials = $studyMaterials
            ->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m))
            ->map(fn (CourseMaterial $m) => QuizMaterialHelper::materialSummary($m))
            ->values();

        $topics = collect($topicGroups)->pluck('label')->filter()->unique()->values();

        if ($topics->isEmpty() && $studyMaterials->isNotEmpty()) {
            $topics = $studyMaterials
                ->map(fn (CourseMaterial $m) => trim((string) ($m->title ?? '')))
                ->filter()
                ->unique()
                ->values();
        }

        if ($topics->isEmpty() && $course->title) {
            $topics = collect([$course->title]);
        }

        return response()->json([
            'course_id' => $course->id,
            'course_title' => $course->title,
            'has_materials' => $studyMaterials->isNotEmpty(),
            'materials_count' => $studyMaterials->count(),
            'materials' => $studyMaterials->map(fn (CourseMaterial $m) => QuizMaterialHelper::materialSummary($m))->values(),
            'pdf_materials' => $pdfMaterials,
            'topic_groups' => $topicGroups,
            'topics' => $topics,
        ]);
    }

    public function generate(Request $request)
    {
        if (!$this->quizAi->isConfigured()) {
            return response()->json([
                'message' => 'AI is not configured. Add ANTHROPIC_API_KEY and/or GEMINI_API_KEY to .env.',
            ], 503);
        }

        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
            'topic' => 'required|string|max:255',
            'question_count' => 'required|integer|min:1|max:100',
            'difficulty' => 'nullable|string|in:easy,medium,hard,mixed',
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'quiz_mode' => 'nullable|string|in:quick,standard,comprehensive,final_exam,custom',
            'bloom_levels' => 'nullable|array',
            'bloom_levels.*' => 'string|in:remember,understand,apply,analyze,evaluate,create',
            'question_types' => 'nullable|array',
            'question_types.*' => 'string|in:multiple_choice,multiple_response,true_false,matching,fill_blank,short_answer,long_answer,essay,case_study,problem_solving,scenario,hots',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $course = Course::findOrFail($data['course_id']);

        $studyMaterials = CourseMaterial::query()
            ->where('course_id', $course->id)
            ->whereNotIn('type', ['quiz', 'assessment', 'zoom'])
            ->get();

        if ($studyMaterials->isEmpty()) {
            return response()->json([
                'message' => 'No course materials found. Upload PDFs or lessons first, with module/chapter/topic in the title or description.',
            ], 422);
        }

        $pdfMaterials = $studyMaterials->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m));
        $materialId = isset($data['material_id']) ? (int) $data['material_id'] : null;
        $topicMaterials = QuizMaterialHelper::materialsForTopic($studyMaterials, $data['topic']);
        $topicPdfCount = collect($topicMaterials)->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m))->count();

        if ($pdfMaterials->count() > 1 && !$materialId && $topicPdfCount === 0) {
            return response()->json([
                'message' => 'This course has multiple PDF materials. Select a topic linked to a PDF or choose a source PDF.',
                'pdf_materials' => $pdfMaterials->map(fn (CourseMaterial $m) => QuizMaterialHelper::materialSummary($m))->values(),
            ], 422);
        }

        if (!$materialId && $topicPdfCount === 1) {
            $materialId = collect($topicMaterials)
                ->first(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m))?->id;
        }

        if (!$materialId && $pdfMaterials->count() === 1) {
            $materialId = $pdfMaterials->first()->id;
        }

        if ($materialId) {
            $selected = $studyMaterials->firstWhere('id', $materialId);
            if (!$selected) {
                return response()->json(['message' => 'Selected material does not belong to this course.'], 422);
            }
        }

        try {
            $result = $this->quizAi->generateQuestions(
                $course,
                $data['topic'],
                (int) $data['question_count'],
                $data['difficulty'] ?? 'medium',
                $materialId,
                [
                    'quiz_mode' => $data['quiz_mode'] ?? 'custom',
                    'bloom_levels' => $data['bloom_levels'] ?? null,
                    'question_types' => $data['question_types'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'topic' => $data['topic'],
            'material_id' => $materialId,
            'provider' => $result['provider'],
            'questions' => $result['questions'],
            'knowledge_map' => $result['knowledge_map'] ?? null,
            'rejected_count' => count($result['rejected'] ?? []),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
            'title' => 'required|string|max:255',
            'topic' => 'required|string|max:255',
            'description' => 'nullable|string',
            'passing_score' => 'nullable|integer|min:40|max:100',
            'time_limit_minutes' => 'nullable|integer|min:1|max:240',
            'questions' => 'required|array|min:1',
            'questions.*.id' => 'required|string|max:50',
            'questions.*.type' => 'required|string|max:50',
            'questions.*.question' => 'required|string|max:2000',
            'questions.*.points' => 'nullable|integer|min:1|max:20',
            'ai_generated' => 'nullable|boolean',
            'generation_provider' => 'nullable|string|max:50',
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'status' => 'nullable|string|in:draft,published',
            'published_student_ids' => 'nullable|array',
            'published_student_ids.*' => 'integer|exists:students,id',
            'anti_cheat' => 'nullable|array',
            'anti_cheat.shuffle_questions' => 'nullable|boolean',
            'anti_cheat.shuffle_options' => 'nullable|boolean',
            'anti_cheat.deliver_count' => 'nullable|integer|min:0|max:100',
            'anti_cheat.max_attempts' => 'nullable|integer|min:0|max:20',
            'anti_cheat.detect_tab_switch' => 'nullable|boolean',
            'question_pool' => 'nullable|array',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $status = $data['status'] ?? 'draft';
        $publishedStudentIds = array_values(array_unique(array_map('intval', $data['published_student_ids'] ?? [])));

        $metadata = $this->buildQuizMetadata($data, $status, $publishedStudentIds);

        $quiz = CourseMaterial::create([
            'course_id' => $data['course_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? ('Topic: ' . $data['topic']),
            'type' => 'quiz',
            'resource_url' => null,
            'metadata' => $metadata,
            'sort_order' => 0,
        ]);

        $message = $status === 'published'
            ? 'Quiz published. Selected learners can now take it.'
            : 'Quiz saved as draft. Publish when you are ready.';

        return response()->json([
            'message' => $message,
            'quiz' => $this->formatQuizRow($quiz->load('course')),
        ], 201);
    }

    public function showForInstructor(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $email = $request->query('instructor_email');
        if (!$email) {
            return response()->json(['message' => 'instructor_email is required'], 400);
        }

        $instructor = User::query()
            ->where('email', $email)
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);

        return response()->json([
            'quiz' => array_merge($this->formatQuizRow($quiz->load('course')), [
                'description' => $quiz->description,
                'questions' => $meta['questions'] ?? [],
                'generation_provider' => $meta['generation_provider'] ?? null,
                'source_material_id' => $meta['source_material_id'] ?? null,
                'published_student_ids' => QuizMaterialHelper::publishedStudentIds($quiz),
            ]),
        ]);
    }

    public function update(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate([
            'instructor_email' => 'required|email',
            'title' => 'required|string|max:255',
            'topic' => 'required|string|max:255',
            'description' => 'nullable|string',
            'passing_score' => 'nullable|integer|min:40|max:100',
            'time_limit_minutes' => 'nullable|integer|min:1|max:240',
            'questions' => 'required|array|min:1',
            'questions.*.id' => 'required|string|max:50',
            'questions.*.type' => 'required|string|max:50',
            'questions.*.question' => 'required|string|max:2000',
            'questions.*.points' => 'nullable|integer|min:1|max:20',
            'ai_generated' => 'nullable|boolean',
            'generation_provider' => 'nullable|string|max:50',
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'status' => 'nullable|string|in:draft,published',
            'published_student_ids' => 'nullable|array',
            'published_student_ids.*' => 'integer|exists:students,id',
            'anti_cheat' => 'nullable|array',
            'anti_cheat.shuffle_questions' => 'nullable|boolean',
            'anti_cheat.shuffle_options' => 'nullable|boolean',
            'anti_cheat.deliver_count' => 'nullable|integer|min:0|max:100',
            'anti_cheat.max_attempts' => 'nullable|integer|min:0|max:20',
            'anti_cheat.detect_tab_switch' => 'nullable|boolean',
            'question_pool' => 'nullable|array',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $existingMeta = QuizMaterialHelper::meta($quiz);
        $status = $data['status'] ?? QuizMaterialHelper::quizStatus($quiz);
        $publishedStudentIds = array_key_exists('published_student_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['published_student_ids'] ?? [])))
            : QuizMaterialHelper::publishedStudentIds($quiz);

        $metadata = $this->buildQuizMetadata($data, $status, $publishedStudentIds, $existingMeta);

        $quiz->title = $data['title'];
        $quiz->description = $data['description'] ?? ('Topic: ' . $data['topic']);
        $quiz->metadata = $metadata;
        $quiz->save();

        return response()->json([
            'message' => $status === 'published' ? 'Quiz updated and published.' : 'Quiz updated.',
            'quiz' => $this->formatQuizRow($quiz->load('course')),
        ]);
    }

    public function publish(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate([
            'instructor_email' => 'required|email',
            'published_student_ids' => 'nullable|array',
            'published_student_ids.*' => 'integer|exists:students,id',
            'time_limit_minutes' => 'nullable|integer|min:1|max:240',
            'passing_score' => 'nullable|integer|min:40|max:100',
        ]);

        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        if (empty($meta['questions'])) {
            return response()->json(['message' => 'Add questions before publishing.'], 422);
        }

        $publishedStudentIds = array_values(array_unique(array_map('intval', $data['published_student_ids'] ?? [])));
        $meta['status'] = 'published';
        $meta['published_at'] = $meta['published_at'] ?? now()->toIso8601String();
        $meta['published_student_ids'] = $publishedStudentIds;

        if (array_key_exists('time_limit_minutes', $data)) {
            $meta['time_limit_minutes'] = $data['time_limit_minutes'] !== null
                ? (int) $data['time_limit_minutes']
                : null;
        }
        if (array_key_exists('passing_score', $data)) {
            $meta['passing_score'] = (int) ($data['passing_score'] ?? 70);
        }

        $quiz->metadata = $meta;
        $quiz->save();

        return response()->json([
            'message' => empty($publishedStudentIds)
                ? 'Quiz published to all enrolled learners.'
                : 'Quiz published to selected learners.',
            'quiz' => $this->formatQuizRow($quiz->load('course')),
        ]);
    }

    public function showForLearner(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $studentId = (int) $request->query('student_id');
        if (!$studentId) {
            return response()->json(['message' => 'student_id is required'], 400);
        }

        $student = Student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $enrolled = CourseEnrollment::query()
            ->where('student_id', $student->id)
            ->where('course_id', $quiz->course_id)
            ->whereIn('status', ['paid', 'completed', 'active', 'approved', 'enrolled'])
            ->exists();

        if (!$enrolled) {
            return response()->json(['message' => 'You are not enrolled in this course.'], 403);
        }

        if (!QuizMaterialHelper::isVisibleToStudent($quiz, $studentId)) {
            return response()->json(['message' => 'This quiz is not available to you yet.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        $allQuestions = $meta['questions'] ?? [];

        if (empty($allQuestions)) {
            return response()->json(['message' => 'This quiz has no questions yet.'], 422);
        }

        $attemptCount = QuizAttempt::query()
            ->where('student_id', $studentId)
            ->where('course_material_id', $quiz->id)
            ->count();

        if ($this->antiCheat->maxAttemptsReached($meta, $studentId, $quiz->id, $attemptCount)) {
            return response()->json(['message' => 'Maximum attempts reached for this quiz.'], 422);
        }

        $delivery = $this->antiCheat->prepareDelivery($meta, $studentId);
        $questions = $delivery['questions'];
        $antiCheat = is_array($meta['anti_cheat'] ?? null) ? $meta['anti_cheat'] : [];

        $attempts = QuizAttempt::query()
            ->where('student_id', $student->id)
            ->where('course_material_id', $quiz->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'score', 'max_score', 'percentage', 'passed', 'marked_at', 'created_at']);

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'course_id' => $quiz->course_id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'topic' => $meta['topic'] ?? null,
                'passing_score' => (int) ($meta['passing_score'] ?? 70),
                'time_limit_minutes' => QuizMaterialHelper::timeLimitMinutes($quiz),
                'question_count' => count($questions),
                'max_attempts' => (int) ($antiCheat['max_attempts'] ?? 0),
                'attempts_used' => $attemptCount,
                'detect_tab_switch' => (bool) ($antiCheat['detect_tab_switch'] ?? true),
                'server_now' => now()->toIso8601String(),
            ],
            'questions' => $this->quizAi->stripAnswersForLearner($questions),
            'delivered_question_ids' => $delivery['delivered_ids'],
            'attempts' => $attempts,
        ]);
    }

    public function analytics(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate(['instructor_email' => 'required|email']);
        $instructor = User::query()
            ->where('email', $data['instructor_email'])
            ->where('role', 'instructor')
            ->first();

        if (!$instructor || !$instructor->assignedCourses()->where('courses.id', $quiz->course_id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        return response()->json($this->analytics->forQuiz($quiz));
    }

    public function submit(Request $request, CourseMaterial $quiz)
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return response()->json(['message' => 'Not a quiz.'], 404);
        }

        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'answers' => 'required|array',
            'started_at' => 'nullable|date',
            'auto_submitted' => 'nullable|boolean',
            'tab_switch_count' => 'nullable|integer|min:0|max:500',
            'focus_lost_seconds' => 'nullable|integer|min:0|max:86400',
            'delivered_question_ids' => 'nullable|array',
            'delivered_question_ids.*' => 'string|max:50',
        ]);

        $student = Student::findOrFail($data['student_id']);

        $enrolled = CourseEnrollment::query()
            ->where('student_id', $student->id)
            ->where('course_id', $quiz->course_id)
            ->whereIn('status', ['paid', 'completed', 'active', 'approved', 'enrolled'])
            ->exists();

        if (!$enrolled) {
            return response()->json(['message' => 'You are not enrolled in this course.'], 403);
        }

        if (!QuizMaterialHelper::isVisibleToStudent($quiz, $student->id)) {
            return response()->json(['message' => 'This quiz is not available to you.'], 403);
        }

        $meta = QuizMaterialHelper::meta($quiz);
        $allQuestions = $meta['questions'] ?? [];

        if (empty($allQuestions)) {
            return response()->json(['message' => 'This quiz has no questions.'], 422);
        }

        $attemptCount = QuizAttempt::query()
            ->where('student_id', $student->id)
            ->where('course_material_id', $quiz->id)
            ->count();

        if ($this->antiCheat->maxAttemptsReached($meta, $student->id, $quiz->id, $attemptCount)) {
            return response()->json(['message' => 'Maximum attempts reached for this quiz.'], 422);
        }

        $deliveredIds = array_values(array_filter($data['delivered_question_ids'] ?? []));
        $questions = $allQuestions;
        if ($deliveredIds !== []) {
            $questions = array_values(array_filter($allQuestions, fn ($q) => in_array((string) ($q['id'] ?? ''), $deliveredIds, true)));
        }

        if (empty($questions)) {
            $delivery = $this->antiCheat->prepareDelivery($meta, $student->id);
            $questions = $delivery['questions'];
            $deliveredIds = $delivery['delivered_ids'];
        }

        $timeLimit = QuizMaterialHelper::timeLimitMinutes($quiz);
        if ($timeLimit && !empty($data['started_at'])) {
            $startedAt = \Carbon\Carbon::parse($data['started_at']);
            $deadline = $startedAt->copy()->addMinutes($timeLimit);
            if (now()->greaterThan($deadline->copy()->addSeconds(30))) {
                return response()->json(['message' => 'Time is up. This quiz has ended.'], 422);
            }
        }

        $passingScore = (int) ($meta['passing_score'] ?? 70);
        $markResult = $this->quizAi->markAttempt($questions, $data['answers'], $passingScore);

        if (!empty($data['auto_submitted'])) {
            $markResult['feedback'] = 'Time expired — your quiz was submitted automatically. '
                . 'Unanswered questions were marked incorrect. '
                . ($markResult['feedback'] ?? '');
        }

        $attempt = QuizAttempt::create([
            'student_id' => $student->id,
            'course_material_id' => $quiz->id,
            'answers' => $data['answers'],
            'question_results' => $markResult['question_results'],
            'score' => $markResult['score'],
            'max_score' => $markResult['max_score'],
            'percentage' => $markResult['percentage'],
            'passed' => $markResult['passed'],
            'feedback' => $markResult['feedback'],
            'marking_provider' => $markResult['marking_provider'],
            'tab_switch_count' => (int) ($data['tab_switch_count'] ?? 0),
            'focus_lost_seconds' => (int) ($data['focus_lost_seconds'] ?? 0),
            'integrity_flags' => [
                'auto_submitted' => (bool) ($data['auto_submitted'] ?? false),
                'tab_switch_count' => (int) ($data['tab_switch_count'] ?? 0),
            ],
            'delivered_question_ids' => $deliveredIds,
            'marked_at' => now(),
        ]);

        return response()->json([
            'message' => $markResult['passed'] ? 'Quiz passed!' : 'Quiz submitted.',
            'attempt' => $attempt,
            'results' => $markResult,
            'analytics' => $markResult['analytics'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $publishedStudentIds
     * @return array<string, mixed>
     */
    protected function buildQuizMetadata(array $data, string $status, array $publishedStudentIds, ?array $existingMeta = null): array
    {
        $existingMeta = $existingMeta ?? [];
        $publishedAt = $existingMeta['published_at'] ?? null;

        if ($status === 'published') {
            $publishedAt = $publishedAt ?: now()->toIso8601String();
        } else {
            $publishedAt = null;
            $publishedStudentIds = [];
        }

        return [
            'topic' => $data['topic'],
            'passing_score' => (int) ($data['passing_score'] ?? 70),
            'time_limit_minutes' => array_key_exists('time_limit_minutes', $data) && $data['time_limit_minutes'] !== null
                ? (int) $data['time_limit_minutes']
                : null,
            'questions' => $data['questions'],
            'question_pool' => $data['question_pool'] ?? ($existingMeta['question_pool'] ?? $data['questions']),
            'anti_cheat' => array_merge([
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'deliver_count' => 0,
                'max_attempts' => 0,
                'detect_tab_switch' => true,
            ], is_array($data['anti_cheat'] ?? null) ? $data['anti_cheat'] : ($existingMeta['anti_cheat'] ?? [])),
            'ai_generated' => (bool) ($data['ai_generated'] ?? ($existingMeta['ai_generated'] ?? false)),
            'generation_provider' => $data['generation_provider'] ?? ($existingMeta['generation_provider'] ?? null),
            'source_material_id' => isset($data['material_id'])
                ? (int) $data['material_id']
                : ($existingMeta['source_material_id'] ?? null),
            'status' => $status,
            'published_student_ids' => $publishedStudentIds,
            'published_at' => $publishedAt,
            'marking' => $existingMeta['marking'] ?? [
                'primary' => config('services.quiz_ai.marking_primary', 'claude'),
                'secondary' => config('services.quiz_ai.marking_secondary', 'gemini'),
            ],
        ];
    }

    protected function formatQuizRow(CourseMaterial $m): array
    {
        $meta = QuizMaterialHelper::meta($m);
        $publishedIds = QuizMaterialHelper::publishedStudentIds($m);

        return [
            'id' => $m->id,
            'course_id' => $m->course_id,
            'course_title' => $m->course->title ?? 'Course',
            'title' => $m->title,
            'description' => $m->description,
            'topic' => $meta['topic'] ?? null,
            'type' => $m->type,
            'resource_url' => $m->resource_url,
            'question_count' => count($meta['questions'] ?? []),
            'passing_score' => (int) ($meta['passing_score'] ?? 70),
            'time_limit_minutes' => QuizMaterialHelper::timeLimitMinutes($m),
            'status' => QuizMaterialHelper::quizStatus($m),
            'published_student_count' => count($publishedIds),
            'published_student_ids' => $publishedIds,
            'publish_to_all' => QuizMaterialHelper::isPublished($m) && empty($publishedIds),
            'ai_generated' => (bool) ($meta['ai_generated'] ?? false),
            'created_at' => $m->created_at?->toIso8601String(),
            'published_at' => $meta['published_at'] ?? null,
        ];
    }
}
