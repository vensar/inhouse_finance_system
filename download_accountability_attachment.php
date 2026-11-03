<?php

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/accountability_service.php";
require_once __DIR__ . "/accountability_attachment_service.php";

ecRequireAccountability();
ecRequirePermission("accountability.district");
try {
    $attachment = ecAccountabilityAttachmentForDownload(max(0, (int) ($_GET["id"] ?? 0)));
} catch (DomainException $exception) {
    http_response_code(403);
    exit("Access denied.");
}
if (!$attachment) {
    http_response_code(404);
    exit("Supporting document not found.");
}
$path = ecAccountabilityAttachmentStorageRoot() . DIRECTORY_SEPARATOR . $attachment["stored_name"];
if (!is_file($path)) {
    http_response_code(404);
    exit("Supporting document file not found.");
}
try {
    $plainText = ecDecryptAccountabilityAttachment($path);
    if (!hash_equals($attachment["sha256"], hash("sha256", $plainText))) {
        throw new RuntimeException("Supporting document integrity check failed.");
    }
} catch (Throwable $exception) {
    error_log("Accountability attachment: " . $exception->getMessage());
    http_response_code(500);
    exit("The supporting document could not be opened.");
}
$downloadName = preg_replace('/[^\pL\pN._ -]+/u', "_", $attachment["original_name"]) ?: "supporting-document";
header("Content-Type: " . $attachment["mime_type"]);
header("Content-Length: " . strlen($plainText));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("X-Content-Type-Options: nosniff");
echo $plainText;
exit;
