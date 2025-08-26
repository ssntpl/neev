# Neev - Laravel User Management Package

Neev is a comprehensive Laravel starter kit for user management, providing a robust set of features for authentication, authorization, team management, and API access control.

## Features

### 1. Multi-Factor Authentication (MFA)
- Multiple MFA methods support:
  - Authenticator App (TOTP)
  - Email OTP
  - Recovery Codes
- Configurable preferred MFA method
- Automatic recovery codes generation
- QR code generation for authenticator apps

### 2. Team Management
- Team creation and ownership
- Team invitations system
- Team membership management
- Public/Private team settings
- Domain-based federation
- Team switching capabilities
- Team join requests

### 3. Access Token Management
- API token generation and management
- Login token support
- Token permissions system
- Token expiry configuration
- Token usage tracking

### 4. Role-Based Access Control
- Role management
- Permission-based authorization
- Granular access control
- Domain rules support

### 5. Email Management
- Multi-email support
- Email verification
- Team invitations via email
- Email-based OTP delivery

### 6. Session & Login Management
- Active session tracking across devices
- Detailed login history with device information
- Geographic location tracking of logins
- Browser and platform detection
- Multi-device session management
- Ability to terminate specific sessions
- Configurable session cleanup
- Rate limiting and blocking for failed attempts
- Password history tracking
- GeoIP integration for location tracking

## Installation

```bash
# Install via composer
composer require ssntpl/neev

# Run the install command to set up configurations and migrations
php artisan neev:install

# The install command will automatically:
# - Publish configurations
# - Set up necessary migrations
# - Configure basic settings
# - Set up optional features (teams, roles, domain federation) based on your choices
```

## Configuration

The package configuration file is located at `config/neev.php`. Here you can configure:

- MFA settings
- Team settings
- Domain federation
- Recovery codes count
- Role-based access control

## Usage

### User Model Setup

Your User model should use the following traits:

```php
use Ssntpl\Neev\Traits\HasTeams;
use Ssntpl\Neev\Traits\HasMultiAuth;
use Ssntpl\Neev\Traits\HasAccessToken;

class User extends Authenticatable
{
    use HasTeams;
    use HasMultiAuth;
    use HasAccessToken;
    
    // ... rest of your model
}
```

### Multi-Factor Authentication

```php
// Add a new MFA method
$user->addMultiFactorAuth('authenticator');
$user->addMultiFactorAuth('email');

// Verify MFA OTP
$user->verifyMFAOTP($method, $otp);

// Generate recovery codes
$user->generateRecoveryCodes();
```

### Team Management

```php
// Create a team
$team = Team::create([
    'name' => 'My Team',
    'user_id' => auth()->id(),
    'is_public' => false,
]);

// Switch team
$user->switchTeam($team);

// Get user's teams
$user->teams(); // Teams user is member of
$user->ownedTeams(); // Teams owned by user
$user->allTeams(); // All teams including pending invitations
```

### Access Tokens

```php
// Create API token
$token = $user->createApiToken(
    name: 'Token Name',
    permissions: ['read', 'write'],
    expiry: 60 // minutes
);

// Create login token
$token = $user->createLoginToken($expiry);

// Access token management
$user->accessTokens(); // All tokens
$user->apiTokens(); // API tokens only
$user->loginTokens(); // Login tokens only
```

## Console Commands

The package includes several helpful commands:

```bash
# Install Neev
php artisan neev:install

# Create permissions
php artisan neev:create-permission

# Clean old login history
php artisan neev:clean-login-history

# Clean old passwords
php artisan neev:clean-passwords

# Download GeoLite database (for location tracking)
php artisan neev:download-geolite
```

## Security Features

1. Password History
   - Tracks password changes
   - Prevents password reuse
   - Configurable history length

2. Login History
   - Tracks login attempts
   - Stores device and location information
   - Automatic cleanup of old records

3. Access Token Security
   - Hashed token storage
   - Configurable permissions
   - Expiry management
   - Last used tracking

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/ssntpl/neev/issues).

## License

This package is currently unlicensed. Please contact the author for licensing information.

## Credits

Created and maintained by [Abhishek Sharma](https://ssntpl.com).
