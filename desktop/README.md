# Ogami Label Studio desktop build

The desktop shell starts the existing Laravel application on a loopback-only port, points it at a per-user SQLite file, and enables the local workspace account. The existing workbook importer, SVG barcode/QR renderer, Vue designer, and two-label A4 PDF exporter remain shared with the web app.

## Windows release prerequisites

The end-user installer includes Electron and a trimmed PHP 8.2 runtime with `pdo_sqlite`, `sqlite3`, `mbstring`, `zip`, `xml`, `dom`, `fileinfo`, and `openssl`, plus the Laravel `vendor` tree and built Vite assets. The runtime goes in `desktop/runtime/php/`. Do not ship `.env`, its secrets, a developer database, logs, or user data.

From a Windows release machine, point `OGAMI_PHP_RUNTIME` to a PHP 8.2 distribution folder that includes the required extensions, then prepare it and build:

```powershell
npm run desktop:runtime
npm install
npm run desktop:dist
```

The installer output is written to `release/`. The app stores its database and logs in the current Windows user's application-data directory. Workbook records remain on that computer unless the user exports/backups the database.

The web header links to the latest GitHub Release installer by default. To publish an update, push a tag named `desktop-vX.Y.Z`; the included GitHub Actions workflow builds the Windows installer and attaches it to the release. Set `DESKTOP_INSTALLER_URL` only if you choose a different release host.

The installer is Windows-only. Keep the PHP distribution's license and notices with the release. `npm install` resolves the Electron packaging dependencies listed in the project manifest.

## Generator accuracy

Desktop requests use the same `WorkbookImporter` and `LabelController` as the web app. The label SVG is rendered by the existing PHP QR Code and Code 128/Code 39 libraries. The existing client-side two-label A4 PDF builder is used unchanged. No separate cloud image or PDF service is required.
