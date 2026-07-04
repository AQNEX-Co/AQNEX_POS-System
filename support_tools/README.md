# Support Tools

This folder is for local technical support access only.

## Setup
1. Download the latest `adminer.php` from https://www.adminer.org/ and place it inside this folder.
2. Keep the file named exactly `adminer.php`.
3. Use the protected wrapper URL:
   `http://localhost:8181/support_tools/index.php?auth=YOUR_SECRET_TOKEN`

## Security
- The wrapper denies access unless the correct `auth` key is provided.
- Direct browser access to `adminer.php` is blocked by `.htaccess`.
- Update `SUPPORT_KEY` inside `index.php` with a strong secret.
- Do not expose the support key to end users.

## Notes
- The wrapper reads database credentials from `app/Config/config.php` so it stays in sync with the application settings.
- If you change the MariaDB port or credentials, update `app/Config/config.php` accordingly.
