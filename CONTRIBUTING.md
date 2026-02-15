# Contributing to Neev

Thank you for your interest in contributing to Neev! This guide will help you get started.

## Development Setup

1. Fork and clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Verify everything works:
   ```bash
   composer lint
   composer analyse
   composer test
   ```
4. Create a Laravel application for testing:
   ```bash
   composer create-project laravel/laravel test-app
   cd test-app
   composer require ssntpl/neev --prefer-source
   ```

## How to Contribute

### Reporting Bugs

- Use [GitHub Issues](https://github.com/ssntpl/neev/issues) to report bugs
- Include steps to reproduce the issue
- Include your PHP version, Laravel version, and database type
- Check existing issues before creating a new one

### Suggesting Features

- Open a [GitHub Discussion](https://github.com/ssntpl/neev/discussions) for feature requests
- Describe the use case and expected behavior
- Consider backwards compatibility

### Submitting Pull Requests

1. Create a feature branch from `main`
2. Follow the existing code style and conventions
3. Run the quality checks before submitting:
   ```bash
   composer lint    # Code style (Pint / PSR-12)
   composer analyse # Static analysis (PHPStan / Larastan)
   composer test    # Test suite
   ```
4. Write clear commit messages
5. Update documentation if your change affects user-facing behavior
6. Add a changelog entry if applicable
7. Ensure your changes don't break existing functionality

## Code Style

This project uses [Laravel Pint](https://laravel.com/docs/pint) with the PSR-12 preset.

```bash
# Check for style violations
composer lint

# Auto-fix style violations
composer lint-fix
```

## Static Analysis

This project uses [PHPStan](https://phpstan.org/) with [Larastan](https://github.com/larastan/larastan) at level 5.

```bash
composer analyse
```

New code should not introduce new PHPStan errors. If you need to add a baseline entry, explain why in your PR.

## Code Conventions

- Use type hints for parameters and return types
- Use fully qualified class names for facade imports (e.g., `Illuminate\Support\Facades\Hash`)
- Use Laravel conventions for naming (controllers, models, migrations)
- Use `User::model()` and `Team::model()` instead of direct class references

## Documentation

- Update relevant docs in the `docs/` directory when changing behavior
- Use clear, concise language
- Include code examples where helpful

## Security

If you discover a security vulnerability, please follow our [Security Policy](SECURITY.md) instead of opening a public issue.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
