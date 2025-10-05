User Profile (user_profile)
==========================

Overview
--------

The User Profile plugin adds a configurable, printable user profile card, plus tools for pedagogical/administrative tracking, teacher assignment, host-company management and monthly evaluations. It integrates with Chamilo’s ticketing to streamline reminders and follow‑up.

Key Features
------------

- Custom fields by category
  - Create text or date fields and group them into categories.
  - Drag‑and‑drop ordering, bulk editing, and cascading deletion of a category (removes its fields and values).
  - Per‑field “Show in tracking” option (`include_tracking`) to decide whether a field appears on the pedagogical vs. administrative tracking views.
  - Fields are injected into user create/edit forms (via hooks) and values are stored per user.

- Pedagogical and administrative tracking
  - Pedagogical tracking (`tracking.php`): lists users with agenda indicators (this/next week), last login (highlighted if too old), time spent last week, average session progress, assigned teachers, host company, and the “tracked” custom fields with a “completed” checkbox (persisted).
  - Administrative tracking (`tracking_untracked.php`): same layout but shows non‑tracked fields instead (`include_tracking = 0`).
  - Quick actions per user:
    - Agenda “Remind” (sends an internal message to the user).
    - “Warn” teachers (creates a ticket per teacher or falls back to internal messages).
    - “Tracking synthesis” (view + PDF export of tickets linked to the user).
    - Open the user profile card.

- Teacher assignment
  - Dedicated screen to assign/unassign one or more teachers to a user (`teachers.php`).
  - Normalized pivot table `plugin_user_profile_user_teacher`; automatic migration from legacy CSV column `plugin_user_profile_teachers.teacher_ids`.

- Company profile and user → company link
  - Per‑portal company profile (`company.php`, `company_list.php`) with trade name, legal name, address and contact fields (tutor, director, other contact).
  - One‑to‑one user → company link table (`plugin_user_profile_user_company`) with trade name shown in tracking pages.
  - Backward compatibility with legacy French column names (e.g. `nom_commercial`, `mail_tuteur`).

- Monthly evaluation
  - Create/edit a monthly evaluation (month/year + comment) by session admin, teacher or platform admin (`monthly_evaluation.php`). Validation is limited to platform/session admins in `admin_monthly_evaluation.php`.
  - On validation: sends an e‑mail to the tutor with a PDF attachment and creates a ticket (if configured) in the learner‑tracking project/category.
  - Web preview and PDF export (`preview_monthly_evaluation.php`, `preview_monthly_evaluation_pdf.php`).

- Quick check
  - `speed_check.php` lists users:
    - with no agenda event next week,
    - with no tracking ticket in the last week (based on configured project/category), or
    - with no validated monthly comment last month;
    with contextual actions (e.g. agenda reminder).

- Exports
  - Profile PDF (`pdf.php`) and Excel (`xls.php`).
  - Time report XLSX (`time_report_xls.php`) from registration date to today.
  - Global ZIP export (`export_zip.php`) bundling: time report (PDF), user profile (PDF), tracking synthesis (PDF) and MyStudents XLS (and, if available, the student_follow_export PDF).

Pages and Access
----------------

- Field/category admin: `plugin/user_profile/admin.php` (platform/session admin). Bulk edit: `plugin/user_profile/fields_edit.php`.
- Teacher assignment: `plugin/user_profile/teachers.php` (platform/session admin).
- Pedagogical tracking: `plugin/user_profile/tracking.php` (platform/session admin).
- Administrative tracking: `plugin/user_profile/tracking_untracked.php` (platform/session admin).
- Quick check: `plugin/user_profile/speed_check.php` (platform/session admin).
- Tracking synthesis: `plugin/user_profile/resume_tracking.php` + PDF `resume_tracking_pdf.php` (standard tracking visibility: admins, HR, teachers, etc.).
- User card: `plugin/user_profile/view.php` (current user; `id` can target another user if allowed).
- Profile exports: `plugin/user_profile/pdf.php`, `plugin/user_profile/xls.php`.
- Time export: `plugin/user_profile/time_report_xls.php`.
- Global ZIP export: `plugin/user_profile/export_zip.php`.
- Company: list/details `plugin/user_profile/company_list.php` (read for teachers; edit for platform/session admin/HR), edit `plugin/user_profile/company.php`.
- Monthly evaluation: edit `plugin/user_profile/monthly_evaluation.php` (platform/session admin/teacher), manage/validate `plugin/user_profile/admin_monthly_evaluation.php` (platform/session admin).
- AJAX API: `plugin/user_profile/ajax.php` (CSRF required, restricted to platform/session admins) for: completed checkbox save, agenda reminder, warn teachers (tickets/messages), save teachers.

