# BIPE Portal V2

A completely new multi-page PHP and MySQL implementation of the BIPE academic portal.

## Standalone guarantee

This root project does not load, include, or execute `BIPE_Portal.html` at runtime.
The old root files were removed, so this folder is now the standalone portal project.

## Project structure

- `app/` shared PHP bootstrap, auth, helpers, layout, and actions
- `config/config.php` central runtime configuration driven by environment variables
- `database/schema.sql` normalized MySQL schema with OTP, audit log, IP logging, mark lock, and mark type tables
- `database/seed_core.sql` academic year, admin, departments, subjects, approved demo faculty, and default mark types
- `database/seed_students.sql` seeded student roster extracted from the legacy BIPE master list
- `database/migration_add_audit_ip.sql` migration for adding IP logging to an existing audit table
- `index.php` root homepage entry point
- `admin/`, `faculty/`, `student/` role-based page folders
- `assets/css/` common CSS plus page-specific CSS
- `assets/js/` common JS plus page-specific JS
- `storage/logs/` application log output
- `storage/cache/` rate-limit and runtime cache files

## Import order

1. Create the database and tables:
   - import `database/schema.sql`
2. Import base data:
   - import `database/seed_core.sql`
3. Import students:
   - import `database/seed_students.sql`

If your database already exists, run:
- `database/migration_add_audit_ip.sql` to add IP logging to existing audit data

## Default credentials

Administrator:
- Username: `bipe`
- Password: `Bipe@4455`

Seeded approved faculty:
- Password: `Teach@1234`
- Example faculty IDs: `rk.cse`, `ak.ce`, `sn.ee`, `mk.pe`, `dk.ae`, `pc.de`

Students are seeded as roster records without passwords.
Each student must activate their own account from `student/register.php`.

## Production configuration

Use server environment variables for all secrets and runtime settings.
A starter template is available in `.env.example`.

Recommended variables:

- `BIPE_V2_APP_ENV=production`
- `BIPE_V2_APP_DEBUG=false`
- `BIPE_V2_DB_HOST`
- `BIPE_V2_DB_PORT`
- `BIPE_V2_DB_NAME`
- `BIPE_V2_DB_USER`
- `BIPE_V2_DB_PASS`
- `BIPE_V2_SESSION_COOKIE_SECURE=true`
- `BIPE_V2_CSRF_ENABLED=true`
- `BIPE_V2_MAIL_ENABLED=true`
- `BIPE_V2_MAIL_FROM`
- `BIPE_V2_MAIL_FROM_NAME`
- `BIPE_V2_SHOW_SEED_HINTS=false`
- `BIPE_V2_EXPOSE_OTP_IN_FLASH=false`

## Run locally

Open CMD or PowerShell in the project root and run:

```powershell
php -S localhost:8000
```

Then open:

- `http://localhost:8000/`

## Production hardening now included

- CSRF protection for POST requests
- Secure session settings and idle timeout support
- CSP, no-sniff, frame-deny, referrer, and permissions headers
- Server-side rate limiting for login and OTP actions
- Strong password enforcement across registration, reset, and password updates
- Hidden seed/demo hints by default
- OTP flash preview disabled by default
- Safe upload validation for CSV and backup JSON files
- POST-based logout and backup export
- Application log file support in `storage/logs/app.log`
- Audit log entries now store and display user IP addresses in the administrator panel

## Mail delivery

Password reset in production should be backed by server mail delivery.
The project uses PHP `mail()` when `BIPE_V2_MAIL_ENABLED=true`.
If mail delivery is not configured, OTP reset requests are blocked in production instead of exposing OTPs in the UI.

## PHP requirement

This project uses PDO MySQL, so `pdo_mysql` must be enabled in PHP.
