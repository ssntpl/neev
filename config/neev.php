<?php

use Illuminate\Validation\Rules\Password;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;

return [
    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Core feature switches that control major functionality in your application.
    | Enabling/disabling these affects database migrations, routes, and UI components.
    |
    */

    /*
    | Team Management System
    | ----------------------
    | When enabled: Users can create teams, invite members, manage team settings,
    | switch between teams, and use team-scoped resources.
    | Database: Requires teams, team_user, team_invitations tables
    | UI: Adds team switcher, team settings, member management interfaces
    | Default: false (single-user mode)
    */
    'team' => false,

    /*
    | Email Verification Requirement
    | ------------------------------
    | When enabled: New users must verify their email before accessing the application.
    | Blocks unverified users from login and sends verification emails automatically.
    | Database: Uses email_verified_at column in users table
    | Routes: Adds email verification routes and middleware
    | Default: false (no verification required)
    */
    'email_verified' => false,

    /*
    | Company Email Requirement
    | -------------------------
    | When enabled: Users registering with free email providers (Gmail, Yahoo, etc.)
    | will have their team created in an inactive state (waitlisted).
    | They can still register, verify email, and access dashboard with a notice.
    | Admin can activate the team later.
    | Requires: 'team' feature to be enabled
    | Default: false (all email domains allowed equally)
    */
    'require_company_email' => false,

    /*
    | Additional Free Email Domains
    | -----------------------------
    | Add custom domains to the default free email provider list.
    | These domains will be considered "free" and subject to waitlist if
    | 'require_company_email' is enabled. Use this to block additional domains.
    | The default list already includes: Gmail, Yahoo, Hotmail, Outlook,
    | iCloud, ProtonMail, AOL, and many other common providers.
    */
    'free_email_domains' => [
        // 'example.com',
        // 'tempmail.org',
    ],

    /*
    | Domain-Based Team Federation
    | ----------------------------
    | When enabled: Users with matching email domains can automatically join teams
    | or have special rules applied. Useful for organizations with company domains.
    | Requires: 'team' feature to be enabled
    | Example: All users with @company.com can auto-join "Company Team"
    | Default: false (manual team management only)
    */
    'domain_federation' => false,

    /*
    | Tenant Isolation (Multi-Tenancy)
    | ---------------------------------
    | When enabled: Teams are isolated by domain/subdomain. Each tenant runs on
    | their own domain (tenant1.yourapp.com) or custom domain (tenant1.com).
    | Requires: 'team' feature to be enabled
    | Database: Requires tenant_domains table
    | Middleware: Adds 'neev:tenant' and 'neev:tenant-api' middleware groups
    | Use case: SaaS applications with isolated tenants
    | Default: false (no domain-based tenant isolation)
    */
    'tenant_isolation' => false,

    /*
    | Tenant Isolation Options
    | ------------------------
    | Configuration options for tenant isolation feature.
    | Only active when 'tenant_isolation' is enabled.
    */
    'tenant_isolation_options' => [
        /*
        | Subdomain Suffix
        | ----------------
        | The base domain suffix for subdomain-based tenants.
        | Example: '.yourapp.com' means tenants use tenant1.yourapp.com
        | Set to null to disable subdomain support and require full domain lookup.
        */
        'subdomain_suffix' => env('NEEV_SUBDOMAIN_SUFFIX', null),

        /*
        | Allow Custom Domains
        | --------------------
        | When true: Tenants can use their own custom domains (e.g., tenant.com)
        | Custom domains require DNS verification before activation.
        */
        'allow_custom_domains' => true,

        /*
        | Single Tenant Users
        | -------------------
        | When true: Users can only belong to one team/tenant.
        | New users are automatically assigned to the current tenant on registration.
        | When false: Users can belong to multiple teams (standard neev behavior).
        */
        'single_tenant_users' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Slug Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for team slugs which are used as URL-friendly identifiers.
    | Slugs are auto-generated from team names if not provided explicitly.
    |
    */
    'slug' => [
        /*
        | Minimum Slug Length
        | -------------------
        | The minimum allowed length for team slugs.
        | Slugs shorter than this will be padded.
        */
        'min_length' => 2,

        /*
        | Maximum Slug Length
        | -------------------
        | The maximum allowed length for team slugs.
        | Set to 63 to comply with DNS label limits for subdomains.
        */
        'max_length' => 63,

        /*
        | Reserved Slugs
        | --------------
        | Slugs that cannot be used by teams.
        | Add common subdomains you want to reserve for your application.
        */
        'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn', 'assets', 'static'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant-Driven Authentication
    |--------------------------------------------------------------------------
    |
    | When enabled, each tenant can configure their own authentication method.
    | Tenants choose between password authentication (default) or SSO via
    | external identity providers (Microsoft Entra ID, Google, Okta, etc.).
    | Requires: 'tenant_isolation' feature to be enabled.
    |
    */

    /*
    | Enable Tenant-Driven Authentication
    | ------------------------------------
    | When true: Tenants can configure their authentication method (password or SSO).
    | The auth method is checked on login and users are routed accordingly.
    | Requires: 'tenant_isolation' = true
    | Default: false
    */
    'tenant_auth' => false,

    /*
    | Tenant Authentication Options
    | -----------------------------
    | Configuration options for tenant-driven authentication.
    | Only active when 'tenant_auth' is enabled.
    */
    'tenant_auth_options' => [
        /*
        | Default Authentication Method
        | -----------------------------
        | The authentication method used when a tenant has no custom settings.
        | Options: 'password' (username/password) or 'sso' (external identity provider)
        | Recommended: 'password' (most tenants will use this)
        */
        'default_method' => 'password',

        /*
        | Supported SSO Providers
        | -----------------------
        | List of SSO providers that tenants can configure.
        | Each provider requires the corresponding Socialite driver.
        | Add providers as needed for your application.
        |
        | Built-in support:
        | 'entra'  - Microsoft Entra ID (Azure AD)
        | 'google' - Google Workspace
        | 'okta'   - Okta Identity
        |
        | Note: Tenants can only configure providers listed here.
        */
        'sso_providers' => ['entra', 'google', 'okta'],

        /*
        | Auto-Provisioning Default
        | -------------------------
        | When true: Users authenticated via SSO who are not yet members of
        | the tenant will be automatically added as members.
        | When false: Only existing members can authenticate; others are rejected.
        |
        | This is the default value; tenants can override per-tenant.
        */
        'auto_provision' => false,

        /*
        | Auto-Provision Default Role
        | ---------------------------
        | The role assigned to users who are auto-provisioned via SSO.
        | Set to null for no role, or a role name string.
        */
        'auto_provision_role' => null,
    ],

    /*
    | Username Authentication Support
    | -------------------------------
    | When enabled: Users can register and login with usernames instead of just emails.
    | Adds username field to registration forms and allows username-based login.
    | Database: Requires username column in users table
    | Validation: Uses 'username' validation rules defined below
    | Default: false (email-only authentication)
    */
    'support_username' => false,

    /*
    | Magic Link Authentication
    | -------------------------
    | When enabled: Users can login via secure links sent to their email.
    | Provides passwordless authentication option alongside traditional login.
    | Security: Links expire after use and have time limits
    | Use case: Convenient for users who prefer not to remember passwords
    | Default: true (magic links available)
    */
    'magicauth' => true,

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    |
    | Specify which Eloquent models to use for core entities. You can extend
    | the default models or completely replace them with your own implementations.
    | Your custom models must implement the same interfaces and use required traits.
    |
    */

    /*
    | Team Model Class
    | ----------------
    | The Eloquent model used for team management functionality.
    | Custom model requirements:
    | - Must extend Ssntpl\Neev\Models\Team or implement same interface
    | - Should use HasFactory trait for testing
    | - Must have relationships: owner(), users(), invitations()
    | Example: App\Models\Organization::class
    */
    'team_model' => Ssntpl\Neev\Models\Team::class,

    /*
    | User Model Class
    | ----------------
    | The Eloquent model used for user authentication and management.
    | Custom model requirements:
    | - Must extend Illuminate\Foundation\Auth\User
    | - Must use required traits: HasTeams, HasMultiAuth, HasAccessToken
    | - Should implement: MustVerifyEmail (if email verification enabled)
    | Example: App\Models\User::class
    */
    'user_model' => Ssntpl\Neev\Models\User::class,

    /*
    |--------------------------------------------------------------------------
    | Domain-Based Security Rules
    |--------------------------------------------------------------------------
    |
    | Security policies automatically applied to users based on their email domain.
    | Only active when 'domain_federation' is enabled. Rules are enforced at
    | login and during security-sensitive operations.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Application URLs
    |--------------------------------------------------------------------------
    |
    | URLs used for redirects, email links, and navigation throughout the application.
    | These should be absolute URLs including protocol and domain.
    |
    */

    /*
    | Post-Authentication Redirect URL
    | --------------------------------
    | Where to redirect users after successful login, registration, or email verification.
    | Should point to your main application dashboard or home page.
    | Used in: Login controllers, email verification, magic link authentication
    | Format: Full URL with protocol (https://yourdomain.com/dashboard)
    | Environment: Can be overridden with NEEV_DASHBOARD_URL env variable
    */
    'dashboard_url' => env('NEEV_DASHBOARD_URL', env('APP_URL').'/dashboard'),

    'frontend_url' => env('APP_URL'),

    /*
    |--------------------------------------------------------------------------
    | Multi-Factor Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configure available MFA methods, recovery options, and security settings.
    | MFA adds an extra layer of security beyond username/password authentication.
    |
    */

    /*
    | Available MFA Methods
    | ---------------------
    | Supported authentication methods users can enable:
    |
    | 'authenticator' - Time-based One-Time Password (TOTP)
    |                   Works with Google Authenticator, Authy, 1Password, etc.
    |                   Generates QR codes for easy setup
    |                   Most secure option (offline, device-based)
    |
    | 'email' - One-Time Password via Email
    |           Sends 6-digit codes to user's verified email
    |           Good fallback option but less secure than TOTP
    |           Requires working email delivery system
    |
    | Users can enable multiple methods and choose their preferred one.
    */
    'multi_factor_auth' => [
        'authenticator', // TOTP via authenticator apps
        'email',        // OTP via email delivery
    ],

    /*
    | Recovery Codes Count
    | --------------------
    | Number of single-use recovery codes generated for each user.
    | Used when primary MFA methods are unavailable (lost phone, no email access).
    | Security considerations:
    | - More codes = more convenience but larger attack surface
    | - Fewer codes = better security but higher lockout risk
    | - Codes are hashed in database and shown only once
    | - Users should store codes securely (password manager, printed copy)
    | Recommended: 8-16 codes
    */
    'recovery_codes' => 8,

    /*
    |--------------------------------------------------------------------------
    | OAuth Social Authentication Providers
    |--------------------------------------------------------------------------
    |
    | Third-party authentication providers for social login functionality.
    | Each provider requires additional configuration in config/services.php
    | and corresponding OAuth app setup with the provider.
    |
    */

    /*
    | Supported OAuth Providers
    | -------------------------
    | Uncomment providers you want to enable. Each requires:
    |
    | 1. OAuth app registration with the provider
    | 2. Client ID and secret in config/services.php
    | 3. Proper redirect URLs configured
    | 4. Implement using socialite package
    | Link - https://socialiteproviders.com/about
    |
    | 'google'    - Google OAuth 2.0 (requires Google Cloud Console setup)
    | 'github'    - GitHub OAuth (requires GitHub App or OAuth App)
    | 'microsoft' - Microsoft OAuth (requires Azure App Registration)
    | 'apple'     - Sign in with Apple (requires Apple Developer setup)
    |
    | Security note: OAuth providers bypass password requirements as well as
    | MFA settings and other security policies.
    */
    'oauth' => [
        // 'google',    // Enable Google Sign-In
        // 'github',    // Enable GitHub Sign-In
        // 'microsoft', // Enable Microsoft Sign-In
        // 'apple',     // Enable Sign in with Apple
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Tracking & Security Monitoring
    |--------------------------------------------------------------------------
    |
    | Comprehensive login tracking system for security monitoring, user activity
    | analysis, and geographic location detection. Helps detect suspicious activity
    | and provides users with visibility into their account access.
    |
    */

    /*
    | Failed Attempt Storage Method
    | -----------------------------
    | Choose where to store failed login attempts for rate limiting:
    |
    | false (cache) - Store in Redis/Memcached (faster, automatic cleanup)
    |                 Recommended for high-traffic applications
    |                 Attempts reset on cache clear/restart
    |
    | true (database) - Store in database table (persistent, auditable)
    |                   Better for compliance and long-term analysis
    |                   Requires manual cleanup of old records
    |
    | Performance impact: Database storage is slower but more reliable
    */
    'record_failed_login_attempts' => false,

    /*
    | Login History Retention Period
    | ------------------------------
    | Number of days to keep detailed login history records in the database.
    | Includes successful logins, device information, IP addresses, and locations.
    |
    | Considerations:
    | - Longer retention = better security analysis but more storage
    | - Shorter retention = less storage but limited historical data
    | - Compliance requirements may dictate minimum retention periods
    | - Use 'neev:clean-login-attempts' command to clean old records
    |
    | Recommended: 30-90 days depending on security requirements
    */
    'last_login_attempts_in_days' => 30,

    /*
    | GeoIP Database File Path
    | ------------------------
    | Local path to MaxMind GeoLite2 database file for IP geolocation.
    | Used to determine city, country, and coordinates from IP addresses.
    |
    | Setup:
    | 1. Run 'php artisan neev:download-geolite' to download database
    | 2. Database updates monthly, set up cron job for automatic updates
    | 3. File size: ~70MB, ensure adequate storage space
    |
    | Path format: Relative to Laravel storage path
    | Alternative: Use absolute path if stored elsewhere
    */
    'geo_ip_db' => 'app/geoip/GeoLite2-City.mmdb',

    /*
    | MaxMind Database Edition
    | ------------------------
    | Which MaxMind database edition to use for geolocation.
    |
    | Options:
    | 'GeoLite2-City'    - Free, city-level accuracy (recommended)
    | 'GeoLite2-Country' - Free, country-level accuracy only
    | 'GeoIP2-City'      - Paid, higher accuracy (requires subscription)
    |
    | Environment: Override with MAXMIND_EDITION env variable
    */
    'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),

    /*
    | MaxMind License Key
    | -------------------
    | Your MaxMind license key for downloading GeoLite2 databases.
    | Required even for free GeoLite2 databases (registration required).
    |
    | Setup:
    | 1. Register at https://www.maxmind.com/en/geolite2/signup
    | 2. Generate license key in your account
    | 3. Add MAXMIND_LICENSE_KEY to your .env file
    |
    | Security: Keep this key secure, don't commit to version control
    */
    'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Username Validation Rules
    |--------------------------------------------------------------------------
    |
    | Laravel validation rules applied to usernames when 'support_username' is enabled.
    | These rules ensure usernames are secure, user-friendly, and database-safe.
    | Only applies during registration and username change operations.
    |
    */

    /*
    | Username Validation Rules Breakdown:
    | ------------------------------------
    | 'required'     - Username field cannot be empty
    | 'string'       - Must be a string value (not numeric or array)
    | 'min:3'        - Minimum 3 characters (prevents very short usernames)
    | 'max:20'       - Maximum 20 characters (prevents excessively long usernames)
    |
    | Regex Pattern Explanation:
    | ^(?![._])           - Cannot start with dot or underscore
    | (?!.*[._]{2})       - Cannot have consecutive dots or underscores
    | [a-zA-Z0-9._]+      - Only letters, numbers, dots, and underscores allowed
    | (?<![._])$          - Cannot end with dot or underscore
    |
    | Valid examples: 'john_doe', 'user123', 'jane.smith'
    | Invalid examples: '_user', 'user..name', 'user_', 'user@domain'
    |
    | 'unique:users,username' - Must be unique across all users
    |                          Prevents duplicate usernames in the system
    |
    | Customization: Modify these rules based on your application's requirements
    | Consider: International characters, longer lengths, different patterns
    */
    'username' => [
        'required',
        'string',
        'min:3',                                                    // Minimum length for usability
        'max:20',                                                   // Maximum length for UI/UX considerations
        'regex:/^(?![._])(?!.*[._]{2})[a-zA-Z0-9._]+(?<![._])$/',   // Alphanumeric with dots/underscores, no consecutive or leading/trailing special chars
        'unique:users,username',                                    // Enforce uniqueness in users table
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Security & Validation Rules
    |--------------------------------------------------------------------------
    |
    | Comprehensive password validation system combining Laravel's built-in rules
    | with custom security rules. Applied during registration, password changes,
    | and password resets to ensure strong, secure passwords.
    |
    */

    /*
    | Password Validation Rules Breakdown:
    | ------------------------------------
    |
    | Basic Laravel Rules:
    | 'required'   - Password field cannot be empty
    | 'confirmed'  - Must match 'password_confirmation' field
    |               Prevents typos during password entry
    |
    | Password Complexity (Laravel Password Rule):
    | ->min(8)     - Minimum 8 characters (industry standard)
    | ->max(72)    - Maximum 72 characters (bcrypt limitation)
    | ->letters()  - Must contain at least one letter (a-z, A-Z)
    | ->mixedCase()- Must contain both uppercase AND lowercase letters
    | ->numbers()  - Must contain at least one number (0-9)
    | ->symbols()  - Must contain at least one symbol (!@#$%^&* etc.)
    |
    | Custom Security Rules:
    |
    | PasswordHistory::notReused(5)
    | - Prevents reusing the last 5 passwords
    | - Passwords are hashed and stored in password_histories table
    | - Helps prevent password cycling attacks
    | - Configurable count (5 is recommended minimum)
    |
    | PasswordUserData::notContain(['name', 'email'])
    | - Prevents passwords containing user's personal information
    | - Checks against 'name' and 'email' fields from user model
    | - Case-insensitive matching
    | - Prevents easily guessable passwords
    | - Add more fields as needed: ['name', 'email', 'username', 'phone']
    |
    | Security Benefits:
    | - Prevents common password attacks (dictionary, brute force)
    | - Ensures password uniqueness over time
    | - Blocks personally identifiable information in passwords
    | - Meets most compliance requirements (PCI DSS, NIST, etc.)
    |
    | Example valid password: 'MySecure123!'
    | Example invalid passwords: 'password', '12345678', 'john@email.com'
    */
    'password' => [
        'required',
        'confirmed',                                                // Must match password_confirmation field
        Password::min(8)                                      // Minimum 8 characters
            ->max(72)                                         // Maximum 72 characters (bcrypt limit)
            ->letters()                                             // Must contain letters
            ->mixedCase()                                           // Must contain both upper and lowercase
            ->numbers()                                             // Must contain numbers
            ->symbols(),                                            // Must contain symbols
        PasswordHistory::notReused(5),                       // Cannot reuse last 5 passwords
        PasswordUserData::notContain(['name', 'email']),   // Cannot contain user's personal data
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Rate Limiting & Brute Force Protection
    |--------------------------------------------------------------------------
    |
    | Multi-tiered protection system against brute force attacks and credential
    | stuffing. Implements progressive delays and temporary lockouts to balance
    | security with user experience.
    |
    */

    /*
    | Soft Limit - Progressive Delay
    | ------------------------------
    | Number of failed attempts before introducing delays between login attempts.
    | After this limit, each subsequent attempt has an increasing delay.
    |
    | Behavior:
    | - Attempts 1-5: No delay (normal login speed)
    | - Attempts 6+: Progressive delay (1s, 2s, 4s, 8s, etc.)
    | - User can still attempt login but must wait between attempts
    | - Helps slow down automated attacks while allowing legitimate users
    |
    | Recommended: 3-5 attempts (balance between security and UX)
    */
    'login_soft_attempts' => 5,

    /*
    | Hard Limit - Account Lockout
    | ----------------------------
    | Number of failed attempts before completely blocking login attempts.
    | After this limit, no login attempts are allowed for the specified duration.
    |
    | Behavior:
    | - Completely blocks all login attempts from the IP/account
    | - Requires waiting for the full lockout period
    | - More aggressive protection against persistent attacks
    | - May require admin intervention or password reset
    |
    | Considerations:
    | - Too low: Legitimate users get locked out easily
    | - Too high: Less protection against brute force
    | - Should be significantly higher than soft limit
    |
    | Recommended: 15-25 attempts (allows for user error while blocking attacks)
    */
    'login_hard_attempts' => 20,

    /*
    | Lockout Duration
    | ----------------
    | Minutes to block login attempts after hard limit is reached.
    | During this period, no login attempts are allowed from the blocked IP/account.
    |
    | Considerations:
    | - Shorter duration: Better user experience, less protection
    | - Longer duration: Better security, potential user frustration
    | - Consider business hours and user patterns
    | - Balance between security and accessibility
    |
    | Common values:
    | - 1-5 minutes: Light protection, good UX
    | - 15-30 minutes: Moderate protection
    | - 60+ minutes: Strong protection, may impact legitimate users
    |
    | Note: Users can still reset password or contact support during lockout
    */
    'login_block_minutes' => 1,

    /*
    |--------------------------------------------------------------------------
    | Password Aging & Expiry Policies
    |--------------------------------------------------------------------------
    |
    | Automated password aging system to enforce regular password changes.
    | Helps maintain security by ensuring passwords don't remain static for
    | extended periods. Implements warning system before forced expiry.
    |
    */

    /*
    | Soft Expiry - Warning Period
    | ----------------------------
    | Days after password creation/change when users start receiving warnings
    | about upcoming password expiry. Provides advance notice for users to
    | change passwords voluntarily before forced expiry.
    |
    | Behavior:
    | - Shows warning messages during login
    | - Sends email notifications (if configured)
    | - Users can still access the application normally
    | - Encourages proactive password changes
    |
    | Calculation: If hard expiry is 90 days and soft expiry is 30 days,
    | warnings start 30 days before the 90-day hard expiry (at day 60).
    |
    | Recommended: 7-30 days before hard expiry
    | Consider: User behavior, notification fatigue, business requirements
    */
    'password_soft_expiry_days' => 30,

    /*
    | Hard Expiry - Forced Password Change
    | ------------------------------------
    | Days after password creation/change when passwords are forcibly expired.
    | Users cannot access the application until they change their password.
    |
    | Behavior:
    | - Blocks application access completely
    | - Redirects to password change form
    | - Cannot bypass without changing password
    | - New password must meet all validation rules
    | - Resets the expiry timer for the new password
    |
    | Compliance Considerations:
    | - PCI DSS: Recommends 90 days maximum
    | - NIST: No longer recommends forced expiry unless breach suspected
    | - Industry standards: 60-90 days common
    | - Balance security with user experience
    |
    | Recommended Values:
    | - High security: 60-90 days
    | - Standard security: 90-180 days
    | - Low security: 365 days or disabled (set to 0)
    |
    | Note: Set to 0 to disable password expiry entirely
    */
    'password_hard_expiry_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Token & OTP Expiry Configuration
    |--------------------------------------------------------------------------
    |
    | Time limits for various tokens and one-time passwords used throughout
    | the authentication system. Values are in minutes for consistency.
    |
    */

    /*
    | URL Token Expiry Time
    | ---------------------
    | Minutes before magic links, password reset links, and email verification
    | links expire. Shorter times are more secure but less user-friendly.
    | Recommended: 15-60 minutes
    */
    'url_expiry_time' => 60,

    /*
    | OTP Expiry Time
    | ---------------
    | Minutes before one-time passwords (email OTP, MFA codes) expire.
    | Should be short enough to prevent replay attacks but long enough
    | for users to receive and enter the code. Recommended: 5-15 minutes
    */
    'otp_expiry_time' => 15,

    /*
    |--------------------------------------------------------------------------
    | OTP Generation Range
    |--------------------------------------------------------------------------
    |
    | Numeric range for generating one-time passwords. Defines minimum and
    | maximum values for 6-digit OTP codes sent via email or SMS.
    |
    */

    /*
    | OTP Minimum Value
    | -----------------
    | Smallest possible OTP value. Set to 100000 to ensure all OTPs
    | are exactly 6 digits (no leading zeros displayed to users).
    */
    'otp_min' => 100000,

    /*
    | OTP Maximum Value
    | -----------------
    | Largest possible OTP value. Set to 999999 to ensure all OTPs
    | are exactly 6 digits for consistent user experience.
    */
    'otp_max' => 999999,
];
