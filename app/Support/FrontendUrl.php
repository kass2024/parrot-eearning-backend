<?php

namespace App\Support;

class FrontendUrl
{
    /**
     * Base URL for learner-facing React app (Stripe return URLs, emails, certificates).
     */
    public static function base(): string
    {
        $configured = rtrim((string) config('app.frontend_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl === '') {
            return 'http://localhost:8080';
        }

        // api.xanderglobalscholars.com → elearning.xanderglobalscholars.com
        if (preg_match('#^https?://api\.(.+)$#i', $appUrl, $matches)) {
            $scheme = str_starts_with(strtolower($appUrl), 'https://') ? 'https' : 'http';

            return $scheme . '://elearning.' . $matches[1];
        }

        return $appUrl;
    }
}
