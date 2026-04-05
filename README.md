# BIPE Academic Portal

A role-based academic management portal built with PHP, MySQL, vanilla JavaScript, and modular page-specific assets. The application is designed to support day-to-day institutional workflows for administrators, faculty members, and students through separate interfaces with shared backend services.

## Overview

The portal centralizes common academic operations such as:

- student onboarding and profile management
- faculty approval and faculty account management
- attendance tracking and attendance review
- marks upload, marks review, and report generation
- assignment tracking
- subject management
- academic-year administration
- audit logging
- backup and restore operations
- request, feedback, and issue queue handling

The codebase is organized as a multi-page PHP application with shared bootstrap, authentication, database, helper, and layout layers under `app/`, and role-specific screens under `admin/`, `faculty/`, and `student/`.

## Core Modules

### Administrator

- Overview dashboard with department-level summaries
- Student records and registration support
- Faculty approval, editing, and lifecycle management
- Request queue for approval, feedback, and issue workflows
- Attendance review and holiday/event management
- Marks administration and report card generation
- Subject import and subject register browsing
- Audit log monitoring
- Backup, restore, and settings management

### Faculty

- Attendance entry and attendance records
- Marks entry, CSV upload, and saved marks review
- Assignment tracking
- Faculty reports across departments and semesters
- Student directory access
- Profile management
- Recovery request workflow for faculty account support

### Student

- Account activation from seeded roster data
- Login and profile access
- Attendance view
- Marks view
- Assignment view

## Technology Stack

- PHP 8+
- MySQL with PDO
- Vanilla JavaScript
- Modular CSS by page and role
- Session-based authentication

## Project Structure

- `admin/` administrator pages
- `faculty/` faculty pages
- `student/` student pages
- `app/` shared bootstrap, auth, helpers, layout, and action logic
- `assets/css/` shared and page-level styles
- `assets/js/` shared and page-level scripts
- `assets/images/` static branding assets
- `assets/uploads/` uploaded profile images
- `config/config.php` runtime configuration
- `database/schema.sql` database schema
- `database/seed_core.sql` base academic and portal seed data
- `database/seed_students.sql` student roster seed data
- `storage/logs/` application logs
- `storage/cache/` runtime cache and rate-limit storage

## Key Features

- Role-based navigation for admin, faculty, and student users
- Academic-year aware records and reporting
- Bulk subject import through CSV
- Marks upload through manual entry and CSV workflows
- Assignment submission tracking per class and subject
- Audit log capture for sensitive administrative actions
- Support request queue with approval states
- Backup export and restore support
- Responsive layouts for desktop and mobile use

## Security Highlights

- CSRF protection for POST requests
- Session timeout handling
- Secure response headers including CSP and clickjacking protection
- Rate limiting for sensitive request flows
- Password strength validation
- File upload validation for CSV, JSON, and profile images
- Audit logging for operational visibility

## Getting Started

### Prerequisites

- PHP 8 or newer
- MySQL or a compatible database server
- PHP `pdo_mysql` extension enabled

### Database Setup

Import the database files in the following order:

1. `database/schema.sql`
2. `database/seed_core.sql`
3. `database/seed_students.sql`

If you are upgrading an older installation, review the SQL files in `database/` and apply only the migrations relevant to your existing schema state.

### Configuration

Runtime configuration is environment-driven. Use environment variables for database connectivity, mail delivery, application mode, upload limits, and security settings.

Common configuration groups include:

- application environment and base URL
- database host, port, name, user, and password
- session and CSRF controls
- upload limits
- mail sender configuration
- logging paths

Use `.env.example` as a reference for variable names, but keep real values out of version control and deployment documentation.

### Local Development

Start a local PHP server from the project root:

```powershell
php -S localhost:8000
```

Then open:

```text
http://localhost:8000/
```

## Deployment Notes

- Set all production secrets through environment variables.
- Disable debug mode in production.
- Enable secure session cookies when serving over HTTPS.
- Configure outbound mail before using mail-dependent recovery flows in production.
- Keep `storage/logs/` and `storage/cache/` writable by the application.
- Review backup and restore permissions carefully before exposing the portal to live users.

## Data and Privacy Guidance

- Do not store secrets, passwords, or real credentials in documentation.
- Do not commit production database credentials or mail server credentials.
- Treat seeded/demo data as non-production initialization data only.
- Review uploaded files and backup data handling policies before live deployment.

## Maintenance

Recommended operational checks:

- monitor application logs
- review audit logs regularly
- validate backups periodically
- verify mail delivery configuration
- review session, rate-limit, and upload settings during deployment updates

## License

Add the appropriate project license statement here if the portal is being distributed outside the institution.
