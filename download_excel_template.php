<?php

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
requireLogin();
$type = (string) ($_GET["type"] ?? "");
$templates = [
    "payment" => ["payment_lot_template.xlsx", "payment_lot_template.xlsx"],
    "telecom" => ["telecom_verification_template.xlsx", "telecom_verification_template.xlsx"],
];
if (!isset($templates[$type])) {
    http_response_code(404);
    exit();
}
[$file, $download] = $templates[$type];
$path = __DIR__ . "/assets/templates/" . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit();
}
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header('Content-Disposition: attachment; filename="' . $download . '"');
header("Content-Length: " . filesize($path));
header("Cache-Control: private, no-store");
readfile($path);
