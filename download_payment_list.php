<?php

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/lot_service.php";
requireLogin();
$uploadId = max(0, (int) ($_GET["upload"] ?? 0));
$upload = ecPaymentUpload($uploadId);
if (!$upload || (!isAdmin() && (int) $upload["uploaded_by"] !== (int) $_SESSION["user_id"])) {
    http_response_code(404);
    exit("Payment list not found.");
}
$rows = ecQuery("SELECT phone,cust_name,amount,narration FROM payment_lot_entries WHERE upload_id=? ORDER BY id", "i", [$uploadId])->get_result()->fetch_all(MYSQLI_ASSOC);
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, "UTF-8");
$cells = static function (int $row, array $values, bool $header = false) use ($escape): string {
    $xml = "";
    foreach ($values as $index => $value) {
        $column = chr(65 + $index);
        if ($index === 2 && !$header) {
            $xml .= '<c r="' . $column . $row . '" s="2"><v>' . number_format((float) $value, 2, ".", "") . '</v></c>';
        } else {
            $style = $header ? ' s="1"' : '';
            $xml .= '<c r="' . $column . $row . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $escape($value) . '</t></is></c>';
        }
    }
    return '<row r="' . $row . '">' . $xml . '</row>';
};
$sheetRows = $cells(1, ["Phone", "CustName", "TranAmount", "Narration"], true);
$rowNumber = 2;
foreach ($rows as $row) {
    $sheetRows .= $cells($rowNumber++, [$row["phone"], $row["cust_name"], $row["amount"], $row["narration"]]);
}
$lastRow = max(1, $rowNumber - 1);
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' .
    '<cols><col min="1" max="1" width="18" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="3" width="18" customWidth="1"/><col min="4" max="4" width="42" customWidth="1"/></cols>' .
    '<sheetData>' . $sheetRows . '</sheetData><autoFilter ref="A1:D' . $lastRow . '"/></worksheet>';
$template = __DIR__ . "/assets/templates/payment_lot_template.xlsx";
$temp = tempnam(sys_get_temp_dir(), "ecash-clean-");
if (!$temp || !copy($template, $temp)) {
    throw new RuntimeException("Could not prepare the clean payment list.");
}
$zip = new ZipArchive();
if ($zip->open($temp) !== true || !$zip->addFromString("xl/worksheets/sheet1.xml", $sheet)) {
    @unlink($temp);
    throw new RuntimeException("Could not create the clean payment list.");
}
$zip->close();
$name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $upload["lot_name"] . "_" . $upload["district_name"]);
$name = trim($name, "_") ?: "payment_list";
session_write_close();
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header('Content-Disposition: attachment; filename="' . $name . '_clean.xlsx"');
header("Content-Length: " . filesize($temp));
header("Cache-Control: private, no-store, max-age=0");
readfile($temp);
@unlink($temp);
exit();
