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

    public $username;

    public $otp;
    public $expiry;

    /**
     * Create a new message instance.
     */
    public function __construct($username, $otp, $expiry)
    {
        $this->username = $username;
        $this->otp = $otp;
        $this->expiry = $expiry;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Email OTP',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'neev::emails.email-otp',
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
