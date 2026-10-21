<?php

require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/advance_service.php';
ecRequireAccountability();
$type = (string)($_GET['type'] ?? 'payments');
if ($type === 'mapping') {
    session_write_close();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="advance_beneficiary_office_mapping.csv"');
    echo "BeneficiaryAccount,BeneficiaryName,Office\r\n256700000001,Sample Beneficiary,AGAGO PC\r\n";
    exit;
}
$template = __DIR__.'/assets/templates/accountability_consumption_template.xlsx';
if (!is_file($template) || !class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Excel template support is unavailable.');
}$temp = tempnam(sys_get_temp_dir(), 'ec-advance-');
copy($template, $temp);
$zip = new ZipArchive();
if ($zip->open($temp) !== true) {
    @unlink($temp);
    http_response_code(500);
    exit('Could not create the advance template.');
}$headers = ['Beneficiary Account','Beneficiary Name','Advance Amount','Office','Reference Number','Description'];
$samples = [['256700000001','Sample Beneficiary','225000','AGAGO','ADV-2026-001','Travel or individual advance purpose']];
$xmlRows = [];
$all = array_merge([$headers], $samples);
foreach ($all as $i => $row) {
    $n = $i + 1;
    $xml = '<row r="'.$n.'">';
    foreach ($row as $j => $value) {
        $col = chr(65 + $j);
        $xml .= '<c r="'.$col.$n.'" t="inlineStr"'.($n === 1 ? ' s="1"' : '').'><is><t>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
    }$xmlRows[] = $xml.'</row>';
}$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:F2"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="2" width="24" customWidth="1"/><col min="3" max="3" width="18" customWidth="1"/><col min="4" max="6" width="28" customWidth="1"/></cols><sheetData>'.implode('', $xmlRows).'</sheetData><autoFilter ref="A1:F2"/></worksheet>';
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$workbook = $zip->getFromName('xl/workbook.xml');
if ($workbook !== false) {
    $zip->addFromString('xl/workbook.xml', preg_replace('/name="[^"]*"/', 'name="Advance Payments"', $workbook, 1));
}$zip->close();
session_write_close();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="advance_payment_template.xlsx"');
header('Content-Length: '.filesize($temp));
header('Cache-Control: private, no-store');
readfile($temp);
@unlink($temp);
exit;
