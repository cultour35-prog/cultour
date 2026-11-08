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

// Production database. Fill these on the server copy of secrets.php.
// On InfinityFree, also set CULTOUR_APP_URL to https://cultour.rf.gd/cultour
putenv('CULTOUR_DB_HOST=sql000.infinityfree.com');
putenv('CULTOUR_DB_NAME=if0_00000000_your_database');
putenv('CULTOUR_DB_USER=if0_00000000');
putenv('CULTOUR_DB_PASS=');

$_ENV['CULTOUR_APP_URL'] = 'http://localhost/cultour';
$_ENV['CULTOUR_MAIL_SMTP_PASS'] = '';
$_ENV['CULTOUR_PAYMENT_WEBHOOK_SECRET'] = 'cultour_gcash_webhook_dev_secret';
$_ENV['CULTOUR_DB_HOST'] = 'sql000.infinityfree.com';
$_ENV['CULTOUR_DB_NAME'] = 'if0_00000000_your_database';
$_ENV['CULTOUR_DB_USER'] = 'if0_00000000';
$_ENV['CULTOUR_DB_PASS'] = '';
