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

    <p>This link is valid for {{ $link_expiry ?? '15' }} minutes after you receive this email.</p>

    @if (!empty($otp))
        <p>Or enter this code on the device you signed up on:</p>

        <p style="
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 6px;
            background-color: #f4f4f4;
            display: inline-block;
            padding: 10px 20px;
            border-radius: 5px;">{{ $otp }}
        </p>

        <p>This code will expire {{ $otp_expiry ?? '15' }} minutes after you receive this email.</p>
    @endif

    <p>If you didn’t request this, you can safely ignore this email.</p>

    <p>Regards,<br>{{ config('app.name') }}</p>
</body>
</html>
