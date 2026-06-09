<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StaffClassScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $staff;
    public Course $course;
    public string $startTime;
    public string $zoomLink;
    public ?string $notes;

    public function __construct(User $staff, Course $course, string $startTime, string $zoomLink, ?string $notes = null)
    {
        $this->staff = $staff;
        $this->course = $course;
        $this->startTime = $startTime;
        $this->zoomLink = $zoomLink;
        $this->notes = $notes;
    }

    public function build(): self
    {
        return $this->subject('Class scheduled for your course: ' . ($this->course->title ?? 'Course'))
            ->view('emails.staff_class_scheduled')
            ->with([
                'staff' => $this->staff,
                'course' => $this->course,
                'startTime' => $this->startTime,
                'zoomLink' => $this->zoomLink,
                'notes' => $this->notes,
            ]);
    }
}
