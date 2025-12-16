<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login Link</title>
</head>
<body style="padding: 8px">
    <h2>Hello there,</h2>

    <p>Please click the button below to login:</p>

    <p>
        <a href="{{ $url }}" style="
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            display: inline-block;
            border-radius: 5px;">
            Login
        </a>
    </p>

    <p>This link is valid for {{ $expiry ?? '15' }} minutes after you receive this email. If you didn’t request this, you can safely ignore this email.</p>

    <p>Regards,<br>{{config('app.name')}}</p>
</body>
</html>
