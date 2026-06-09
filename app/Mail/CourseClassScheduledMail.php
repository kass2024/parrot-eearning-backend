<?php

namespace App\Mail;

use App\Models\Student;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CourseClassScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;
    public Course $course;
    public string $startTime;
    public string $zoomLink;
    public ?string $notes;

    public function __construct(Student $student, Course $course, string $startTime, string $zoomLink, ?string $notes = null)
    {
        $this->student = $student;
        $this->course = $course;
        $this->startTime = $startTime;
        $this->zoomLink = $zoomLink;
        $this->notes = $notes;
    }

    public function build(): self
    {
        return $this->subject('Upcoming class scheduled: ' . ($this->course->title ?? 'Your course'))
            ->view('emails.course_class_scheduled')
            ->with([
                'student' => $this->student,
                'course' => $this->course,
                'startTime' => $this->startTime,
                'zoomLink' => $this->zoomLink,
                'notes' => $this->notes,
            ]);
    }
}
