<?php

namespace Ssntpl\Neev\Enums;

/**
 * What an emailed code in the `otp` table was issued for. A code is checked
 * only against its own purpose, so one sent to verify an address cannot
 * reset a password, and a user holds one live code per purpose — asking
 * for one no longer cancels another in flight.
 */
enum OtpPurpose: string
{
    case EmailVerification = 'email_verification';
    case Confirmation = 'confirmation';
    case PasswordReset = 'password_reset';
}
