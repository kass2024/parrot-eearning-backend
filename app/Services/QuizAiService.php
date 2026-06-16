<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Services\Quiz\QuizDocumentEngine;
use App\Services\Quiz\QuizMaterialAnalysisService;
use App\Services\Quiz\QuizQuestionValidator;
use App\Support\QuizMaterialHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QuizAiService
{
    protected ?string $lastAiError = null;

    /** @var array<string> */
    protected array $supportedTypes = [
        'multiple_choice',
        'multiple_response',
        'true_false',
        'matching',
        'fill_blank',
        'short_answer',
        'long_answer',
        'essay',
        'case_study',
        'problem_solving',
        'scenario',
        'hots',
    ];

    public function __construct(
        protected MaterialDocumentReader $documentReader,
        protected QuizDocumentEngine $documentEngine,
        protected QuizMaterialAnalysisService $analysisService,
        protected QuizQuestionValidator $questionValidator,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->hasClaude() || $this->hasGemini();
    }

    public function hasClaude(): bool
    {
        return (bool) config('services.anthropic.api_key');
    }

    public function hasGemini(): bool
    {
        return $this->geminiApiKeys() !== [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{questions: array<int, array>, provider: string, knowledge_map?: array, rejected?: array, insufficient?: bool}
     */
    public function generateQuestions(
        Course $course,
        string $topic,
        int $count = 5,
        string $difficulty = 'medium',
        ?int $materialId = null,
        array $options = []
    ): array {
        $count = $this->resolveQuestionCount($count, $options);
        $materials = $this->resolveContextMaterials($course, $topic, $materialId);
        $rag = $this->documentEngine->buildRagContext($materials, $topic, $count, true);

        if ($rag['context'] === '' || ($rag['word_count'] ?? 0) < 40) {
            throw new \RuntimeException(
                'Insufficient information in uploaded material. Could not extract enough readable text from the document(s).'
            );
        }

        $knowledgeMap = null;
        $primaryMaterial = $materials->first();
        if ($primaryMaterial instanceof CourseMaterial) {
            $knowledgeMap = $this->analysisService->getCachedKnowledgeMap($primaryMaterial);
        }

        $context = $this->formatGenerationContext($course, $topic, $materials, $rag['context'], $knowledgeMap);
        $prompt = $this->generationPrompt($course, $topic, $count, $difficulty, $context, $options);
        $maxTokens = min(8192, max(1200, ($count * 320) + 300));

        $raw = null;
        $provider = 'gemini';
        $generationModel = $this->resolveGenerationModel();

        foreach ($this->resolveAiProviderOrder('generation') as $name) {
            if ($name === 'claude' && $this->hasClaude()) {
                $raw = $this->callClaude($prompt, $maxTokens, $generationModel);
                $provider = 'claude';
            } elseif ($name === 'gemini' && $this->hasGemini()) {
                $raw = $this->callGemini($prompt, $maxTokens, true);
                $provider = 'gemini';
            }

            if ($raw !== null) {
                break;
            }
        }

        if ($raw === null) {
            $detail = $this->lastAiError ?: 'Both Claude and Gemini returned no response.';
            throw new \RuntimeException('AI quiz generation failed: ' . $detail);
        }

        $parsed = $this->parseQuestionsJson($raw);

        if (!empty($parsed['insufficient'])) {
            throw new \RuntimeException('Insufficient information in uploaded material.');
        }

        $questions = $this->normalizeQuestions($parsed['questions'] ?? $parsed, $options);
        $validated = $this->questionValidator->validate($questions);
        $questions = $validated['questions'];

        if (count($questions) < max(1, (int) ceil($count * 0.5))) {
            throw new \RuntimeException('Insufficient information in uploaded material.');
        }

        $questions = array_slice($questions, 0, $count);

        return [
            'questions' => $questions,
            'provider' => $provider,
            'knowledge_map' => $knowledgeMap,
            'rejected' => $validated['rejected'],
            'content_hash' => $rag['content_hash'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function markAttempt(array $questions, array $answers, int $passingScore = 70): array
    {
        $results = [];
        $score = 0;
        $maxScore = 0;
        $openItems = [];

        foreach ($questions as $question) {
            $qid = (string) ($question['id'] ?? '');
            $points = (int) ($question['points'] ?? 1);
            $maxScore += $points;
            $type = (string) ($question['type'] ?? 'multiple_choice');
            $studentAnswer = $answers[$qid] ?? '';

            if ($type === 'true_false') {
                $results[] = $this->markExact($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'multiple_choice') {
                $results[] = $this->markExact($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'multiple_response') {
                $results[] = $this->markMultipleResponse($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'matching') {
                $decoded = is_array($studentAnswer) ? $studentAnswer : json_decode((string) $studentAnswer, true);
                $results[] = $this->markMatching($question, is_array($decoded) ? $decoded : [], $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if ($type === 'fill_blank') {
                $results[] = $this->markFillBlank($question, $studentAnswer, $points, $qid);
                $score += $results[array_key_last($results)]['score'];
                continue;
            }

            if (trim((string) $studentAnswer) === '') {
                $results[] = [
                    'question_id' => $qid,
                    'type' => $type,
                    'correct' => false,
                    'score' => 0,
                    'max_score' => $points,
                    'student_answer' => '',
                    'feedback' => 'No answer provided.',
                    'marked_by' => 'auto',
                ];
                continue;
            }

            $openItems[] = [
                'question_id' => $qid,
                'type' => $type,
                'question' => $question['question'] ?? '',
                'model_answer' => $question['model_answer'] ?? ($question['correct_answer'] ?? ''),
                'marking_rubric' => $question['marking_rubric'] ?? null,
                'student_answer' => $studentAnswer,
                'points' => $points,
            ];
        }

        $markingProvider = 'auto';
        $overallFeedback = '';
        $analytics = null;

        if ($openItems !== []) {
            $aiMark = $this->markOpenAnswersWithAi($openItems);
            $markingProvider = $aiMark['provider'];
            $overallFeedback = $aiMark['overall_feedback'] ?? '';

            foreach ($aiMark['results'] as $row) {
                $score += (int) ($row['score'] ?? 0);
                $results[] = $row;
            }
        }

        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0;

        if ($this->hasClaude()) {
            $analytics = $this->buildPersonalizedFeedback($results, $percentage, $passingScore);
            if ($analytics && empty($overallFeedback)) {
                $overallFeedback = (string) ($analytics['summary'] ?? '');
            }
        }

        return [
            'question_results' => $results,
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'passed' => $percentage >= $passingScore,
            'feedback' => $overallFeedback ?: ($percentage >= $passingScore
                ? 'Well done! You passed this quiz.'
                : 'Keep studying this topic and try again.'),
            'marking_provider' => $markingProvider,
            'analytics' => $analytics,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    public function stripAnswersForLearner(array $questions): array
    {
        return array_map(function (array $q) {
            $type = (string) ($q['type'] ?? 'multiple_choice');

            unset(
                $q['correct_answer'],
                $q['correct_answers'],
                $q['model_answer'],
                $q['explanation'],
                $q['marking_rubric'],
                $q['confidence_score'],
                $q['source_section'],
                $q['source_paragraph']
            );

            if ($type === 'true_false') {
                $q['options'] = ['True', 'False'];
            }

            if ($type === 'matching' && isset($q['pairs']) && is_array($q['pairs'])) {
                $q['pairs'] = array_values(array_map(
                    fn ($p) => ['left' => (string) ($p['left'] ?? '')],
                    $q['pairs']
                ));
                $rights = array_values(array_map(fn ($p) => (string) ($p['right'] ?? ''), $q['pairs'] ?? []));
                shuffle($rights);
                $q['match_options'] = $rights;
            }

            if ($type === 'fill_blank') {
                unset($q['acceptable_answers']);
            }

            return $q;
        }, $questions);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function resolveQuestionCount(int $count, array $options): int
    {
        $mode = (string) ($options['quiz_mode'] ?? 'custom');
        $presets = [
            'quick' => 5,
            'standard' => 10,
            'comprehensive' => 20,
            'final_exam' => 50,
        ];

        if (isset($presets[$mode])) {
            return $presets[$mode];
        }

        return max(1, min(100, $count));
    }

    protected function resolveGenerationModel(): ?string
    {
        $fast = config('services.quiz_ai.fast_generation_model');
        if (is_string($fast) && trim($fast) !== '') {
            return trim($fast);
        }

        $claude = config('services.quiz_ai.claude_generation_model')
            ?: config('services.anthropic.model', 'claude-sonnet-4-6');

        return is_string($claude) && trim($claude) !== '' ? trim($claude) : null;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAiProviderOrder(string $context = 'generation'): array
    {
        if ($context === 'marking') {
            $primary = strtolower((string) config('services.quiz_ai.marking_primary', 'gemini'));
            $secondary = strtolower((string) config('services.quiz_ai.marking_secondary', 'claude'));
        } else {
            $primary = strtolower((string) config('services.quiz_ai.generation_provider', 'gemini'));
            $secondary = $primary === 'gemini' ? 'claude' : 'gemini';
        }

        if ($context === 'generation'
            && filter_var(config('services.quiz_ai.prefer_gemini_for_speed', true), FILTER_VALIDATE_BOOL)) {
            return ['gemini', 'claude'];
        }

        $order = [$primary, $secondary];

        return array_values(array_unique(array_filter($order, fn ($name) => in_array($name, ['claude', 'gemini'], true))));
    }

    /**
     * @param  Collection<int, CourseMaterial>  $materials
     * @param  array<string, mixed>|null  $knowledgeMap
     */
    protected function formatGenerationContext(
        Course $course,
        string $topic,
        Collection $materials,
        string $ragContext,
        ?array $knowledgeMap
    ): string {
        $sourceLines = $materials->map(function (CourseMaterial $material) {
            $meta = QuizMaterialHelper::meta($material);
            $parts = array_filter([
                $material->title,
                !empty($meta['module']) ? 'Module: ' . $meta['module'] : null,
                !empty($meta['chapter']) ? 'Chapter: ' . $meta['chapter'] : null,
            ]);

            return '- ' . implode(' · ', $parts);
        })->implode("\n");

        $mapSection = '';
        if (is_array($knowledgeMap) && $knowledgeMap !== []) {
            $compactMap = array_filter([
                'main_topics' => array_slice($knowledgeMap['main_topics'] ?? [], 0, 8),
                'key_concepts' => array_slice($knowledgeMap['key_concepts'] ?? [], 0, 12),
                'difficulty_level' => $knowledgeMap['difficulty_level'] ?? null,
            ]);
            if ($compactMap !== []) {
                $mapSection = "\n\nKnowledge map (cached analysis):\n" . json_encode($compactMap, JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", array_filter([
            'Course: ' . ($course->title ?? 'Untitled'),
            'Topic focus: ' . $topic,
            'Source material(s):',
            $sourceLines,
            $mapSection,
            '',
            '=== RETRIEVED STUDY MATERIAL (RAG — use ONLY this content) ===',
            $ragContext,
        ]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function generationPrompt(
        Course $course,
        string $topic,
        int $count,
        string $difficulty,
        string $context,
        array $options = []
    ): string {
        $types = $options['question_types'] ?? ['multiple_choice', 'true_false'];
        if (!is_array($types) || $types === []) {
            $types = ['multiple_choice', 'true_false'];
        }
        $types = array_values(array_intersect($types, $this->supportedTypes));
        if ($types === []) {
            $types = ['multiple_choice', 'true_false'];
        }

        $bloom = $options['bloom_levels'] ?? ['remember', 'understand', 'apply', 'analyze'];
        if (!is_array($bloom) || $bloom === []) {
            $bloom = ['remember', 'understand', 'apply', 'analyze'];
        }
        $bloomList = implode(', ', $bloom);
        $typeList = implode(', ', $types);
        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard', 'mixed'], true) ? $difficulty : 'medium';

        return <<<PROMPT
You are an expert assessment designer for Xander Learning Hub.

Generate exactly {$count} quiz questions with difficulty "{$difficulty}" focused on topic "{$topic}".

STRICT CONTENT RULE:
- Use ONLY the retrieved study material below.
- DO NOT invent facts or use external knowledge.
- If the material lacks enough distinct facts for {$count} quality questions, return:
  {"insufficient": true, "questions": []}

Allowed question types: {$typeList}
Target Bloom levels: {$bloomList}

{$context}

Return JSON only:
{
  "questions": [
    {
      "id": "q1",
      "question": "...",
      "type": "multiple_choice",
      "difficulty": "medium",
      "bloom_level": "understand",
      "source_section": "section from material",
      "source_paragraph": "brief excerpt reference",
      "options": ["A","B","C","D"],
      "correct_answer": "B",
      "explanation": "why this is correct based on the material",
      "confidence_score": 0.92,
      "estimated_time": 60,
      "points": 1
    }
  ]
}

Rules:
- ids q1..q{$count}
- Every question must cite source_section from the material
- confidence_score 0.0-1.0 (reject below 0.5)
- For true_false: correct_answer is "True" or "False"
- For multiple_choice: exactly 4 options
- For multiple_response: use correct_answers array with 2+ values, options array 4-5 items
- For fill_blank: use blanks in question with ___, correct_answer or acceptable_answers array
- For essay/long_answer/case_study: include model_answer and marking_rubric
- Mix types as requested; prefer material-specific factual questions
PROMPT;
    }

    /**
     * @return Collection<int, CourseMaterial>
     */
    protected function resolveContextMaterials(Course $course, string $topic, ?int $materialId = null): Collection
    {
        $studyMaterials = CourseMaterial::query()
            ->where('course_id', $course->id)
            ->whereNotIn('type', ['quiz', 'assessment', 'zoom'])
            ->orderBy('sort_order')
            ->get();

        if ($materialId) {
            $selected = $studyMaterials->firstWhere('id', $materialId);
            if (!$selected) {
                throw new \RuntimeException('Selected material does not belong to this course.');
            }

            return collect([$selected]);
        }

        $topicMaterials = QuizMaterialHelper::materialsForTopic($studyMaterials, $topic);
        if ($topicMaterials !== []) {
            return collect($topicMaterials);
        }

        $pdfs = $studyMaterials->filter(fn (CourseMaterial $m) => QuizMaterialHelper::isPdfMaterial($m));
        if ($pdfs->count() === 1) {
            return collect([$pdfs->first()]);
        }

        return $studyMaterials->take(3);
    }

    protected function markExact(array $question, mixed $studentAnswer, int $points, string $qid): array
    {
        $correct = trim((string) ($question['correct_answer'] ?? ''));
        $student = trim((string) $studentAnswer);
        $isCorrect = $student !== '' && strcasecmp($student, $correct) === 0;

        return [
            'question_id' => $qid,
            'type' => $question['type'] ?? 'multiple_choice',
            'correct' => $isCorrect,
            'score' => $isCorrect ? $points : 0,
            'max_score' => $points,
            'student_answer' => $studentAnswer,
            'correct_answer' => $correct,
            'explanation' => $question['explanation'] ?? null,
            'marked_by' => 'auto',
        ];
    }

    protected function markMultipleResponse(array $question, mixed $studentAnswer, int $points, string $qid): array
    {
        $correct = array_map('strval', $question['correct_answers'] ?? [$question['correct_answer'] ?? '']);
        $student = is_array($studentAnswer)
            ? array_map('strval', $studentAnswer)
            : array_filter(array_map('trim', explode(',', (string) $studentAnswer)));

        sort($correct);
        sort($student);
        $isCorrect = $correct === $student;

        return [
            'question_id' => $qid,
            'type' => 'multiple_response',
            'correct' => $isCorrect,
            'score' => $isCorrect ? $points : 0,
            'max_score' => $points,
            'student_answer' => $studentAnswer,
            'correct_answer' => implode(', ', $correct),
            'marked_by' => 'auto',
        ];
    }

    protected function markMatching(array $question, mixed $studentAnswer, int $points, string $qid): array
    {
        $pairs = $question['pairs'] ?? [];
        $studentPairs = is_array($studentAnswer) ? $studentAnswer : json_decode((string) $studentAnswer, true);
        if (!is_array($studentPairs)) {
            $studentPairs = [];
        }

        $total = max(1, count($pairs));
        $correctCount = 0;
        foreach ($pairs as $pair) {
            $left = (string) ($pair['left'] ?? '');
            $right = (string) ($pair['right'] ?? '');
            if ($left !== '' && (($studentPairs[$left] ?? null) === $right)) {
                $correctCount++;
            }
        }

        $ratio = $correctCount / $total;
        $earned = (int) round($points * $ratio);

        return [
            'question_id' => $qid,
            'type' => 'matching',
            'correct' => $ratio >= 1,
            'score' => $earned,
            'max_score' => $points,
            'student_answer' => $studentAnswer,
            'marked_by' => 'auto',
        ];
    }

    protected function markFillBlank(array $question, mixed $studentAnswer, int $points, int|string $qid): array
    {
        $acceptable = $question['acceptable_answers'] ?? [$question['correct_answer'] ?? ''];
        if (!is_array($acceptable)) {
            $acceptable = [$acceptable];
        }

        $student = strtolower(trim((string) $studentAnswer));
        $isCorrect = false;
        foreach ($acceptable as $answer) {
            $answer = strtolower(trim((string) $answer));
            if ($answer === '' || $student === '') {
                continue;
            }
            if ($student === $answer || similar_text($student, $answer) / max(strlen($answer), 1) > 0.82) {
                $isCorrect = true;
                break;
            }
        }

        return [
            'question_id' => (string) $qid,
            'type' => 'fill_blank',
            'correct' => $isCorrect,
            'score' => $isCorrect ? $points : 0,
            'max_score' => $points,
            'student_answer' => $studentAnswer,
            'marked_by' => 'auto',
        ];
    }

    protected function markOpenAnswersWithAi(array $openItems): array
    {
        $payload = json_encode(['items' => $openItems], JSON_UNESCAPED_UNICODE);
        $prompt = <<<PROMPT
Grade these open-ended quiz answers using ONLY the model answers/rubrics provided.

Essay rubric weights:
- Content accuracy 30%
- Understanding 25%
- Critical thinking 20%
- Structure 15%
- Language 10%

Return JSON only:
{
  "overall_feedback": "...",
  "results": [
    {
      "question_id": "q2",
      "type": "short_answer",
      "correct": true,
      "score": 2,
      "max_score": 2,
      "student_answer": "...",
      "feedback": "...",
      "improvement_suggestions": "..."
    }
  ]
}

Items: {$payload}
PROMPT;

        $raw = null;
        $provider = 'gemini';

        foreach ($this->resolveAiProviderOrder('marking') as $name) {
            if ($name === 'claude' && $this->hasClaude()) {
                $raw = $this->callClaude($prompt, 2048);
                $provider = 'claude';
            } elseif ($name === 'gemini' && $this->hasGemini()) {
                $raw = $this->callGemini($prompt, 2048, true);
                $provider = 'gemini';
            }

            if ($raw !== null) {
                break;
            }
        }

        if ($raw === null) {
            return $this->fallbackMarkOpen($openItems);
        }

        try {
            $parsed = $this->parseQuestionsJson($raw);
            $results = $parsed['results'] ?? [];
            foreach ($results as &$row) {
                $row['marked_by'] = $provider;
            }

            return [
                'provider' => $provider,
                'overall_feedback' => (string) ($parsed['overall_feedback'] ?? ''),
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            return $this->fallbackMarkOpen($openItems);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>|null
     */
    protected function buildPersonalizedFeedback(array $results, float $percentage, int $passingScore): ?array
    {
        $summary = json_encode([
            'percentage' => $percentage,
            'passing_score' => $passingScore,
            'results' => array_map(fn ($r) => [
                'question_id' => $r['question_id'] ?? '',
                'correct' => $r['correct'] ?? false,
                'type' => $r['type'] ?? '',
            ], $results),
        ], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Based on these quiz results, return JSON only:
{
  "summary": "2-3 sentence personalized feedback",
  "strengths": ["..."],
  "weaknesses": ["..."],
  "learning_gaps": ["..."],
  "recommendations": ["..."]
}
Data: {$summary}
PROMPT;

        $raw = null;
        foreach ($this->resolveAiProviderOrder('marking') as $name) {
            if ($name === 'claude' && $this->hasClaude()) {
                $raw = $this->callClaude($prompt, 800);
            } elseif ($name === 'gemini' && $this->hasGemini()) {
                $raw = $this->callGemini($prompt, 800, true);
            }
            if ($raw !== null) {
                break;
            }
        }

        if ($raw === null) {
            return null;
        }

        try {
            return $this->parseQuestionsJson($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function fallbackMarkOpen(array $openItems): array
    {
        $results = [];
        foreach ($openItems as $item) {
            $student = trim((string) ($item['student_answer'] ?? ''));
            $model = trim((string) ($item['model_answer'] ?? ''));
            $points = (int) ($item['points'] ?? 1);
            $similar = $student !== '' && $model !== ''
                && similar_text(strtolower($student), strtolower($model)) / max(strlen($model), 1) > 0.55;
            $earned = $similar ? $points : (strlen($student) > 20 ? (int) ceil($points / 2) : 0);

            $results[] = [
                'question_id' => $item['question_id'],
                'type' => $item['type'],
                'correct' => $earned >= $points,
                'score' => $earned,
                'max_score' => $points,
                'student_answer' => $student,
                'feedback' => 'Graded with similarity matching (AI unavailable).',
                'marked_by' => 'fallback',
            ];
        }

        return [
            'provider' => 'fallback',
            'overall_feedback' => 'Your answers were reviewed. AI marking was unavailable for some items.',
            'results' => $results,
        ];
    }

    protected function callClaude(string $prompt, int $maxTokens = 2048, ?string $model = null): ?string
    {
        $key = config('services.anthropic.api_key');
        if (!$key) {
            return null;
        }

        $model = $model ?: config('services.anthropic.model', 'claude-sonnet-4-6');

        try {
            $response = Http::timeout(90)
                ->connectTimeout(15)
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.25,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                $this->lastAiError = $this->summarizeHttpError('Claude', $response->status(), $response->body());
                Log::warning('Claude API error', ['body' => $response->body()]);

                return null;
            }

            foreach ($response->json('content') ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    return (string) ($block['text'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            $this->lastAiError = 'Claude request failed: ' . $e->getMessage();
            Log::error('Claude request failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    protected function callGemini(string $prompt, int $maxTokens = 2048, bool $jsonMode = false): ?string
    {
        $keys = $this->geminiApiKeys();
        if ($keys === []) {
            return null;
        }

        $model = config('services.quiz_ai.generation_model')
            ?: config('services.gemini.model', 'gemini-2.0-flash');

        $generationConfig = [
            'temperature' => 0.25,
            'maxOutputTokens' => $maxTokens,
        ];
        if ($jsonMode) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => $generationConfig,
        ];

        foreach ($keys as $keyLabel => $key) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

            try {
                $response = Http::timeout(90)->connectTimeout(15)->post($url, $payload);

                if (!$response->successful()) {
                    $this->lastAiError = $this->summarizeHttpError("Gemini ({$keyLabel})", $response->status(), $response->body());
                    continue;
                }

                $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
                if ($text !== '') {
                    return $text;
                }
            } catch (\Throwable $e) {
                $this->lastAiError = "Gemini ({$keyLabel}) request failed: " . $e->getMessage();
            }
        }

        return null;
    }

    /** @return array<string, string> */
    protected function geminiApiKeys(): array
    {
        $keys = [];
        foreach (['GOOGLE_AI_API_KEY', 'GEMINI_API_KEY'] as $name) {
            $value = env($name);
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($value === '' || isset($keys[$value])) {
                continue;
            }
            $keys[$name] = $value;
        }

        return $keys;
    }

    protected function summarizeHttpError(string $provider, int $status, string $body): string
    {
        $message = null;
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = data_get($decoded, 'error.message') ?: data_get($decoded, 'error.error.message');
        }

        return trim($provider . ' HTTP ' . $status . ($message ? ': ' . $message : ''));
    }

    /** @return array<string, mixed> */
    protected function parseQuestionsJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AI returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  mixed  $questions
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeQuestions($questions, array $options = []): array
    {
        if (!is_array($questions)) {
            throw new \RuntimeException('No questions returned from AI.');
        }

        $allowedTypes = $options['question_types'] ?? $this->supportedTypes;
        if (!is_array($allowedTypes) || $allowedTypes === []) {
            $allowedTypes = ['multiple_choice', 'true_false'];
        }

        $normalized = [];
        $i = 1;
        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }

            $type = in_array($q['type'] ?? '', $this->supportedTypes, true) ? $q['type'] : 'multiple_choice';
            if (!in_array($type, $allowedTypes, true)) {
                $type = in_array('multiple_choice', $allowedTypes, true) ? 'multiple_choice' : ($allowedTypes[0] ?? 'multiple_choice');
            }

            $optionsList = array_values($q['options'] ?? []);
            if ($type === 'true_false') {
                $optionsList = ['True', 'False'];
            }

            $normalized[] = array_filter([
                'id' => (string) ($q['id'] ?? ('q' . $i)),
                'type' => $type,
                'question' => (string) ($q['question'] ?? 'Question'),
                'options' => $optionsList ?: null,
                'correct_answer' => isset($q['correct_answer']) ? (string) $q['correct_answer'] : null,
                'correct_answers' => isset($q['correct_answers']) && is_array($q['correct_answers']) ? array_values($q['correct_answers']) : null,
                'acceptable_answers' => isset($q['acceptable_answers']) && is_array($q['acceptable_answers']) ? array_values($q['acceptable_answers']) : null,
                'pairs' => isset($q['pairs']) && is_array($q['pairs']) ? $q['pairs'] : null,
                'model_answer' => isset($q['model_answer']) ? (string) $q['model_answer'] : null,
                'marking_rubric' => isset($q['marking_rubric']) ? (string) $q['marking_rubric'] : null,
                'explanation' => isset($q['explanation']) ? (string) $q['explanation'] : null,
                'difficulty' => (string) ($q['difficulty'] ?? 'medium'),
                'bloom_level' => (string) ($q['bloom_level'] ?? 'understand'),
                'source_section' => (string) ($q['source_section'] ?? ''),
                'source_paragraph' => (string) ($q['source_paragraph'] ?? ''),
                'confidence_score' => (float) ($q['confidence_score'] ?? 0.85),
                'estimated_time' => (int) ($q['estimated_time'] ?? 60),
                'points' => max(1, (int) ($q['points'] ?? 1)),
            ], fn ($v) => $v !== null && $v !== '');
            $i++;
        }

        if ($normalized === []) {
            throw new \RuntimeException('AI did not return usable questions.');
        }

        return $normalized;
    }
}
