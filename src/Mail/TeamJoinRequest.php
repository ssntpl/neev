<?php

namespace Ssntpl\Neev\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamJoinRequest extends Mailable
{
    use Queueable, SerializesModels;

    public $username;
    public $team;
    public $owner;
    public $teamId;

    /**
     * Create a new message instance.
     */
    public function __construct($team, $username, $owner, $teamId)
    {
        $this->team = $team;
        $this->username = $username;
        $this->owner = $owner;
        $this->teamId = $teamId;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Team Join Request',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'neev.emails.team-join-request',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
