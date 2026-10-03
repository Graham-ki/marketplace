<?php
require_once __DIR__ . '/../app/core/Session.php';
Session::start();
Session::logout();
header('Location: /');
exit;