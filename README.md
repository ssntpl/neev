# Neev - Complete Laravel User Management Package

Neev is a powerful Laravel package that provides everything you need for user management in modern web applications. It includes authentication, team management, security features, and much more - all designed to be simple to use while being highly secure.

## What Does Neev Do?

Neev handles all the complex user management tasks so you can focus on building your application:

- **User Registration & Login** - Complete authentication system
- **Multi-Factor Authentication** - Extra security with authenticator apps and email codes
- **Team Management** - Users can create teams, invite members, and collaborate
- **API Tokens** - Secure API access for your applications
- **Security Monitoring** - Track login attempts, device information, and suspicious activity
- **Email Management** - Multiple email addresses per user with verification
- **Password Security** - Strong password rules, history tracking, and expiry policies
- **Modern Authentication** - Support for passkeys (WebAuthn) and social login

## Quick Start

### 1. Install the Package

```bash
composer require ssntpl/neev
```

### 2. Run the Setup Command

```bash
php artisan neev:install
```

The installer will ask you a few questions:
- Do you want team support? (recommended: yes)
- Do you want email verification? (recommended: yes for production)
- Do you want domain federation? (for organizations)

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Update Your User Model

Add these traits to your User model:

```php
use Ssntpl\Neev\Traits\HasTeams;
use Ssntpl\Neev\Traits\HasMultiAuth;
use Ssntpl\Neev\Traits\HasAccessToken;

class User extends Authenticatable
{
    use HasTeams, HasMultiAuth, HasAccessToken;
    
    // Your existing code...
}
```

That's it! Neev is now ready to use.

## Core Features

### 🔐 Authentication Methods

**Password Login**
- Secure password-based authentication
- Strong password requirements
- Password history to prevent reuse
- Automatic password expiry policies

**Magic Link Login**
- Passwordless authentication via email
- Secure, time-limited login links
- Perfect for users who prefer not to remember passwords

**Social Login (OAuth)**
- Support for Google, GitHub, Microsoft, Apple
- Easy setup with Laravel Socialite
- Automatic account linking

**Passkeys (WebAuthn)**
- Modern biometric authentication
- Works with fingerprint, face recognition, or security keys
- Most secure authentication method available

### 🛡️ Multi-Factor Authentication (MFA)

**Authenticator Apps**
```php
// Enable authenticator app MFA
$user->addMultiFactorAuth('authenticator');
// Returns QR code for setup
```

**Email OTP**
```php
// Enable email-based OTP
$user->addMultiFactorAuth('email');
```

**Recovery Codes**
```php
// Generate backup codes
$user->generateRecoveryCodes();
```

**Verify MFA**
```php
// Verify any MFA method
if ($user->verifyMFAOTP($method, $otp)) {
    // MFA verified successfully
}
```

### 👥 Team Management

**Create Teams**
```php
$team = Team::create([
    'name' => 'My Company',
    'user_id' => auth()->id(),
    'is_public' => false, // Private team
]);
```

**Invite Members**
```php
// Invite by email
$team->invitations()->create([
    'email' => 'user@example.com',
    'role' => 'member'
]);
```

**Switch Between Teams**
```php
// User can switch active team
$user->switchTeam($team);
```

**Team Relationships**
```php
$user->teams();        // Teams user belongs to
$user->ownedTeams();   // Teams user owns
$user->teamRequests(); // Pending invitations
```

### 🔑 API Token Management

**Create API Tokens**
```php
// Create token with specific permissions
$token = $user->createApiToken(
    name: 'Mobile App Token',
    permissions: ['read', 'write'],
    expiry: 60 // minutes
);

echo $token->plainTextToken; // Give this to the client
```

**Create Login Tokens**
```php
// For magic link authentication
$token = $user->createLoginToken(expiry: 15); // 15 minutes
```

**Token Permissions**
```php
// Check if token has permission
if ($token->can('write')) {
    // Allow write operations
}
```

### 📧 Email Management

**Multiple Emails per User**
```php
// Add additional email
$user->emails()->create([
    'email' => 'work@example.com',
    'is_primary' => false
]);
```

**Email Verification**
- Automatic verification emails
- Configurable verification requirements
- Resend verification links

### 🔍 Security & Monitoring

**Login Tracking**
- Every login attempt is recorded
- Device and browser information
- Geographic location (with GeoIP)
- Suspicious activity detection

**Session Management**
```php
// View all active sessions
$user->sessions();

// Logout from all devices
$user->logoutFromAllDevices();
```

**Failed Login Protection**
- Progressive delays after failed attempts
- Temporary account lockouts
- IP-based rate limiting

### 🌍 Geographic Features

**GeoIP Location Tracking**
```bash
# Download GeoIP database
php artisan neev:download-geolite
```

**Domain Federation**
- Automatic team joining based on email domain
- Perfect for organizations
- Custom domain rules and policies

## Configuration

Neev is highly configurable through `config/neev.php`:

### Feature Toggles
```php
'team' => true,              // Enable team management
'email_verified' => true,    // Require email verification
'domain_federation' => true, // Enable domain-based teams
'support_username' => false, // Allow username login
'magicauth' => true,        // Enable magic link login
```

