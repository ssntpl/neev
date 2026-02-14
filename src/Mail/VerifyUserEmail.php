<?php

namespace Ssntpl\Neev\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyUserEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $url,
        public $username,
        public $purpose = '',
        public $expiry = 15,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Email Verification');
    }

    public function content(): Content
    {
        return new Content(view: 'neev::emails.email-verify');
    }
}
