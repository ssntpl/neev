<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Team Invitation</title>
</head>
<body style="padding: 8px">
    <h2>Hello {{ $username }},</h2>

    <p>
        You have been invited to join the {{ $team }} team!
    </p>

    <p>
    @if ($userExist)
        you may check this invitation by clicking the button below:    
    @else
        To accept the invitation, please create an account using the link below. This invitation link will expires at {{ $expiry }}.
    @endif
    </p>

    <p>
        <a href="{{ $url ?? env('APP_URL').'/account/teams' }}" style="
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            display: inline-block;
            border-radius: 5px;">
            {{$userExist ? ($url ? "Accept Invitation" : "Check Invitation") : "Create Account"}}
        </a>
    </p>

    <p>Regards,<br>{{env('APP_NAME')}}</p>
</body>
</html>
