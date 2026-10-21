<?php

require_once __DIR__.'/config.php';
require_once __DIR__.'/accountability_service.php';
ecRequireAccountability();
$type = (string)($_GET['type'] ?? 'consumption');
$files = ['consumption' => 'accountability_consumption_template.xlsx','eft' => 'eft_upload_template.xlsx'];
if (!isset($files[$type]) || ($type === 'eft' && !ecIsAccountabilityAdmin())) {
    http_response_code(404);
    exit('Template unavailable.');
}$path = __DIR__.'/assets/templates/'.$files[$type];
if (!is_file($path)) {
    http_response_code(404);
    exit('Template unavailable.');
}session_write_close();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$files[$type].'"');
header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
exit();
