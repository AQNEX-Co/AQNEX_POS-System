<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['SESS_MEMBER_ID']) || empty(trim($_SESSION['SESS_MEMBER_ID']))) {
    header("Location: auth/login.php");
    exit();
} else {
    header("Location: home.php");
    exit();
}
?>
