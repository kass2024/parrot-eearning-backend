<?php

return [
    'contact_email' => env('PLATFORM_CONTACT_EMAIL', 'infos@parrotglobalstudyacademy.ca'),
    'seed_password' => trim((string) env('SEED_PLATFORM_PASSWORD', 'Parrot@2025'), " \t\n\r\0\x0B'\""),
    'certificate_prefix' => 'PGS',
];
