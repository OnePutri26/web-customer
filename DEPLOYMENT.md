# YesNet deployment and Xendit payments

The application requires PHP 8.3 with `mysqli`, `curl`, and a reachable MariaDB
database. Payment credentials must be configured as server-side environment
variables; do not put them in PHP source, browser code, or source control.

## Database migration

Back up the database, inspect the existing `pembayaran` columns, then run
[`database/migrasi_xendit.sql`](./database/migrasi_xendit.sql) once. It adds
Xendit identifiers/provider metadata and widens status fields while retaining
old payment rows and Midtrans identifiers. The migration marks rows as
`midtrans` only when both old Midtrans identifier columns exist and a row has
an order ID or Snap token; other historical rows are marked `legacy`.

## Server environment

Configure these values for the PHP-FPM pool used by the site. Keep the pool
configuration readable only by the service administrator and restart PHP-FPM
after changes:

```ini
env[YESNET_DB_HOST] = 127.0.0.1
env[YESNET_DB_PORT] = 3306
env[YESNET_DB_NAME] = wifi_management
env[YESNET_DB_USER] = wifi_app
env[YESNET_DB_PASSWORD] = replace-with-a-secret
env[YESNET_APP_URL] = https://your-public-domain.example
env[XENDIT_SECRET_KEY] = paste-the-secret-key-from-your-Xendit-dashboard
env[XENDIT_WEBHOOK_TOKEN] = paste-the-callback-verification-token-from-Xendit
```

Use the Xendit **development/test Secret API Key** for test mode and the
production Secret API Key only for live mode. Xendit Invoice API uses the same
API host for both; the key selects the account environment. A Public Key is not
required for hosted invoices. See the [Xendit documentation](https://docs.xendit.co/)
and [webhook verification guidance](https://docs.xendit.co/docs/handling-webhooks).
`config/xendit.php` reads the Secret API Key and
webhook token from environment variables only; never send either to a browser.
`YESNET_APP_URL` must be the canonical public HTTPS origin with no trailing
slash. Do not configure a fake or sample key.

For Windows/XAMPP, define the same values as Windows system environment
variables or in a protected Apache virtual-host configuration, then restart
Apache so PHP can read them with `getenv()`. Do not put secret values in
`.htaccess`, a tracked PHP file, or browser JavaScript. A local-only XAMPP URL
cannot receive Xendit webhooks; use a public HTTPS test/staging host.

The Xendit secret that had previously been present in the repository must be
revoked/rotated in the Xendit Dashboard before deployment. Do not reuse it.

## Configure the Xendit webhook

In the Xendit Dashboard, open **Settings → Developers → Webhooks/Callbacks**,
select the Invoice event notifications, and set the callback URL to:

```text
https://your-public-domain.example/customer/payment_webhook.php
```

Copy the webhook verification token shown by Xendit into
`XENDIT_WEBHOOK_TOKEN`. The endpoint checks `x-callback-token`, retrieves the
invoice from Xendit using the server-side Secret API Key, and verifies invoice
ID, external ID, and amount before updating payment/tagihan status. Do not
disable webhook-token validation.

## Test mode checklist

1. Configure a test/development Secret API Key, webhook token, database, and
   public HTTPS `YESNET_APP_URL`.
2. Apply the database migration and configure the Invoice webhook URL above in
   the Xendit test/development dashboard.
3. Create an unpaid customer tagihan and open
   `pembayaran.php?tagihan=<tagihan-id>`. Submit **Buat invoice Xendit** and
   verify that the browser is redirected to an Xendit hosted invoice.
4. Complete a successful test payment. Confirm Xendit sends a webhook and the
   local `pembayaran.status` becomes `success` and `tagihan.status` becomes
   `paid`. Re-deliver the same webhook and confirm there is no duplicate
   payment/status side effect.
5. Test a still-unpaid invoice (`PENDING`), wait for or trigger expiry
   (`EXPIRED`), and test a failed/voided invoice where that status is available
   in the Xendit test dashboard. Confirm these never mark a tagihan paid.
6. Check the result page, customer billing history, order status, and admin
   overview. Test an invalid callback token and confirm it receives HTTP 403.
7. Check application logs if Xendit or the database is unavailable; the
   checkout must not report success unless the server verifies a paid invoice.

Never mark an invoice paid by editing a browser request or by visiting the
result URL. The result URL refreshes status from Xendit and the webhook is the
authoritative asynchronous update.

## Apply and verify deployment

Back up configuration before deployment, then lint the changed PHP files and
check the public route:

```bash
php -l config/xendit.php
php -l pembayaran.php
php -l payment_webhook.php
curl -I https://your-public-domain.example/
```

The HTTPS virtual host, certificate, PHP-FPM environment, and MariaDB
connectivity must be verified on the deployment server; they cannot be
validated from this repository workspace.
