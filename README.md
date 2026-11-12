# GPCCI HRIS

Laravel 12 starter for employee records, role-based administration, and self-registration.

## Setup

1. Copy `.env.example` to `.env`, set a secure `APP_KEY`, and configure the database connection. For a standard XAMPP setup, the example targets `127.0.0.1:3306`, database `system_hris`, and user `root`. Set `DB_PASSWORD` to your local MySQL password if you configured one.
2. Install PHP and frontend dependencies, then run:

   ```sh
   composer install
   npm install
   php artisan key:generate
   php artisan migrate --seed
   npm run build
   ```

3. Create the first administrator from a trusted terminal:

   ```sh
   php artisan hris:bootstrap-admin
   ```

   The command prompts for the administrator's name, email, and password. It will refuse to run if an active Super Admin already exists.

If you imported the provided HRIS SQL schema into `system_hris`, the migration is designed to keep those tables and add the missing account-name, OTP timestamp, onboarding, and Laravel support tables. It does not drop your imported tables. Back up the database first and check the configured connection with `php artisan about` before running migrations.

## Registration and administration

- Email OTP verification is temporarily disabled. Public registration collects name, email, contact number, and password directly; it does not send email.
- New sign-ups receive only the Employee role and remain pending until HR reviews them.
- HR must confirm the applicant’s identity and email outside the HRIS before approval. Approval marks the email verified, creates or links the employee record, and activates the account.
- Login is available only to active accounts with a verified email. Public users cannot set department, position, salary, employee number, or role.
- The first Super Admin is bootstrapped from the CLI. Administration routes require an active account and per-action permissions.
- The admin dashboard supports onboarding review, user and role administration, departments, and employee records.

Use HTTPS in deployment, configure Laravel's session and cache stores for the production environment, and back up the database before applying migrations.
