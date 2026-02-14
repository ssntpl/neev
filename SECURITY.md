# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly.

**DO NOT** open a public GitHub issue for security vulnerabilities.

### How to Report

1. Email your findings to **security@ssntpl.com**
2. Include a detailed description of the vulnerability
3. Provide steps to reproduce the issue
4. Include the impact assessment if possible

### What to Expect

- **Acknowledgment**: We will acknowledge receipt within 48 hours
- **Assessment**: We will assess the vulnerability within 5 business days
- **Resolution**: Critical vulnerabilities will be patched within 7 days
- **Disclosure**: We will coordinate public disclosure after a fix is available

### Scope

The following are in scope:
- Authentication bypass
- Authorization flaws
- Token/session security
- Tenant isolation breaches
- SQL injection, XSS, CSRF
- Cryptographic weaknesses
- Information disclosure

### Out of Scope

- Issues in dependencies (report to the upstream project)
- Issues requiring physical access
- Social engineering attacks
- Denial of service attacks

## Security Best Practices

When using Neev in production:

1. Always use HTTPS
2. Set strong `APP_KEY` values
3. Configure proper CORS headers
4. Use Redis/Memcached for rate limiting in production
5. Regularly update dependencies
6. Enable email verification (`'email_verified' => true`)
7. Configure MFA for sensitive applications
8. Review and restrict OAuth provider list
9. Set appropriate password expiry policies
10. Schedule cleanup commands (`neev:clean-login-attempts`, `neev:clean-passwords`)
