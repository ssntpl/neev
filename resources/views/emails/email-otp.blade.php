<!DOCTYPE html>
<html>
<head>
    <title>Email OTP</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; padding: 8px;">
    <h2>Hello {{ $username ?? 'there' }},</h2>
    
    <p>Your verification code is:</p>
    
    <p style="font-size: 28px; font-weight: bold; letter-spacing: 4px; text-align: center; margin: 20px 0; color: #2ecc71;">
        {{ $otp }}
    </p>

    <p>This code will expire {{ $expiry ?? '15' }} minutes after you receive this email. If you didn’t request this, you can safely ignore this email.</p>

    <p style="margin-top: 30px;">
        If you have any questions, contact our support team.
    </p>

    <p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>
