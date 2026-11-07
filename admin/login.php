<?php
require_once __DIR__ . '/../includes/auth.php';
redirect(auth_login_url(true));
