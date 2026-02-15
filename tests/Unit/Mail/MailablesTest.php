<?php

namespace Ssntpl\Neev\Tests\Unit\Mail;

use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Tests\TestCase;

class MailablesTest extends TestCase
{
    // =================================================================
    // VerifyUserEmail
    // =================================================================

    public function test_verify_user_email_has_correct_subject(): void
    {
        $mailable = new VerifyUserEmail('https://example.com/verify', 'John');

        $envelope = $mailable->envelope();

        $this->assertSame('Email Verification', $envelope->subject);
    }

    public function test_verify_user_email_has_correct_view(): void
    {
        $mailable = new VerifyUserEmail('https://example.com/verify', 'John');

        $content = $mailable->content();

        $this->assertSame('neev::emails.email-verify', $content->view);
    }

    public function test_verify_user_email_stores_constructor_properties(): void
    {
        $mailable = new VerifyUserEmail(
            url: 'https://example.com/verify',
            username: 'John',
            purpose: 'registration',
            expiry: 30,
        );

        $this->assertSame('https://example.com/verify', $mailable->url);
        $this->assertSame('John', $mailable->username);
        $this->assertSame('registration', $mailable->purpose);
        $this->assertSame(30, $mailable->expiry);
    }

    public function test_verify_user_email_has_default_purpose_and_expiry(): void
    {
        $mailable = new VerifyUserEmail('https://example.com/verify', 'John');

        $this->assertSame('', $mailable->purpose);
        $this->assertSame(15, $mailable->expiry);
    }

    // =================================================================
    // EmailOTP
    // =================================================================

    public function test_email_otp_has_correct_subject(): void
    {
        $mailable = new EmailOTP('John', '123456', 15);

        $envelope = $mailable->envelope();

        $this->assertSame('Verification Code', $envelope->subject);
    }

    public function test_email_otp_has_correct_view(): void
    {
        $mailable = new EmailOTP('John', '123456', 15);

        $content = $mailable->content();

        $this->assertSame('neev::emails.email-otp', $content->view);
    }

    public function test_email_otp_stores_constructor_properties(): void
    {
        $mailable = new EmailOTP('Jane', '654321', 10);

        $this->assertSame('Jane', $mailable->username);
        $this->assertSame('654321', $mailable->otp);
        $this->assertSame(10, $mailable->expiry);
    }

    // =================================================================
    // LoginUsingLink
    // =================================================================

    public function test_login_using_link_has_correct_subject(): void
    {
        $mailable = new LoginUsingLink('https://example.com/login-link');

        $envelope = $mailable->envelope();

        $this->assertSame('Login Link', $envelope->subject);
    }

    public function test_login_using_link_has_correct_view(): void
    {
        $mailable = new LoginUsingLink('https://example.com/login-link');

        $content = $mailable->content();

        $this->assertSame('neev::emails.login-link', $content->view);
    }

    public function test_login_using_link_stores_constructor_properties(): void
    {
        $mailable = new LoginUsingLink('https://example.com/login-link', 30);

        $this->assertSame('https://example.com/login-link', $mailable->url);
        $this->assertSame(30, $mailable->expiry);
    }

    public function test_login_using_link_has_default_expiry(): void
    {
        $mailable = new LoginUsingLink('https://example.com/login-link');

        $this->assertSame(15, $mailable->expiry);
    }

    // =================================================================
    // TeamInvitation
    // =================================================================

    public function test_team_invitation_has_correct_subject(): void
    {
        $mailable = new TeamInvitation('Acme Corp', 'John');

        $envelope = $mailable->envelope();

        $this->assertSame('Team Invitation', $envelope->subject);
    }

    public function test_team_invitation_has_correct_view(): void
    {
        $mailable = new TeamInvitation('Acme Corp', 'John');

        $content = $mailable->content();

        $this->assertSame('neev::emails.team-invitation', $content->view);
    }

    public function test_team_invitation_stores_constructor_properties(): void
    {
        $mailable = new TeamInvitation(
            team: 'Acme Corp',
            username: 'John',
            url: 'https://example.com/invite',
            expiry: 48,
            userExist: false,
        );

        $this->assertSame('Acme Corp', $mailable->team);
        $this->assertSame('John', $mailable->username);
        $this->assertSame('https://example.com/invite', $mailable->url);
        $this->assertSame(48, $mailable->expiry);
        $this->assertFalse($mailable->userExist);
    }

    public function test_team_invitation_has_default_values(): void
    {
        $mailable = new TeamInvitation('Acme Corp', 'John');

        $this->assertNull($mailable->url);
        $this->assertNull($mailable->expiry);
        $this->assertTrue($mailable->userExist);
    }

    // =================================================================
    // TeamJoinRequest
    // =================================================================

    public function test_team_join_request_has_correct_subject(): void
    {
        $mailable = new TeamJoinRequest('Acme Corp', 'John', 'Admin', 1);

        $envelope = $mailable->envelope();

        $this->assertSame('Team Join Request', $envelope->subject);
    }

    public function test_team_join_request_has_correct_view(): void
    {
        $mailable = new TeamJoinRequest('Acme Corp', 'John', 'Admin', 1);

        $content = $mailable->content();

        $this->assertSame('neev::emails.team-join-request', $content->view);
    }

    public function test_team_join_request_stores_constructor_properties(): void
    {
        $mailable = new TeamJoinRequest(
            team: 'Acme Corp',
            username: 'John',
            owner: 'Admin',
            teamId: 42,
        );

        $this->assertSame('Acme Corp', $mailable->team);
        $this->assertSame('John', $mailable->username);
        $this->assertSame('Admin', $mailable->owner);
        $this->assertSame(42, $mailable->teamId);
    }
}
