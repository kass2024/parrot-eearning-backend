<?php

namespace App\Services\Quiz;

use App\Models\CourseMaterial;
use App\Models\QuizMaterialAnalysis;
use App\Services\MaterialDocumentReader;
use App\Support\QuizMaterialHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QuizMaterialAnalysisService
{
    public function __construct(
        protected MaterialDocumentReader $documentReader,
        protected QuizDocumentEngine $documentEngine,
        protected QuizEmbeddingService $embeddings,
        protected LocalMaterialKnowledgeMap $localKnowledgeMap,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(CourseMaterial $material, bool $force = false): array
    {
        $text = $this->documentReader->readMaterialText($material);
        if ($text === null || trim($text) === '') {
            throw new \RuntimeException('Could not extract text from this material. Upload PDF, DOCX, PPTX, or TXT with readable content.');
        }

        $hash = hash('sha256', $text);
        $existing = QuizMaterialAnalysis::query()
            ->where('course_material_id', $material->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing && !$force) {
            return $this->formatAnalysisResponse($existing, $material);
        }

        $label = QuizMaterialHelper::extractTopicLabel($material) ?? ($material->title ?? 'Material');
        $chunks = $this->documentEngine->chunkText($text, $label, (int) $material->id);
        $knowledgeMap = $this->buildKnowledgeMap($text, $label);
        $provider = $knowledgeMap['provider'] ?? 'local';

        $chunkEmbeddings = [];
        $embeddingModel = null;
        if ($this->shouldIndexEmbeddings() && $this->embeddings->isAvailable()) {
            $chunkEmbeddings = $this->embeddings->embedChunks($chunks);
            $embeddingModel = config('services.quiz_ai.embedding_model', 'text-embedding-004');
        }

        $record = QuizMaterialAnalysis::updateOrCreate(
            [
                'course_material_id' => $material->id,
                'content_hash' => $hash,
            ],
            [
                'knowledge_map' => $knowledgeMap['map'] ?? [],
                'chunks' => $chunks,
                'chunk_embeddings' => $chunkEmbeddings ?: null,
                'embedding_model' => $embeddingModel,
                'word_count' => str_word_count($text),
                'analysis_provider' => $provider,
                'analyzed_at' => now(),
            ]
        );

        return $this->formatAnalysisResponse($record, $material);
    }

    /**
     * Cached knowledge map only — never triggers Claude/embeddings (fast path for quiz generation).
     *
     * @return array<string, mixed>|null
     */
    public function getCachedKnowledgeMap(CourseMaterial $material): ?array
    {
        $text = $this->documentReader->readMaterialText($material);
        if ($text === null || trim($text) === '') {
            return null;
        }

        $existing = QuizMaterialAnalysis::query()
            ->where('course_material_id', $material->id)
            ->where('content_hash', hash('sha256', $text))
            ->first();

        if (!$existing || !is_array($existing->knowledge_map) || $existing->knowledge_map === []) {
            return null;
        }

        return $existing->knowledge_map;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAnalysisResponse(QuizMaterialAnalysis $record, CourseMaterial $material): array
    {
        $map = is_array($record->knowledge_map) ? $record->knowledge_map : [];

        return [
            'material_id' => $material->id,
            'material_title' => $material->title,
            'word_count' => $record->word_count,
            'chunk_count' => count($record->chunks ?? []),
            'analysis_provider' => $record->analysis_provider,
            'analyzed_at' => $record->analyzed_at?->toIso8601String(),
            'knowledge_map' => $map,
            'topics' => $map['main_topics'] ?? [],
            'learning_outcomes' => $map['learning_outcomes'] ?? [],
            'difficulty_level' => $map['difficulty_level'] ?? 'intermediate',
            'embeddings_indexed' => is_array($record->chunk_embeddings) && count($record->chunk_embeddings) > 0,
            'embedding_model' => $record->embedding_model,
        ];
    }

    /**
     * @return array{map: array<string, mixed>, provider: string}
     */
    protected function buildKnowledgeMap(string $text, string $label): array
    {
        if (!filter_var(config('services.quiz_ai.use_ai_knowledge_map', false), FILTER_VALIDATE_BOOL)) {
            return $this->localKnowledgeMap->build($text, $label);
        }

        return $this->buildKnowledgeMapWithAi($text, $label);
    }

    protected function shouldIndexEmbeddings(): bool
    {
        return filter_var(config('services.quiz_ai.enable_embeddings', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Optional AI knowledge map — costs API credits; disabled by default.
     *
     * @return array{map: array<string, mixed>, provider: string}
     */
    protected function buildKnowledgeMapWithAi(string $text, string $label): array
    {
        $excerpt = substr($text, 0, 6000);
        $prompt = <<<PROMPT
Analyze this uploaded study material and return JSON only.

Material title: {$label}

Content:
{$excerpt}

Return:
{
  "main_topics": ["..."],
  "subtopics": ["..."],
  "key_concepts": ["..."],
  "definitions": [{"term":"...","definition":"..."}],
  "learning_outcomes": ["..."],
  "difficulty_level": "beginner|intermediate|advanced",
  "bloom_levels_present": ["remember","understand","apply","analyze","evaluate","create"]
}

Use ONLY information from the content. Do not add external knowledge.
PROMPT;

        $preferGemini = filter_var(config('services.quiz_ai.prefer_gemini_for_speed', true), FILTER_VALIDATE_BOOL);
        $raw = null;
        $provider = 'local';

        if ($preferGemini) {
            $raw = $this->callGemini($prompt, 1200);
            $provider = 'gemini';
            if ($raw === null) {
                $raw = $this->callClaude($prompt, 1200);
                $provider = 'claude';
            }
        } else {
            $raw = $this->callClaude($prompt, 1200);
            $provider = 'claude';
            if ($raw === null) {
                $raw = $this->callGemini($prompt, 1200);
                $provider = 'gemini';
            }
        }

        if ($raw === null) {
            return $this->localKnowledgeMap->build($text, $label);
        }

        $decoded = $this->parseJson($raw);

        return ['map' => $decoded, 'provider' => $provider];
    }

    /**
     * @deprecated Use buildKnowledgeMapWithAi() when QUIZ_AI_USE_AI_KNOWLEDGE_MAP=true
     * @return array{map: array<string, mixed>, provider: string}
     */
    protected function buildKnowledgeMapWithClaude(string $text, string $label): array
    {
        return $this->buildKnowledgeMapWithAi($text, $label);
    }

    protected function callClaude(string $prompt, int $maxTokens): ?string
    {
        $key = config('services.anthropic.api_key');
        if (!$key) {
            return null;
        }

        $model = config('services.quiz_ai.claude_generation_model')
            ?: config('services.anthropic.model', 'claude-sonnet-4-6');

        try {
            $response = Http::timeout(60)->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => 0.2,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (!$response->successful()) {
                return null;
            }

            foreach ($response->json('content') ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    return (string) ($block['text'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Material analysis Claude failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    protected function callGemini(string $prompt, int $maxTokens): ?string
    {
        $key = env('GOOGLE_AI_API_KEY') ?: env('GEMINI_API_KEY');
        if (!is_string($key) || trim($key, " \t\"'") === '') {
            return null;
        }

        $model = config('services.quiz_ai.generation_model') ?: config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . trim($key, " \t\"'");

        try {
            $response = Http::timeout(60)->post($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => $maxTokens,
                    'responseMimeType' => 'application/json',
                ],
            ]);

            if (!$response->successful()) {
                return null;
            }

            return (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
