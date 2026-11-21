# SmartBarangay

SmartBarangay is a web-based barangay document reservation system with QR code
and IoT-based verification. Residents reserve barangay document services online,
upload requirements, pick an appointment schedule and track their requests.
Admins review and process requests, prepare personalized certificates, record
on-site payments and verify QR tickets at the barangay office, either with a
browser camera or with an ESP32-CAM scanner.

- Client: Barangay Calayo, Nasugbu, Batangas (single barangay)
- Course context: Web Systems and Technologies, with an IoT component
- Type: academic project

## Roles

There are exactly two roles. There is no Staff role and no public Admin registration.

- **Resident**: registers publicly, verifies email, and must be active (and residency-approved where required) to use protected pages.
- **Admin**: created only with `php artisan smartbarangay:create-admin`; must verify email like any other account.

## Implemented modules

Resident

- Registration, login, email verification, password reset, pre-verification email change
- Dashboard, profile (with profile picture and demographic details), personal settings
- Reservation wizard: service, requirements and uploads, document information, schedule, confirmation
- My Reservations, request status tracking, cancellation and cancellation requests
- QR tickets for approved requests
- Personalized document preview and generated certificate download
- In-app notifications

Admin

- Dashboard and in-app notifications
- Services, service requirements, fees and official master PDFs
- Document configuration: custom fields and a visual PDF layout designer
- Schedules with slot capacity
- Reservation processing: status changes, attachments, missed appointments, cancellation review
- Certificate preview, final generation, download and printing
- On-site payment recording (payment required before Completed for paid services)
- QR verification and persisted check-in
- Reservation logs
- Reports with CSV export: reservations, collections, documents, check-ins
- Resident management: details, residency review, activate/deactivate
- IoT scanner pairing and IoT dashboard

IoT

- ESP32-CAM (AI Thinker, OV2640) firmware with on-device QR decoding (quirc)
- Authenticated HTTPS device API under `/api/iot/v1` (pair, probe, status, preview, scans)
- Laravel stays the only authority for QR validity, check-in and reservation status; the device never accesses the database

## Tech stack

- PHP 8.2+, Laravel 12, MySQL/MariaDB (XAMPP for local development)
- Blade, Bootstrap 5, custom CSS, vanilla JavaScript, Vite 7
- PDF: setasign/fpdi, setasign/tfpdf, smalot/pdfparser, pdfjs-dist
- QR: bacon/bacon-qr-code (generation), @zxing/browser (browser scanning)
- Testing: PHPUnit 11 (SQLite in memory), Node built-in test runner, Laravel Pint
- Firmware: PlatformIO (ESP32-CAM)

## Local setup (XAMPP)

Requirements: XAMPP (PHP 8.2+, MySQL), Composer, Node.js and npm.

1. Clone the repository into `C:\xampp\htdocs\smartbarangay`.
2. Install PHP dependencies:

   ```
   composer install
   ```

3. Create the environment file and application key:

   ```
   copy .env.example .env
   php artisan key:generate
   ```

4. Create an empty local development database in MySQL (for example `smartbarangay`),
   then set `DB_CONNECTION=mysql`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env`.
   Use your own local values; never commit `.env`.
5. Run the migrations on that local development database:

   ```
   php artisan migrate
   ```

6. Install and build the frontend assets:

   ```
   npm install
   npm run build
   ```

7. Create the first Admin account and start the app:

   ```
   php artisan smartbarangay:create-admin
   php artisan serve
   ```

Mail defaults to the `log` driver. Configure SMTP only in your local `.env`.
IoT device credentials and Wi-Fi settings are kept in a private folder outside
`htdocs`; see `firmware/README.md`.

## Running tests

PHP tests run on SQLite in memory (see `phpunit.xml`) and do not touch MySQL.

```
php artisan test
node --test tests/Js/
npm run build
```

## Project structure

```
app/
  Console/Commands/   Artisan commands (create-admin, cleanup, IoT device tools)
  Enums/              Reservation, payment, role and residency enums
  Http/Controllers/   Admin, Resident, Auth and Api (IoT) controllers
  Http/Middleware/    Role, active account, residency and IoT device checks
  Models/             Eloquent models
  Services/           Domain logic (QR, documents, payments, check-in, reports, IoT)
database/migrations/  Schema migrations
resources/views/      Blade templates
resources/css, js/    Frontend sources built by Vite
routes/               web.php, api.php, console.php
tests/Feature, Unit/  PHPUnit tests
tests/Js/             JavaScript tests
firmware/             ESP32-CAM PlatformIO project
docs/                 Documentation index and archived module notes
```
