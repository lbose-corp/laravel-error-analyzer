# Changelog

All notable changes to `laravel-error-analyzer` will be documented in this file.

## [Unreleased]

### Fixed
- Resolved `composer install` failure caused by requiring unsupported `illuminate/foundation` for Laravel 11/12

### Changed
- Removed unused dependencies (`illuminate/notifications`, `mockery/mockery`)
- Updated Japanese README optional dependency instructions to match current implementation

### Added
- Initial release
- AI-driven error analysis using Google Gemini
- Automatic GitHub issue creation
- Slack notifications for critical errors
- PII sanitization for stack traces and context
- Daily quota management
- Error deduplication (5-minute window)
- Artisan commands for testing and cleanup
- Comprehensive test suite
- Full documentation

## [1.0.0] - TBD

Initial release.
