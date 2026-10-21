<?php

require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/advance_service.php';
ecRequireAccountability();
$assigned = ecAccountabilityOfficeId();
$type = in_array($_GET['type'] ?? '', ['TRAVEL','INDIVIDUAL'], true) ? $_GET['type'] : '';
$status = in_array($_GET['status'] ?? '', ['ACCOUNTED','UNACCOUNTED'], true) ? $_GET['status'] : '';
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$officeId = $assigned ?? max(0, (int)($_GET['office_id'] ?? 0));
$search = trim((string)($_GET['q'] ?? ''));
$where = [];
$types = '';
$params = [];
if ($assigned !== null || $officeId) {
    $where[] = 'r.office_id=?';
    $types .= 'i';
    $params[] = $officeId;
}if ($type) {
    $where[] = 'r.advance_type=?';
    $types .= 's';
    $params[] = $type;
}if ($status) {
    $where[] = 'r.accountability_status=?';
    $types .= 's';
    $params[] = $status;
}if ($from) {
    $where[] = 'r.advance_date>=?';
    $types .= 's';
    $params[] = $from;
}if ($to) {
    $where[] = 'r.advance_date<=?';
    $types .= 's';
    $params[] = $to;
}if ($search !== '') {
    $like = '%'.$search.'%';
    $where[] = '(r.beneficiary_account LIKE ? OR r.beneficiary_name LIKE ? OR d.name LIKE ? OR r.office_label LIKE ? OR r.reference_number LIKE ?)';
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}$whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';
$rows = ecQuery('SELECT r.*,COALESCE(d.name,r.office_label,"UNMAPPED") office_name,d.region FROM advance_records r LEFT JOIN districts d ON d.id=r.office_id'.$whereSql.' ORDER BY r.advance_type,r.advance_date,r.id', $types, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
$template = __DIR__.'/assets/templates/accountability_consumption_template.xlsx';
if (!is_file($template) || !class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Excel export support is unavailable.');
}$temp = tempnam(sys_get_temp_dir(), 'ec-advance-report-');
copy($template, $temp);
$zip = new ZipArchive();
if ($zip->open($temp) !== true) {
    @unlink($temp);
    http_response_code(500);
    exit('Could not create the report.');
}$headers = ['Category','Advance Date','Financial Year','Quarter','Region','Office','Beneficiary Account','Beneficiary Name','Reference Number','Advance Amount (UGX)','Accounted (UGX)','Unaccounted (UGX)','Status','Review Note'];
$xmlRows = [];
$line = 1;
$write = function ($values, $header = false) use (&$xmlRows, &$line) {
    $xml = '<row r="'.$line.'">';
    foreach ($values as $i => $value) {
        $col = '';
        $n = $i + 1;
        while ($n) {
            $n--;
            $col = chr(65 + $n % 26).$col;
            $n = intdiv($n, 26);
        }$numeric = is_int($value) || is_float($value);
        $xml .= $numeric ? '<c r="'.$col.$line.'"'.($header ? ' s="1"' : ' s="2"').'><v>'.$value.'</v></c>' : '<c r="'.$col.$line.'" t="inlineStr"'.($header ? ' s="1"' : '').'><is><t>'.htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
    }$xmlRows[] = $xml.'</row>';
    $line++;
};
$write($headers, true);
foreach ($rows as $r) {
    $write([$r['advance_type'] === 'TRAVEL' ? 'Travel Advances' : 'Individual Advances',$r['advance_date'],$r['financial_year'],(int)$r['quarter'],$r['region'] ?? '',$r['office_name'],$r['beneficiary_account'],$r['beneficiary_name'],$r['reference_number'],(float)$r['advance_amount'],(float)$r['accounted_amount'],(float)$r['advance_amount'] - (float)$r['accounted_amount'],$r['accountability_status'],$r['review_note']]);
}$last = max(1, $line - 1);
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:N'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="9" width="20" customWidth="1"/><col min="10" max="12" width="22" customWidth="1"/><col min="13" max="14" width="20" customWidth="1"/></cols><sheetData>'.implode('', $xmlRows).'</sheetData><autoFilter ref="A1:N'.$last.'"/></worksheet>';
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$workbook = $zip->getFromName('xl/workbook.xml');
if ($workbook !== false) {
    $zip->addFromString('xl/workbook.xml', preg_replace('/name="[^"]*"/', 'name="Advance Accountability"', $workbook, 1));
}$zip->close();
session_write_close();
$label = $type ? strtolower($type) : 'all';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$label.'_advance_accountability.xlsx"');
header('Content-Length: '.filesize($temp));
header('Cache-Control: private, no-store');
readfile($temp);
@unlink($temp);
exit;
