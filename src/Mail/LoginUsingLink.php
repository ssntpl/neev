<?php

namespace Ssntpl\Neev\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginUsingLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $url,
        public $expiry = 15,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Login Link');
    }

    public function content(): Content
    {
        return new Content(view: 'neev::emails.login-link');
    }
}
