<?php

const EC_ACCOUNTABILITY_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;
const EC_ACCOUNTABILITY_ATTACHMENT_MAGIC = "ECASHACC1";
const EC_ACCOUNTABILITY_ATTACHMENT_CIPHER = "aes-256-gcm";

function ecAccountabilityAttachmentPrivateRoot(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "private";
}

function ecAccountabilityAttachmentStorageRoot(): string
{
    return ecAccountabilityAttachmentPrivateRoot() . DIRECTORY_SEPARATOR . "accountability_attachments";
}

function ecAccountabilityAttachmentKeyPath(): string
{
    return ecAccountabilityAttachmentPrivateRoot() . DIRECTORY_SEPARATOR . "accountability_attachment.key";
}

function ecAccountabilityAttachmentKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $path = ecAccountabilityAttachmentKeyPath();
    if (!is_file($path)) {
        throw new RuntimeException("Accountability attachment encryption key is not configured.");
    }
    $key = file_get_contents($path);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException("Accountability attachment encryption key is invalid.");
    }
    return $key;
}

function ecAccountabilityAttachmentTypes(): array
{
    return [
        "pdf" => ["application/pdf"],
        "jpg" => ["image/jpeg"],
        "jpeg" => ["image/jpeg"],
        "png" => ["image/png"],
        "txt" => ["text/plain"],
        "csv" => ["text/plain", "text/csv", "application/csv", "application/vnd.ms-excel"],
        "docx" => [
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "application/zip",
        ],
        "xlsx" => [
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "application/zip",
        ],
    ];
}

