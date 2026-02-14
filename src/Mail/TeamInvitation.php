<?php

namespace Ssntpl\Neev\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $team,
        public $username,
        public $url = null,
        public $expiry = null,
        public $userExist = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Team Invitation');
    }

    public function content(): Content
    {
        return new Content(view: 'neev::emails.team-invitation');
    }
}
