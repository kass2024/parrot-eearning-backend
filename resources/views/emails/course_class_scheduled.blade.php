<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upcoming class scheduled</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#f3f4f6; color:#111827; }
        .wrapper { width:100%; padding:24px 0; }
        .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; box-shadow:0 10px 30px rgba(15,23,42,0.08); overflow:hidden; }
        .header { background:#111827; color:#f9fafb; padding:20px 28px; }
        .header-title { font-size:18px; font-weight:700; letter-spacing:0.05em; text-transform:uppercase; }
        .body { padding:24px 28px 28px; }
        .paragraph { font-size:14px; line-height:1.6; margin:0 0 12px; color:#374151; }
        .highlight { font-weight:600; }
        .link { color:#2563eb; text-decoration:none; }
        .footer { padding:14px 28px 18px; border-top:1px solid #e5e7eb; background:#f9fafb; font-size:11px; color:#9ca3af; text-align:center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="container">
        <div class="header">
            <div class="header-title">{{ config('app.name') }}</div>
            <p style="margin-top:8px;font-size:14px;">New class scheduled</p>
        </div>
        <div class="body">
            <p class="paragraph">Dear {{ $student->first_name ?? $student->name ?? 'Student' }},</p>
            <p class="paragraph">
                A new class has been scheduled for your course
                <span class="highlight">{{ $course->title ?? 'Your course' }}</span>.
            </p>
            <p class="paragraph">
                <span class="highlight">Start time:</span>
                {{ \Carbon\Carbon::parse($startTime)->toDayDateTimeString() }}
            </p>
            <p class="paragraph">
                <span class="highlight">Zoom link:</span>
                <a href="{{ $zoomLink }}" class="link" target="_blank">{{ $zoomLink }}</a>
            </p>
            @if(!empty($notes))
                <p class="paragraph">
                    <span class="highlight">Notes:</span> {{ $notes }}
                </p>
            @endif
            <p class="paragraph">
                Please join a few minutes early to test your connection.
            </p>
            <p class="paragraph" style="margin-top:20px;">
                Best regards,<br>
                <span class="highlight">{{ config('app.name') }}</span>
            </p>
        </div>
        <div class="footer">
            <p>You are receiving this email because you are enrolled in this course.</p>
        </div>
    </div>
</div>
</body>
</html>
