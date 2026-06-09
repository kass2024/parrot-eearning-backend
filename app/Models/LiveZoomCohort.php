<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveZoomCohort extends Model
{
    use HasFactory;

    // Explicit table name because it is not the default plural form
    protected $table = 'livezoom_cohort';

    protected $fillable = [
        'day_of_week',
        'start_time',
        'end_time',
        'timezone',
        'is_active',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'day_of_week' => 'integer',
    ];
}
