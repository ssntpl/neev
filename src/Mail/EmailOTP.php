<?php

namespace Ssntpl\Neev\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailOTP extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $username,
        public $otp,
        public $expiry,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verification Code');
    }

    public function content(): Content
    {
        return new Content(view: 'neev::emails.email-otp');
    }
}