### Security Settings
```php
'login_soft_attempts' => 5,    // Soft limit before delays
'login_hard_attempts' => 20,   // Hard limit before lockout
'login_block_minutes' => 1,    // Lockout duration

'password_soft_expiry_days' => 30, // Warning period
'password_hard_expiry_days' => 90, // Forced change
```

### MFA Configuration
```php
'multi_factor_auth' => [
    'authenticator', // TOTP apps
    'email',        // Email OTP
],
'recovery_codes' => 8, // Number of backup codes
```

## Available Routes

Neev provides all necessary routes out of the box:

### Authentication Routes
- `GET /register` - Registration form
- `POST /register` - Process registration
- `GET /login` - Login form
- `PUT /login` - Process login
- `POST /login/link` - Send magic link
- `GET /login/{id}/{hash}` - Magic link login
- `POST /logout` - Logout

### MFA Routes
- `GET /otp/mfa/{method}` - MFA verification form
- `POST /otp/mfa` - Verify MFA code
- `POST /otp/mfa/send` - Send email OTP

### Account Management
- `GET /account/profile` - User profile
- `GET /account/security` - Security settings
- `GET /account/tokens` - API tokens
- `GET /account/sessions` - Active sessions
- `GET /account/teams` - Team management

### Team Routes
- `GET /teams/create` - Create team
- `GET /teams/{team}/members` - Team members
- `POST /teams/create` - Store new team
- `PUT /teams/switch` - Switch active team

## Console Commands

### Setup Commands
```bash
# Install Neev with interactive setup
php artisan neev:install

# Create roles and permissions
php artisan neev:create-permission
php artisan neev:create-role
```

### Maintenance Commands
```bash
# Clean old login records (runs automatically)
php artisan neev:clean-login-history

# Clean old password history
php artisan neev:clean-passwords

# Download/update GeoIP database
php artisan neev:download-geolite

# Send push notifications
php artisan neev:push-notification
```

## Advanced Features

### Push Notifications
Neev includes Firebase Cloud Messaging support:

```php
// Configure in config/neev.php
'firebase' => [
    'api_key' => env('FIREBASE_API_KEY'),
    'project_id' => env('FIREBASE_PROJECT_ID'),
    // ... other Firebase config
],
```

### Custom Models
You can use your own models:

```php
// In config/neev.php
'user_model' => App\Models\User::class,
'team_model' => App\Models\Organization::class,
```

### Password Rules
Customize password requirements:

```php
'password' => [
    'required',
    'confirmed',
    Password::min(8)->mixedCase()->numbers()->symbols(),
    PasswordHistory::notReused(5), // Can't reuse last 5 passwords
    PasswordUserData::notContain(['name', 'email']), // Can't contain personal info
],
```

### Username Support
Enable username-based authentication:

```php
'support_username' => true,
'username' => [
    'required', 'string', 'min:3', 'max:20',
    'regex:/^(?![._])(?!.*[._]{2})[a-zA-Z0-9._]+(?<![._])$/',
    'unique:users,username',
],
```

## Security Best Practices

1. **Always use HTTPS** in production
2. **Enable email verification** for new accounts
3. **Set up GeoIP tracking** to detect suspicious logins
4. **Configure strong password rules**
5. **Enable MFA** for admin accounts
6. **Regularly clean old data** using provided commands
7. **Monitor login attempts** for unusual patterns

## Database Tables

Neev creates these tables:

- `users` - User accounts
- `teams` - Team information
- `memberships` - Team memberships
- `team_invitations` - Team invitations
- `access_tokens` - API and login tokens
- `multi_factor_auths` - MFA configurations
- `recovery_codes` - MFA backup codes
- `login_attempts` - Login history and tracking
- `user_devices` - Device registration for push notifications
- `passkeys` - WebAuthn credentials
- `otps` - One-time passwords
- `domain_rules` - Domain federation rules
- `password_histories` - Password change history

## Troubleshooting

### Common Issues

**GeoIP not working?**
```bash
# Make sure you have a MaxMind license key
php artisan neev:download-geolite
```

**Teams not showing?**
- Make sure you enabled teams during installation
- Check that your User model uses the `HasTeams` trait

**MFA codes not working?**
- Check system time is synchronized
- Verify the secret key is correctly stored

**Emails not sending?**
- Configure your mail settings in `.env`
- Check Laravel's mail configuration

### Getting Help

If you need help:
1. Check the [GitHub issues](https://github.com/ssntpl/neev/issues)
2. Review the configuration file comments
3. Enable Laravel debugging to see detailed errors

## Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- MySQL, PostgreSQL, or SQLite database
- Redis (recommended for caching and sessions)

## License

Neev is open-source software. Please check the license file for details.

## Credits

Created and maintained by [Abhishek Sharma](https://ssntpl.com) at [SSNTPL](https://ssntpl.com).

---

**Ready to get started?** Run `composer require ssntpl/neev` and `php artisan neev:install` to begin!
