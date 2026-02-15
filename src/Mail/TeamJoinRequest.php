<?php

namespace Ssntpl\Neev\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamJoinRequest extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public $team,
        public $username,
        public $owner,
        public $teamId,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Team Join Request');
    }

    public function content(): Content
    {
        return new Content(view: 'neev::emails.team-join-request');
    }
}
