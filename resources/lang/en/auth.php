<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // Tenant-driven authentication
    'not_member' => 'You are not a member of this organization.',
    'sso_not_configured' => 'Single sign-on is not configured for this organization.',
    'sso_required' => 'This organization requires single sign-on authentication.',
    'auto_provision_disabled' => 'New user registration is not enabled for this organization. Please contact your administrator.',
];
