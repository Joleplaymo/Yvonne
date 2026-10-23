<?php
declare(strict_types=1);
require __DIR__ . '/../_prive/admin.php';

admin_session();
$_SESSION = [];
session_destroy();
header('Location: /admin/', true, 303);
