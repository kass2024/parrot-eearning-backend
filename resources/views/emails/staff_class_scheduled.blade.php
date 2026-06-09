<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }} - Class Scheduled</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #111827; }
        .wrapper { width: 100%; padding: 24px 0; background-color: #f3f4f6; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); overflow: hidden; }
        .header { background: linear-gradient(135deg, #0f172a, #1d4ed8); color: #f9fafb; padding: 20px 28px; }
        .header-title { font-size: 18px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
        .header-subtitle { margin-top: 8px; font-size: 14px; color: #e5e7eb; }
        .body { padding: 24px 28px 28px; }
        .greeting { font-size: 16px; margin-bottom: 16px; }
        .paragraph { font-size: 14px; line-height: 1.6; margin: 0 0 12px; color: #374151; }
        .info-box { background-color: #eff6ff; border-radius: 10px; padding: 14px 16px; border: 1px solid #bfdbfe; margin: 18px 0; font-size: 13px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .info-label { color: #6b7280; }
        .info-value { font-weight: 600; color: #111827; }
        .footer { padding: 14px 28px 18px; border-top: 1px solid #e5e7eb; background-color: #f9fafb; font-size: 11px; color: #9ca3af; text-align: center; }
        a.btn { display: inline-block; padding: 8px 16px; background-color: #1d4ed8; color: #f9fafb; text-decoration: none; border-radius: 999px; font-size: 13px; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrapper">
  <div class="container">
    <div class="header">
      <div class="header-title">{{ config('app.name') }}</div>
      <div class="header-subtitle">New class scheduled</div>
    </div>
    <div class="body">
      <p class="greeting">Dear {{ $staff->name ?? 'Instructor' }},</p>
      <p class="paragraph">
        A new class has been scheduled for your course
        <strong>{{ $course->title ?? 'your course' }}</strong>.
      </p>
      <div class="info-box">
        <div class="info-row">
          <span class="info-label">Date &amp; time</span>
          <span class="info-value">{{ $startTime }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Join link</span>
          <span class="info-value"><a href="{{ $zoomLink }}" style="color:#1d4ed8;">Open Zoom meeting</a></span>
        </div>
      </div>
      @if(!empty($notes))
        <p class="paragraph"><strong>Notes from admin:</strong></p>
        <p class="paragraph">{{ $notes }}</p>
      @endif
      <p class="paragraph" style="margin-top: 20px;">
        You can share this link with your learners or manage materials from your dashboard.
      </p>
      <p class="paragraph" style="margin-top: 20px;">
        Best regards,<br>
        <strong>{{ config('app.name') }}</strong>
      </p>
    </div>
    <div class="footer">
      You are receiving this email because a class was scheduled for a course where you are the instructor or staff contact.
    </div>
  </div>
</div>
</body>
</html>
