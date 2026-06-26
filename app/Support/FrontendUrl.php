<?php

namespace App\Support;

class FrontendUrl
{
    /**
     * Base URL for learner-facing React app (Stripe return URLs, emails, certificates).
     */
    public static function base(): string
    {
        $explicit = rtrim((string) config('app.frontend_url', ''), '/');
        if ($explicit !== '') {
            return $explicit;
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');

        // Parrot production: API on api.parrotglobalstudyacademy.ca → learner app on parrotglobalstudyacademy.ca
        if ($appUrl !== '' && preg_match('#^https?://api\.parrotglobalstudyacademy\.ca#i', $appUrl)) {
            return 'https://parrotglobalstudyacademy.ca';
        }

        // Legacy Xander production (kept for old deployments)
        if ($appUrl !== '' && preg_match('#^https?://api\.xanderglobalscholars\.com#i', $appUrl)) {
            return 'https://xanderglobalacademy.com';
        }

        // Generic: api.example.com → elearning.example.com
        if ($appUrl !== '' && preg_match('#^https?://api\.(.+)$#i', $appUrl, $matches)) {
            $scheme = str_starts_with(strtolower($appUrl), 'https://') ? 'https' : 'http';

            return $scheme . '://elearning.' . $matches[1];
        }

        if ($appUrl !== '') {
            return $appUrl;
        }

        return 'http://localhost:8080';
    }
}
