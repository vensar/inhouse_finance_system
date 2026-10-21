<?php

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
requireLogin();
header("Content-Type: text/csv; charset=utf-8");
header('Content-Disposition: attachment; filename="payment_template.csv"');
header("Cache-Control: no-store");
$out = fopen("php://output", "w");
fwrite($out, "\xEF\xBB\xBF");
$headers = ["Phone", "CustName", "TranAmount", "Status", "Narration"];
$sample = [
    "256700000000",
    "EXAMPLE RECIPIENT",
    "75000",
    "SUCCESS",
    "Payment for polling officials in AGAGO",
];
if (isset($_GET["district_template"])) {
    $headers[] = "District";
    $sample[] = "AGAGO";
}
fputcsv($out, $headers, ",", '"', "");
fputcsv($out, $sample, ",", '"', "");
fclose($out);
