<?php

// logout.php
require_once "config.php";

// Log security event before destroying session
if (isset($_SESSION["user_id"])) {
    logSecurityEvent("User logged out", $_SESSION["user_id"]);
}

// Unset all session variables
$_SESSION = [];

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"],
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login");
exit();
