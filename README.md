# Ogami Label Studio

Laravel 12, Vue 3, Tailwind CSS 4, and Vite application for Excel label batches. Supabase is the production database provider and uses PostgreSQL; it does not provide a managed MySQL database.

## Local setup

Requirements: PHP 8.2+, Composer, Node.js 20+, and PHP extensions `zip`, `xml`, `mbstring`, and a database driver (`pdo_sqlite` locally; `pdo_pgsql` for Supabase). SVG QR and barcode generation does not require GD.

```powershell
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Force database/database.sqlite
php artisan migrate
npm run build
php artisan serve
```

Open http://127.0.0.1:8000. Web accounts use administrator-provisioned usernames and passwords; there is no public sign-up. After migrating, create a local web account with `php artisan ogami:user "Local Ogami User"`. The password and confirmation are entered without being shown or saved to source files. Do not commit `.env` or share database credentials in chat.

For frontend development with automatic rebuilds, use two terminals: `php artisan serve` and `npm run dev`.

## Supabase production database

1. Create a Supabase project and get its PostgreSQL connection settings from **Connect**. Use the direct connection for a host with IPv6 access or the session pooler connection for supported IPv4 environments. The project and database password are not included in this source tree.
2. Install PHP `pdo_pgsql` on the hosting runtime.
3. Copy `.env.example` to the host's secret environment and fill in `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Keep `DB_CONNECTION=pgsql`, `DB_SSLMODE=require`, `APP_ENV=production`, and `APP_DEBUG=false`.
4. Install dependencies and build assets, then run `php artisan key:generate --force`, `php artisan migrate --force`, and `php artisan ogami:user "<approved username>" --name="<display name>"` on the host. Enter the password at the hidden prompt. If several old Google accounts exist, add `--email=...` for the account whose workbook history should be kept.
5. Serve the Laravel `public/` directory over HTTPS. Configure backups, private durable storage for uploaded workbooks, and log monitoring before production use.

The web app requires an approved username and password; there is no public account creation or Google sign-in. Login attempts are rate-limited, and passwords are stored as hashes. Every workbook belongs to the account that uploaded it; only that account can view its rows and labels or delete individual labels and entire workbook folders. Older workbook rows with no recorded owner remain hidden rather than being exposed to all users. The validated row data and labels are persisted in PostgreSQL; source workbook files are not retained, which avoids relying on Vercel's ephemeral filesystem.

## Workbook columns and code accuracy

The importer reads the first worksheet and matches headers automatically. It preserves Excel's displayed value for formatted numeric cells, including `1,800`, `7.0`, `25.07.17`, and padded serials. Each non-empty row can create a label even when some fields or code values are missing; absent barcode/QR values leave their code image area blank. Re-enter long codes as Excel text to avoid Excel's numeric precision loss.

The QR code is generated from that row's exact `QR CODE` value. Code 128 symbols are generated directly from that row's exact `SERIAL CODE`, `QUANTITY CODE`, `PART NUMBER CODE`, and `SUPPLIER CODE` values. The raw values are displayed alongside the label and embedded in SVG titles for inspection. Each accepted row is stored in the database with its source values; each workbook has its own batch folder.

## Vercel deployment

The repository includes `Dockerfile.vercel` for Vercel's container runtime. Set `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://ogami-label.vercel.app`, `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE=require`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `LOG_CHANNEL=stderr`, and `LARAVEL_STORAGE_PATH=/tmp/ogami-storage` in Vercel's Environment Variables. The container applies Laravel migrations on startup. Provision the approved web account with `vercel env run --environment production --project ogami-label -- php artisan ogami:user "<approved username>" --name="<display name>"`; the command prompts for the password without echoing it. Point the Supabase pooler and Vercel container at nearby regions. The Vercel `.vercel.app` project URL is first-come-first-served; setting project name `ogami-label` requests the desired address but does not reserve it.

The app limits Excel upload files to 4 MB to fit within Vercel request payload limits. Larger workbooks require direct-to-object-storage uploads.

## Production readiness boundary

The Laravel app, migrations, workbook parsing, label generation, public API, and Vue/Tailwind build are implemented. The local build and an Excel-shaped accuracy fixture were verified. Production deployment still requires Vercel account access, a Supabase project with private connection values, and a successful Vercel container build. Before printing labels for business use, validate a real workbook and scan the resulting QR and Code 128 symbols with the intended scanners.

The previous static prototype is preserved in `legacy-prototype/` for reference.
