# signlab_qr
QR result receiver endpoint from the core server (/web/qr).

## What it does
Code copied read-only from `/web/qr` on the core server (2026-09-22), so it is no longer only on one machine. Data files, logs and caches were left out.

## Where it runs
The core server, `/web/qr`. The server still runs its own copy; this repo is not deployed yet.

## Status
Production (copy). See [signlab_signcollect-stack#35](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack/issues/35).

## How to run or deploy
Not deployed from here yet. Scripts run on the core server, some from pythonCron.

## Configuration
Credentials were removed from the code. Scripts read them from environment variables: `DB_PASS`, `SIGNBANK_API_KEY`, `SIGNBANK_CSRFTOKEN`, `SIGNBANK_SESSIONID`, `OPENROUTER_API_KEY`. PHP files include `../mysql_config.php` (not in git).

## Dependencies
MySQL database `admin_gebarenoverleg`; Signbank.
