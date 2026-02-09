<?php
require_once __DIR__ . '/../config/session_config.php';
secure_session_start();
secure_session_destroy();
header("Location: login.php?out=1");
exit;
