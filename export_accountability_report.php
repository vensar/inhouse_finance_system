<?php

require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/accountability_service.php';
ecRequireAccountability();

[$currentFy] = ecFiscalPeriod(date('Y-m-d'));
$fy = (string)($_GET['financial_year'] ?? $currentFy);
$quarter = max(0, min(4, (int)($_GET['quarter'] ?? 0)));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$region = trim((string)($_GET['region'] ?? ''));
$officeId = max(0, (int)($_GET['office_id'] ?? 0));
$rows = ($from && $to)
    ? ecAccountabilitySummaryByDate($from, $to, $region ?: null, $officeId ?: null)
    : ecAccountabilitySummary($fy, $quarter, $region ?: null, $officeId ?: null);

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Excel export is unavailable. Enable the PHP zip extension.');
}
$template = __DIR__.'/assets/templates/accountability_consumption_template.xlsx';
if (!is_file($template)) {
    http_response_code(500);
    exit('The Excel template is unavailable.');
}
$temp = tempnam(sys_get_temp_dir(), 'ec-accountability-');
copy($template, $temp);
$zip = new ZipArchive();
if ($zip->open($temp) !== true) {
    @unlink($temp);
    http_response_code(500);
    exit('Could not create the Excel report.');
}
function ecXmlText($value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
$headers = ['Region','Office','Office Type','EFTs Sent (UGX)','Expenditure (UGX)','Unutilised (UGX)','Unaccounted (UGX)','Accounted (UGX)'];
$xmlRows = ['<row r="1">'];
foreach ($headers as $i => $header) {
    $col = chr(65 + $i);
    $xmlRows[0] .= '<c r="'.$col.'1" t="inlineStr" s="1"><is><t>'.ecXmlText($header).'</t></is></c>';
}
$xmlRows[0] .= '</row>';
$line = 2;
foreach ($rows as $row) {
    $cells = [
        ['s', $row['region']], ['s', $row['name']], ['s', $row['office_type']],
        ['n', $row['allocated']], ['n', $row['spent']], ['n', $row['unutilised']],
        ['n', $row['unaccounted']], ['n', $row['accounted']],
    ];
    $entry = '<row r="'.$line.'">';
    foreach ($cells as $i => [$type, $value]) {
        $ref = chr(65 + $i).$line;
        $entry .= $type === 'n'
            ? '<c r="'.$ref.'" s="2"><v>'.(float)$value.'</v></c>'
            : '<c r="'.$ref.'" t="inlineStr"><is><t>'.ecXmlText($value).'</t></is></c>';
    }
    $xmlRows[] = $entry.'</row>';
    $line++;
}
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    .'<dimension ref="A1:H'.max(1, $line - 1).'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    .'<cols><col min="1" max="1" width="18" customWidth="1"/><col min="2" max="3" width="24" customWidth="1"/><col min="4" max="8" width="21" customWidth="1"/></cols>'
    .'<sheetData>'.implode('', $xmlRows).'</sheetData><autoFilter ref="A1:H'.max(1, $line - 1).'"/></worksheet>';
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$workbook = $zip->getFromName('xl/workbook.xml');
if ($workbook !== false) {
    $workbook = preg_replace('/name="[^"]*"/', 'name="Accountability Report"', $workbook, 1);
    $zip->addFromString('xl/workbook.xml', $workbook);
}
$zip->close();
$period = ($from && $to) ? $from.'_to_'.$to : preg_replace('/[^A-Za-z0-9-]/', '-', $fy).($quarter ? '_Q'.$quarter : '');
session_write_close();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="accountability_report_'.$period.'.xlsx"');
header('Content-Length: '.filesize($temp));
header('Cache-Control: private, no-store');
readfile($temp);
@unlink($temp);
exit;
