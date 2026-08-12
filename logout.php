<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
session_destroy();
header('Location: login.php');
