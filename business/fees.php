<?php
require_once __DIR__ . '/../includes/auth.php';
require_login(ROLE_BUSINESS);
redirect(APP_URL . '/business/dashboard.php?view=report');