function ecValidateAccountabilityAttachment(array $file): array
{
    $error = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException("The supporting document could not be uploaded.");
    }
    $size = (int) ($file["size"] ?? 0);
    if ($size < 1 || $size > EC_ACCOUNTABILITY_ATTACHMENT_MAX_BYTES) {
        throw new DomainException("Supporting documents must be between 1 byte and 10 MB.");
    }
    $temporaryPath = (string) ($file["tmp_name"] ?? "");
    if (!is_uploaded_file($temporaryPath)) {
        throw new DomainException("The supporting document upload is invalid.");
    }
    $originalName = trim(basename((string) ($file["name"] ?? "document")));
    $originalName = preg_replace('/[\x00-\x1F\x7F]+/u', "", $originalName) ?: "document";
    if (mb_strlen($originalName) > 180) {
        throw new DomainException("The supporting document filename is too long.");
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ecAccountabilityAttachmentTypes();
    if (!isset($allowed[$extension])) {
        throw new DomainException("Allowed documents: PDF, JPG, PNG, TXT, CSV, DOCX and XLSX.");
    }
    $mimeType = (string) (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    if (!in_array($mimeType, $allowed[$extension], true)) {
        throw new DomainException("The supporting document content does not match its file extension.");
    }
    return [$temporaryPath, $originalName, $mimeType, $size];
}

function ecAssertAccountabilityExpenseAccess(int $expenseId): array
{
    $expense = ecQuery(
        "SELECT e.id,e.office_id,d.name office_name FROM accountability_expenses e JOIN districts d ON d.id=e.office_id WHERE e.id=?",
        "i",
        [$expenseId],
    )
        ->get_result()
        ->fetch_assoc();
    if (!$expense) {
        throw new DomainException("Expenditure record not found.");
    }
    ecAssertAccountabilityOffice((int) $expense["office_id"]);
    return $expense;
}

function ecEncryptAccountabilityAttachment(string $sourcePath, string $destinationPath): array
{
    $plainText = file_get_contents($sourcePath);
    if (!is_string($plainText)) {
        throw new RuntimeException("Unable to read the supporting document.");
    }
    $iv = random_bytes(12);
    $tag = "";
    $cipherText = openssl_encrypt(
        $plainText,
        EC_ACCOUNTABILITY_ATTACHMENT_CIPHER,
        ecAccountabilityAttachmentKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        EC_ACCOUNTABILITY_ATTACHMENT_MAGIC,
        16,
    );
    if (!is_string($cipherText) || strlen($tag) !== 16) {
        throw new RuntimeException("Unable to encrypt the supporting document.");
    }
    $payload = EC_ACCOUNTABILITY_ATTACHMENT_MAGIC . $iv . $tag . $cipherText;
    if (file_put_contents($destinationPath, $payload, LOCK_EX) !== strlen($payload)) {
        throw new RuntimeException("Unable to store the encrypted supporting document.");
    }
    return [strlen($payload), hash("sha256", $plainText)];
}

function ecDecryptAccountabilityAttachment(string $path): string
{
    $payload = file_get_contents($path);
    $magicLength = strlen(EC_ACCOUNTABILITY_ATTACHMENT_MAGIC);
    if (!is_string($payload) || strlen($payload) < $magicLength + 28) {
        throw new RuntimeException("The encrypted supporting document is damaged.");
    }
    if (!hash_equals(EC_ACCOUNTABILITY_ATTACHMENT_MAGIC, substr($payload, 0, $magicLength))) {
        throw new RuntimeException("The supporting document format is invalid.");
    }
    $plainText = openssl_decrypt(
        substr($payload, $magicLength + 28),
        EC_ACCOUNTABILITY_ATTACHMENT_CIPHER,
        ecAccountabilityAttachmentKey(),
        OPENSSL_RAW_DATA,
        substr($payload, $magicLength, 12),
        substr($payload, $magicLength + 12, 16),
        EC_ACCOUNTABILITY_ATTACHMENT_MAGIC,
    );
    if (!is_string($plainText)) {
        throw new RuntimeException("The supporting document could not be decrypted or has been modified.");
    }
    return $plainText;
}

function ecStoreAccountabilityAttachment(int $expenseId, array $file, int $userId): int
{
    $expense = ecAssertAccountabilityExpenseAccess($expenseId);
    ecAssertAccountabilitySubmissionOffice((int) $expense["office_id"]);
    [$sourcePath, $originalName, $mimeType, $size] = ecValidateAccountabilityAttachment($file);
    $storageRoot = ecAccountabilityAttachmentStorageRoot();
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0700, true) && !is_dir($storageRoot)) {
        throw new RuntimeException("Supporting-document storage is unavailable.");
    }
    $storedName = bin2hex(random_bytes(24)) . ".enc";
    $destinationPath = $storageRoot . DIRECTORY_SEPARATOR . $storedName;
    try {
        [$encryptedSize, $plainHash] = ecEncryptAccountabilityAttachment($sourcePath, $destinationPath);
        ecQuery(
            "INSERT INTO accountability_attachments(expense_id,uploaded_by,stored_name,original_name,mime_type,size_bytes,encrypted_size,sha256) VALUES(?,?,?,?,?,?,?,?)",
            "iisssiis",
            [$expenseId, $userId, $storedName, $originalName, $mimeType, $size, $encryptedSize, $plainHash],
        );
        return getDBConnection()->insert_id;
    } catch (Throwable $exception) {
        if (is_file($destinationPath)) {
            unlink($destinationPath);
        }
        throw $exception;
    }
}

function ecAccountabilityAttachmentsByExpense(array $expenseIds): array
{
    $expenseIds = array_values(array_unique(array_map("intval", $expenseIds)));
    if (!$expenseIds) {
        return [];
    }
    $placeholders = implode(",", array_fill(0, count($expenseIds), "?"));
    $rows = ecQuery(
        "SELECT a.id,a.expense_id,a.original_name,a.mime_type,a.size_bytes,a.created_at,u.full_name uploaded_by_name FROM accountability_attachments a LEFT JOIN users u ON u.id=a.uploaded_by WHERE a.expense_id IN ({$placeholders}) ORDER BY a.id",
        str_repeat("i", count($expenseIds)),
        $expenseIds,
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row["expense_id"]][] = $row;
    }
    return $result;
}

function ecAccountabilityAttachmentForDownload(int $attachmentId): ?array
{
    $row = ecQuery(
        "SELECT a.*,e.office_id FROM accountability_attachments a JOIN accountability_expenses e ON e.id=a.expense_id WHERE a.id=?",
        "i",
        [$attachmentId],
    )
        ->get_result()
        ->fetch_assoc();
    if (!$row) {
        return null;
    }
    ecAssertAccountabilityOffice((int) $row["office_id"]);
    return $row;
}
