<?php

namespace App\Services\Quiz;

class QuizQuestionValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array{questions: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>}
     */
    public function validate(array $questions): array
    {
        $valid = [];
        $rejected = [];
        $seen = [];

        foreach ($questions as $q) {
            $reason = $this->rejectReason($q, $seen);
            if ($reason !== null) {
                $rejected[] = ['question' => $q, 'reason' => $reason];
                continue;
            }

            $questionText = strtolower(trim((string) ($q['question'] ?? '')));
            $seen[] = $questionText;
            $valid[] = $q;
        }

        return ['questions' => $valid, 'rejected' => $rejected];
    }

    protected function rejectReason(array $q, array $seen): ?string
    {
        $text = trim((string) ($q['question'] ?? ''));
        if ($text === '' || strlen($text) < 10) {
            return 'Question too short or empty';
        }

        if (in_array(strtolower($text), $seen, true)) {
            return 'Duplicate question';
        }

        $type = (string) ($q['type'] ?? '');
        $answer = trim((string) ($q['correct_answer'] ?? ''));

        if ($type === 'true_false' && !in_array($answer, ['True', 'False'], true)) {
            return 'True/False answer must be True or False';
        }

        if ($type === 'multiple_choice') {
            $options = array_values(array_filter($q['options'] ?? [], fn ($o) => trim((string) $o) !== ''));
            if (count($options) < 4) {
                return 'MCQ needs at least 4 options';
            }
            if ($answer === '' || !in_array($answer, $options, true)) {
                return 'MCQ correct answer must match an option';
            }
        }

        if (($q['confidence_score'] ?? 1) < 0.35) {
            return 'Low confidence score';
        }

        return null;
    }
}
