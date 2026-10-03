<?php
require __DIR__ . '/db.php';
$_SESSION = [];
session_destroy();
header('Location:index.php');
exit;