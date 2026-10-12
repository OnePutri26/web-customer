# YesNet deployment notes

The application requires PHP 8.3 with `mysqli` and a reachable MariaDB database.
Do not store production database or Midtrans credentials in source control.

## PHP-FPM environment

Configure these variables for the PHP-FPM pool used by the site. Keep the pool
configuration readable only by root and restart PHP-FPM after changing it:

```ini
env[YESNET_DB_HOST] = 127.0.0.1
env[YESNET_DB_PORT] = 3306
env[YESNET_DB_NAME] = wifi_management
env[YESNET_DB_USER] = wifi_app
env[YESNET_DB_PASSWORD] = replace-with-a-secret
env[YESNET_PAYMENT_DEMO] = 0
env[MIDTRANS_SERVER_KEY] = replace-with-a-secret
env[MIDTRANS_CLIENT_KEY] = replace-with-a-secret
env[MIDTRANS_IS_PRODUCTION] = 1
```

Use credentials created specifically for this application and grant only the
database permissions it needs. Rotate any database password that was previously
committed in the repository.

`YESNET_PAYMENT_DEMO` is disabled unless explicitly set to `1`. Demo mode must
only be used in a non-production environment. The public checkout in this
project does not create a live Midtrans transaction; setting demo mode to `0`
therefore prevents simulated payments from being recorded as paid until a live
checkout integration is implemented and tested.

## Apply and verify

Back up the live file before deployment, then lint it and check the public route:

```bash
sudo cp -a /var/www/html/config/database.php \
  "/var/www/html/config/database.php.bak.$(date +%F-%H%M%S)"
sudo install -o root -g www-data -m 0640 /path/to/config/database.php \
  /var/www/html/config/database.php
sudo systemctl restart php8.3-fpm
sudo systemctl reload apache2
php -l /var/www/html/config/database.php
curl -I https://yesnet.my.id/
```

The HTTPS virtual host, certificate, PHP-FPM socket, and MariaDB connectivity
must be verified on the Ubuntu server; they cannot be validated from this
repository workspace.
