# signlab_qr
One PHP endpoint that stores QR scan results from the DRS in the database.

## What it does
- `qrResultReceiver.php` takes a POST from `qr/qr_scanner_service.py` on the DRS (see [signlab_drs-pipeline](https://github.com/Amsterdam-Humanities-Labs/signlab_drs-pipeline)).
- Body: JSON or form fields, for example `{"camera":"L","filename":"L20260824_6288.wav","glosId":"9999","type":"test","time":"09:31:36","date":"2026-08-24"}`.
- It finds the `matched_transcriptions` row with the same `time` and `date`. No row: it inserts one. A row: it updates the transcription columns and the camera file column (`l_file`, `m_file`, ...).
- It refuses a request without a transcription, date or time (HTTP 400) and does not touch the database.
- It logs every raw request to `qrResultReceiver.log` next to the script. Answers are JSON.

## Where it runs
The core server: `/web/qr`, URL `https://signcollect.nl/qr/qrResultReceiver.php`.
The server runs its own copy. This repo is a backup of that code and is not deployed yet.

## Status
Production (copy of the live code, 2026-09-22). See [signlab_signcollect-stack#35](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack/issues/35).

## How to run or deploy
Not deployed from here. To test by hand:
```
curl -X POST https://signcollect.nl/qr/qrResultReceiver.php \
  -H 'Content-Type: application/json' \
  -d '{"camera":"L","filename":"L20260824_6288.wav","glosId":"9999","type":"test","time":"09:31:36","date":"2026-08-24"}'
```
This writes to the production database. Use a test host where you can.

## Configuration
- `../mysql_config.php` (not in git) sets `$servername`, `$username`, `$password`, `$database`.
- The web server user must be able to write `qrResultReceiver.log`.

## Dependencies
- MySQL database `admin_gebarenoverleg`, table `matched_transcriptions`.
- Caller: the DRS QR scanner, and `tools/qr_backfill.py` and `tools/replay_qr_results.py` in [signlab_drs-pipeline](https://github.com/Amsterdam-Humanities-Labs/signlab_drs-pipeline).
- [signlab_pythonCron](https://github.com/Amsterdam-Humanities-Labs/signlab_pythonCron) runs `/web/qr/qrConvert.py` hourly (service `qRconvert`). That script is not in this repo.
