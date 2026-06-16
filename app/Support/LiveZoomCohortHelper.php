<?php

namespace App\Support;

use App\Models\LiveZoomCohort;

class LiveZoomCohortHelper
{
    public static function publicJoinUrl(LiveZoomCohort|int $cohort): string
    {
        $id = $cohort instanceof LiveZoomCohort ? $cohort->id : $cohort;
        $frontend = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/');

        if ($frontend === '') {
            return '/live-cohort/' . $id . '/join';
        }

        return $frontend . '/live-cohort/' . $id . '/join';
    }
}
