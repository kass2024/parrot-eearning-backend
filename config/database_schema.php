<?php

/**
 * Expected database schema for Xander Learning Hub.
 * Used by DatabaseSchemaService to detect incomplete deployments and trigger auto-migrate.
 * When adding a migration, append the table/columns here (or add to sync_parrot_hub_schema migration).
 */
return [
    'users' => ['id', 'name', 'email', 'password', 'role', 'status', 'phone'],
    'students' => ['id', 'email', 'first_name', 'last_name', 'status', 'password', 'country', 'phone', 'primary_goal'],
    'courses' => ['id', 'title', 'status', 'price', 'course_code'],
    'course_enrollments' => ['id', 'student_id', 'course_id', 'status', 'level', 'study_shift_id'],
    'course_payments' => ['id', 'course_id', 'student_id', 'amount_cents', 'status', 'provider'],
    'assign_cours' => ['user_id', 'course_id'],
    'meeting_registrations' => ['id', 'email', 'status'],
    'available_schedules' => ['id', 'available_on_date'],
    'livezoom_cohort' => ['id', 'available_on_date'],
    'livezoom_cohort_queue_entries' => ['id'],
    'instructor_payout_requests' => ['id', 'instructor_id', 'amount', 'status', 'payment_method'],
    'webinar_settings' => ['id'],
    'study_shifts' => ['id', 'name', 'day_of_week', 'start_time', 'end_time', 'is_active'],
    'course_enrollment_study_shifts' => ['id', 'course_enrollment_id', 'study_shift_id'],
    'study_shift_change_requests' => ['id', 'course_enrollment_id', 'student_id', 'course_id', 'status'],
    'course_materials' => ['id', 'course_id'],
    'quiz_attempts' => ['id'],
];
