<?php

namespace App\Support;

use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Services\ZoomService;

class LearnerRecordingAccess
{
    public static function pathwaysMeetingId(): ?string
    {
        return app(ZoomService::class)->pathwaysMeetingId();
    }

    public static function isPathwaysWebinarMeeting(?string $meetingId): bool
    {
        $pathwaysId = self::pathwaysMeetingId();
        if (!$pathwaysId || !$meetingId) {
            return false;
        }

        return (string) $meetingId === (string) $pathwaysId;
    }

    public static function hasPaidAccessToCourse(int $studentId, int $courseId): bool
    {
        return CourseEnrollment::query()
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->whereIn('status', ['paid', 'completed'])
            ->exists();
    }

    /**
     * Zoom meeting IDs linked to paid/completed course live classes (excludes Pathways webinar room).
     *
     * @return array<int, string>
     */
    public static function liveClassMeetingIdsForStudent(int $studentId, ?int $courseId = null): array
    {
        $courseIds = CourseEnrollment::query()
            ->where('student_id', $studentId)
            ->whereIn('status', ['paid', 'completed'])
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->pluck('course_id');

        if ($courseIds->isEmpty()) {
            return [];
        }

        return CourseMaterial::query()
            ->whereIn('course_id', $courseIds)
            ->where('type', 'zoom')
            ->get()
            ->map(fn (CourseMaterial $material) => CourseMaterialHelper::meetingId($material))
            ->filter()
            ->reject(fn (?string $meetingId) => self::isPathwaysWebinarMeeting($meetingId))
            ->map(fn (?string $meetingId) => (string) $meetingId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $grouped
     * @param  array<int, string>  $allowedMeetingIds
     * @return array<string, list<array<string, mixed>>>
     */
    public static function filterGroupedRecordings(array $grouped, array $allowedMeetingIds): array
    {
        $allowed = array_fill_keys(array_map('strval', $allowedMeetingIds), true);
        $filtered = [];

        foreach ($grouped as $meetingId => $recordings) {
            if (isset($allowed[(string) $meetingId])) {
                $filtered[(string) $meetingId] = $recordings;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $grouped
     * @return list<array<string, mixed>>
     */
    public static function flattenGroupedRecordings(array $grouped): array
    {
        $items = [];

        foreach ($grouped as $recordings) {
            foreach ($recordings as $recording) {
                $items[] = $recording;
            }
        }

        return $items;
    }
}
