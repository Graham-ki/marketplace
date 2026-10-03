<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Session.php';

Session::start();

// If logged in → dashboard
if (Session::has('user_id')) {
    header('Location: /dashboard.php');
    exit;
}

require __DIR__ . '/../app/views/landing.php';