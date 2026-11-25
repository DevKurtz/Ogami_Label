# Ogami Label Studio prototype

## Run it

1. Install the local image-generation dependency:

   ```powershell
   python -m pip install -r requirements.txt
   ```

2. Start the app from this folder:

   ```powershell
   python serve.py
   ```

3. Open http://localhost:8000.

Stop it with Ctrl+C.

## Workbook format

The importer reads the first worksheet and matches the fixed Ogami headers automatically. It does not ask users to map columns. It rejects a workbook if required headers or code values are missing. It supports `.xlsx`, `.xlsm`, and `.csv`; legacy `.xls` files are not supported in this prototype.

QR payload and Code 128 barcodes use the corresponding row values from `QR CODE`, `SERIAL CODE`, `PART NUMBER CODE`, `QUANTITY CODE`, and `SUPPLIER CODE`. The values are not reconstructed from other columns. The label preview and printable SVG follow the supplied reference layout.

## Prototype storage

Upload records are saved in the browser's IndexedDB for that browser profile. The app is currently a local prototype: it is not yet the requested Laravel/Vue/Tailwind application and it does not yet connect to MySQL/Supabase. Do not use this browser-only storage as the production record system.
