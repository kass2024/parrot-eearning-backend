<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    protected $fillable = [
        'student_id',
        'course_material_id',
        'answers',
        'question_results',
        'score',
        'max_score',
        'percentage',
        'passed',
        'feedback',
        'marking_provider',
        'tab_switch_count',
        'focus_lost_seconds',
        'integrity_flags',
        'delivered_question_ids',
        'marked_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'question_results' => 'array',
        'integrity_flags' => 'array',
        'delivered_question_ids' => 'array',
        'passed' => 'boolean',
        'marked_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'course_material_id');
    }
}
