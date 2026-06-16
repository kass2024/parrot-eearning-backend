<?php

namespace App\Services\Quiz;

class QuizAntiCheatService
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array{questions: array<int, array<string, mixed>>, delivered_ids: array<int, string>}
     */
    public function prepareDelivery(array $meta, int $studentId): array
    {
        $settings = is_array($meta['anti_cheat'] ?? null) ? $meta['anti_cheat'] : [];
        $pool = $meta['question_pool'] ?? ($meta['questions'] ?? []);
        if (!is_array($pool)) {
            $pool = [];
        }

        $deliverCount = (int) ($settings['deliver_count'] ?? 0);
        if ($deliverCount > 0 && count($pool) > $deliverCount) {
            $pool = $this->deterministicSample($pool, $deliverCount, $studentId, (int) ($meta['source_material_id'] ?? 0));
        }

        if ($settings['shuffle_questions'] ?? true) {
            $pool = $this->deterministicShuffle($pool, $studentId, 'questions');
        }

        $prepared = [];
        foreach ($pool as $question) {
            if (!is_array($question)) {
                continue;
            }
            $prepared[] = $this->maybeShuffleOptions($question, $settings, $studentId);
        }

        $ids = array_values(array_map(fn ($q) => (string) ($q['id'] ?? ''), $prepared));

        return ['questions' => $prepared, 'delivered_ids' => $ids];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function maxAttemptsReached(array $meta, int $studentId, int $courseMaterialId, int $attemptCount): bool
    {
        $settings = is_array($meta['anti_cheat'] ?? null) ? $meta['anti_cheat'] : [];
        $max = (int) ($settings['max_attempts'] ?? 0);

        return $max > 0 && $attemptCount >= $max;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    protected function maybeShuffleOptions(array $question, array $settings, int $studentId): array
    {
        $type = (string) ($question['type'] ?? '');
        if (!($settings['shuffle_options'] ?? true)) {
            return $question;
        }

        if (!in_array($type, ['multiple_choice', 'multiple_response'], true)) {
            return $question;
        }

        $options = array_values($question['options'] ?? []);
        if (count($options) < 2) {
            return $question;
        }

        $question['options'] = $this->deterministicShuffle($options, $studentId, (string) ($question['id'] ?? 'opts'));

        return $question;
    }

    /**
     * @template T
     * @param  array<int, T>  $items
     * @return array<int, T>
     */
    protected function deterministicShuffle(array $items, int $studentId, string $salt): array
    {
        $items = array_values($items);
        usort($items, function ($a, $b) use ($studentId, $salt) {
            $ka = crc32($studentId . ':' . $salt . ':' . json_encode($a));
            $kb = crc32($studentId . ':' . $salt . ':' . json_encode($b));

            return $ka <=> $kb;
        });

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function deterministicSample(array $items, int $count, int $studentId, int $quizId): array
    {
        $items = array_values($items);
        usort($items, function ($a, $b) use ($studentId, $quizId) {
            $ka = crc32($studentId . ':' . $quizId . ':' . ($a['id'] ?? ''));
            $kb = crc32($studentId . ':' . $quizId . ':' . ($b['id'] ?? ''));

            return $ka <=> $kb;
        });

        return array_slice($items, 0, $count);
    }
}
