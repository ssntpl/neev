<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Team Invitation</title>
</head>
<body style="padding: 8px">
    <h2>Hello {{ $owner }},</h2>

    <p>
        {{$username}} want to join the {{ $team }} team!
    </p>

    <p>
        you may check this request by clicking the button below:
    </p>

    <p>
        <a href="{{ env('APP_URL').'/teams'.'/'.$teamId.'/members' }}" style="
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            display: inline-block;
            border-radius: 5px;">
            Check Request
        </a>
    </p>

    <p>Regards,<br>{{env('APP_NAME')}}</p>
</body>
</html>
