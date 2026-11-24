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

## Railway deployment

The repository includes a PHP 8.2 Docker deployment, with GD, MySQL PDO, Node/Vite build tooling, Python 3, and PyMuPDF installed.

Before deploying:

1. Create a Railway MySQL database and set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in the application service variables. Railway's MySQL plugin variables are commonly used as references; alternatively set `DB_URL` to the complete MySQL connection URL.
2. Set `APP_KEY` to a Laravel application key (`php artisan key:generate --show` locally), `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-railway-domain`, `SESSION_DRIVER=database`, `CACHE_DRIVER=database`, and `QUEUE_CONNECTION=database`.
3. Set `FILESYSTEM_DISK=local` and attach a persistent Railway volume at `/app/storage/app` if contract uploads and originals must survive redeployments.
4. Deploy the repository with the included `Dockerfile`; the start command runs migrations, optimizes Laravel, and serves on Railway's `$PORT`.

## Editing uploaded contracts

PDF conversion runs on the application server and requires Python 3 with PyMuPDF installed (`python -m pip install -r requirements-pdf.txt`, or `python3 -m pip install -r requirements-pdf.txt` on Linux). Windows checks standard per-user Python installs before falling back to `python`; Linux uses `python3`. Set `PDF_LAYOUT_PYTHON` in the server environment when Python is installed elsewhere or the web process runs under a different account. The converter detects common table and page geometry to make the extracted content editable, but the result is an approximation; the original PDF remains available for exact viewing and download.

Converted contracts preview as HTML and do not need PHP GD for browser preview. Extracted PDF headers are stored as JPEG. If GD is unavailable, PDF export converts embedded PNG images—including those in previously saved conversions—to JPEG using the configured Python/PyMuPDF runtime. Large PDF conversions can take several minutes, so configure PHP and any web-server/proxy request timeouts to allow up to 300 seconds.
