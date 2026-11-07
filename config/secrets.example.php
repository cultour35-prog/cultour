<?php
/**
 * Copy this file to config/secrets.php and fill in local values.
 * secrets.php is gitignored — never commit SMTP passwords or live keys.
 *
 * You can also set the same keys as environment variables instead.
 */
putenv('CULTOUR_APP_URL=http://localhost/cultour');
putenv('CULTOUR_MAIL_SMTP_PASS=');
putenv('CULTOUR_PAYMENT_WEBHOOK_SECRET=cultour_gcash_webhook_dev_secret');

$_ENV['CULTOUR_APP_URL'] = 'http://localhost/cultour';
$_ENV['CULTOUR_MAIL_SMTP_PASS'] = '';
$_ENV['CULTOUR_PAYMENT_WEBHOOK_SECRET'] = 'cultour_gcash_webhook_dev_secret';