Installation
------------

1) Enable the plugin in `configuration.php`:

```
$_configuration['plugin_user_profile_enabled'] = true;
```

2) Create the schema (per portal):
- By enabling the plugin from the Plugins admin UI, or
- By executing `plugin/user_profile/install.php` while logged in as platform admin.

3) Optional: place the plugin in the `pre_footer` region to expose a link to the user card (`view.php`).

Configuration (tickets and options)
-----------------------------------

The settings form (Administration → Plugins → user_profile) saves per‑portal configuration in `plugin_user_profile_configuration`:

- `email` (optional): generic contact e‑mail.
- `id_ticket_communication` and `category1`: project and category used by the “Warn” action (creates one ticket per assigned teacher). If not configured or ticket creation fails, the plugin falls back to internal messages.
- `id_ticket_learner_tracking` and `category2`: project and category for “learner tracking”, used to:
  - color the “Follow‑up” indicator in `tracking.php` (green if a ticket exists for last week), and
  - feed the “Quick check” mode for tracking.

Data Model (per portal)
-----------------------

- `plugin_user_profile_category`: categories (name, order).
- `plugin_user_profile_field`: fields (name, type `text|date`, category, order, `include_tracking`).
- `plugin_user_profile_value`: per‑user values + `checked` (completed) flag.
- `plugin_user_profile_teachers`: legacy CSV storage of teacher IDs per user.
- `plugin_user_profile_user_teacher`: normalized pivot (user ↔ teacher).
- `plugin_user_profile_comment`: free comments (author, date, visibility) per user.
- `plugin_user_profile_entreprise`: company profile (trade/legal name, address, contacts).
- `plugin_user_profile_user_company`: user → company link (one company per user).
- `plugin_user_profile_configuration`: settings described above.
- `relation_student_ticket`: user ↔ ticket links (filtered by `access_url_id`).
- `user_profile_monthly_evaluation`: monthly evaluation (student, author, month, year, comment, validation).

Multi‑URL
---------

All queries filter by `access_url_id`: each portal keeps its own categories, fields, values, companies, links, evaluations and configuration. No data is shared across portals.

Hooks and Lifecycle
-------------------

- `HookCreateUser` / `HookUpdateUser`: persist custom field values from user forms.
- On user deletion: cleanup of values, comments, teacher links, ticket relations and monthly evaluations, plus user→company link.
- `install.php` / `uninstall.php`: idempotent schema creation/removal. Uninstall drops all plugin tables (destructive).
- Automatic migrations: add missing columns (`include_tracking`, `checked`, `author_id`, etc.), create useful indexes, migrate CSV → pivot for teachers, support legacy French columns in the company table.

Security and i18n
-----------------

- All write actions enforce CSRF tokens and require appropriate roles (platform/session admins, teachers where applicable).
- Output is XSS‑escaped; ticket messages are limited to a safe subset when rendered.
- Translations live in `plugin/user_profile/lang/`. Category/field labels are translated when a matching key exists; otherwise the raw text is used.

Quick Links
-----------

- Fields admin: `plugin/user_profile/admin.php`
- Bulk edit: `plugin/user_profile/fields_edit.php`
- Teacher assignment: `plugin/user_profile/teachers.php`
- Pedagogical tracking: `plugin/user_profile/tracking.php`
- Administrative tracking: `plugin/user_profile/tracking_untracked.php`
- Quick check: `plugin/user_profile/speed_check.php`
- User card: `plugin/user_profile/view.php`

