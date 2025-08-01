<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verify Your Email</title>
</head>
<body style="padding: 8px">
    <h2>Hello {{ $username }},</h2>

    <p>Please click the button below to {{ $purpose ?? 'verify your email address' }}:</p>

    <p>
        <a href="{{ $url }}" style="
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            display: inline-block;
            border-radius: 5px;">
            {{ $purpose ?? 'Verify Email' }}
        </a>
    </p>

    <p>This link is valid for {{ $expiry ?? '15' }} minutes after you receive this email. If you didn’t request this, you can safely ignore this email.</p>

    <p>Regards,<br>{{env('APP_NAME')}}</p>
</body>
</html>
