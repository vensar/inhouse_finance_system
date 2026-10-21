<?php

require_once __DIR__ . "/xlsx_service.php";

function ecPaymentLots(?int $districtId = null, ?int $userId = null): array
{
    $scope = $districtId === null
        ? ""
        : " WHERE l.status='ACTIVE' AND (l.allocation_mode='ALL' OR EXISTS (SELECT 1 FROM payment_lot_allocations a WHERE a.lot_id=l.id AND a.user_id=?))";
    $sql =
        "SELECT l.*,u.full_name created_by_name,COUNT(DISTINCT x.id) upload_count," .
        "COALESCE(SUM(x.row_count),0) entry_count,COALESCE(SUM(x.total_amount),0) total_amount " .
        "FROM payment_lots l LEFT JOIN users u ON u.id=l.created_by LEFT JOIN payment_lot_uploads x ON x.lot_id=l.id" .
        ($districtId === null ? "" : " AND x.district_id=?") .
        $scope .
        " GROUP BY l.id ORDER BY l.created_at DESC";
    return ecQuery(
        $sql,
        $districtId === null ? "" : "ii",
        $districtId === null ? [] : [$districtId, $userId],
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
}

function ecPaymentLot(int $lotId): ?array
{
    return ecQuery(
        "SELECT l.*,u.full_name created_by_name FROM payment_lots l LEFT JOIN users u ON u.id=l.created_by WHERE l.id=?",
        "i",
        [$lotId],
    )
        ->get_result()
        ->fetch_assoc() ?:
        null;
}

function ecCreatePaymentLot(
    string $name,
    string $description,
    int $userId,
    string $allocationMode = "ALL",
    array $allocatedUserIds = [],
): int {
    $name = trim($name);
    $description = trim($description);
    if ($name === "" || mb_strlen($name) > 120) {
        throw new DomainException("Enter a payment lot name of up to 120 characters.");
    }
    if (mb_strlen($description) > 500) {
        throw new DomainException("Description must be 500 characters or fewer.");
    }
    $allocationMode = strtoupper(trim($allocationMode));
    if (!in_array($allocationMode, ["ALL", "SELECTED"], true)) {
        throw new DomainException("Choose who can access this payment lot.");
    }
    $allocatedUserIds = array_values(array_unique(array_filter(array_map("intval", $allocatedUserIds))));
    if ($allocationMode === "SELECTED" && !$allocatedUserIds) {
        throw new DomainException("Select at least one district user for this payment lot.");
    }
    if ($allocationMode === "SELECTED") {
        $placeholders = implode(",", array_fill(0, count($allocatedUserIds), "?"));
        $valid = ecQuery(
            "SELECT COUNT(*) total FROM users WHERE role='district_user' AND is_active=1 AND id IN ($placeholders)",
            str_repeat("i", count($allocatedUserIds)),
            $allocatedUserIds,
        )
            ->get_result()
            ->fetch_assoc();
        if ((int) $valid["total"] !== count($allocatedUserIds)) {
            throw new DomainException("One or more selected district users are invalid or inactive.");
        }
    }
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery(
            "INSERT INTO payment_lots(name,description,created_by,allocation_mode) VALUES(?,?,?,?)",
            "ssis",
            [$name, $description, $userId, $allocationMode],
        );
        $lotId = $db->insert_id;
        foreach ($allocatedUserIds as $allocatedUserId) {
            ecQuery(
                "INSERT INTO payment_lot_allocations(lot_id,user_id,allocated_by) VALUES(?,?,?)",
                "iii",
                [$lotId, $allocatedUserId, $userId],
            );
        }
        $db->commit();
        return $lotId;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function ecUserCanAccessPaymentLot(int $lotId, int $userId): bool
{
    $row = ecQuery(
        "SELECT 1 FROM payment_lots l WHERE l.id=? AND (l.allocation_mode='ALL' OR EXISTS (SELECT 1 FROM payment_lot_allocations a WHERE a.lot_id=l.id AND a.user_id=?))",
        "ii",
        [$lotId, $userId],
    )
        ->get_result()
        ->fetch_row();
    return (bool) $row;
}

function ecSetPaymentLotAllocation(
    int $lotId,
    string $allocationMode,
    array $allocatedUserIds,
    int $adminId,
): void {
    $lot = ecPaymentLot($lotId);
    if (!$lot) {
        throw new DomainException("Payment lot not found.");
    }
    $allocationMode = strtoupper(trim($allocationMode));
    $allocatedUserIds = array_values(array_unique(array_filter(array_map("intval", $allocatedUserIds))));
    if (!in_array($allocationMode, ["ALL", "SELECTED"], true)) {
        throw new DomainException("Choose who can access this payment lot.");
    }
    if ($allocationMode === "SELECTED" && !$allocatedUserIds) {
        throw new DomainException("Select at least one district user for this payment lot.");
    }
    if ($allocationMode === "SELECTED") {
        $placeholders = implode(",", array_fill(0, count($allocatedUserIds), "?"));
        $valid = ecQuery(
            "SELECT COUNT(*) total FROM users WHERE role='district_user' AND is_active=1 AND id IN ($placeholders)",
            str_repeat("i", count($allocatedUserIds)),
            $allocatedUserIds,
        )
            ->get_result()
            ->fetch_assoc();
        if ((int) $valid["total"] !== count($allocatedUserIds)) {
            throw new DomainException("One or more selected district users are invalid or inactive.");
        }
    }
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery("UPDATE payment_lots SET allocation_mode=? WHERE id=?", "si", [$allocationMode, $lotId]);
        ecQuery("DELETE FROM payment_lot_allocations WHERE lot_id=?", "i", [$lotId]);
        if ($allocationMode === "SELECTED") {
            foreach ($allocatedUserIds as $allocatedUserId) {
                ecQuery(
                    "INSERT INTO payment_lot_allocations(lot_id,user_id,allocated_by) VALUES(?,?,?)",
                    "iii",
                    [$lotId, $allocatedUserId, $adminId],
                );
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function ecSetPaymentLotStatus(int $lotId, string $status, int $userId): void
{
    if (!in_array($status, ["ACTIVE", "INACTIVE", "ARCHIVED"], true)) {
        throw new DomainException("Choose a valid payment lot action.");
    }
    $lot = ecPaymentLot($lotId);
    if (!$lot) {
        throw new DomainException("Payment lot not found.");
    }
    if ($lot["status"] === "ARCHIVED" && $status !== "ARCHIVED") {
        throw new DomainException("Archived payment lots are read-only.");
    }
    ecQuery(
        "UPDATE payment_lots SET status=?,status_changed_by=?,status_changed_at=NOW() WHERE id=?",
        "sii",
        [$status, $userId, $lotId],
    );
}

function ecImportPaymentLotWorkbook(
    string $path,
    string $filename,
    int $lotId,
    int $districtId,
    int $userId,
): int {
    $lot = ecPaymentLot($lotId);
    if (!$lot || $lot["status"] !== "ACTIVE") {
        throw new DomainException("Choose an active payment lot.");
    }
    if (!ecUserCanAccessPaymentLot($lotId, $userId)) {
        throw new DomainException("This payment lot has not been allocated to your account.");
    }
    $source = ecSpreadsheetUpload($path, ["phone", "custname", "tranamount", "narration"]);
    $rows = [];
    $totalCents = 0;
    foreach ($source as $row) {
        $line = $row["_line"];
        $phone = ecNormalizePhone($row["phone"], $line);
        $name = trim($row["custname"]);
        if ($name === "" || mb_strlen($name) > 100) {
            throw new DomainException(
                "Row {$line}: CustName is required and must not exceed 100 characters.",
            );
        }
        $amount = str_replace(",", "", $row["tranamount"]);
        if (
            !preg_match('/^\d{1,11}(?:\.\d{1,2})?$/D', $amount) ||
            (float) $amount <= 0 ||
            (float) $amount >= 1000000000000
        ) {
            throw new DomainException(
                "Row {$line}: TranAmount must be a positive number with up to two decimal places.",
            );
        }
        if (mb_strlen($row["narration"]) > 500) {
            throw new DomainException("Row {$line}: Narration exceeds 500 characters.");
        }
        $amount = number_format((float) $amount, 2, ".", "");
        $totalCents += (int) round((float) $amount * 100);
        $rows[] = [$phone, $name, $amount, $row["narration"]];
    }
    $hash = hash_file("sha256", $path);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery(
            "INSERT INTO payment_lot_uploads(lot_id,district_id,uploaded_by,original_filename,file_hash,row_count,total_amount) VALUES(?,?,?,?,?,?,?)",
            "iiissis",
            [
                $lotId,
                $districtId,
                $userId,
                basename($filename),
                $hash,
                count($rows),
                number_format($totalCents / 100, 2, ".", ""),
            ],
        );
        $uploadId = $db->insert_id;
        foreach (array_chunk($rows, 300) as $chunk) {
            $values = [];
            $params = [];
            $types = "";
            foreach ($chunk as [$phone, $name, $amount, $narration]) {
                $values[] = "(?,?,?,?,?,?,?)";
                array_push($params, $uploadId, $lotId, $districtId, $phone, $name, $amount, $narration);
                $types .= "iiissss";
            }
            ecQuery(
                "INSERT INTO payment_lot_entries(upload_id,lot_id,district_id,phone,cust_name,amount,narration) VALUES " .
                    implode(",", $values),
                $types,
                $params,
            );
        }
        $db->commit();
        return count($rows);
    } catch (mysqli_sql_exception $error) {
        $db->rollback();
        if ((int) $error->getCode() === 1062) {
            throw new DomainException(
                "This workbook has already been uploaded to this payment lot for your district.",
            );
        }
        throw $error;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}
function ecPaymentUpload(int $uploadId): ?array
{
    return ecQuery(
        "SELECT x.*,l.status lot_status,l.name lot_name,u.full_name uploaded_by_name,d.name district_name FROM payment_lot_uploads x JOIN payment_lots l ON l.id=x.lot_id JOIN districts d ON d.id=x.district_id LEFT JOIN users u ON u.id=x.uploaded_by WHERE x.id=?",
        "i",
        [$uploadId],
    )->get_result()->fetch_assoc() ?: null;
}

function ecSetPaymentUploadAmendment(int $uploadId, bool $enabled, int $adminId): void
{
    $upload = ecPaymentUpload($uploadId);
    if (!$upload) {
        throw new DomainException("Payment list not found.");
    }
    if ($upload["lot_status"] === "ARCHIVED") {
        throw new DomainException("Archived payment lots cannot be amended.");
    }
    ecQuery(
        "UPDATE payment_lot_uploads SET amendment_enabled=?,amendment_changed_by=?,amendment_changed_at=NOW() WHERE id=?",
        "iii",
        [$enabled ? 1 : 0, $adminId, $uploadId],
    );
    ecQuery(
        "INSERT INTO payment_lot_amendment_log(upload_id,action,changed_by) VALUES(?,?,?)",
        "isi",
        [$uploadId, $enabled ? "GRANT" : "REVOKE", $adminId],
    );
}

function ecAssertPaymentUploadAmendable(int $uploadId, int $userId): array
{
    $upload = ecPaymentUpload($uploadId);
    if (!$upload || (int) $upload["uploaded_by"] !== $userId) {
        throw new DomainException("Payment list not found or does not belong to your account.");
    }
    if (!(int) $upload["amendment_enabled"]) {
        throw new DomainException("An administrator must grant amendment permission for this payment list.");
    }
    if ($upload["lot_status"] !== "ACTIVE") {
        throw new DomainException("Only active payment lots can be amended.");
    }
    return $upload;
}

function ecValidatePaymentEntry(string $phone, string $name, string $amount, string $narration): array
{
    $phone = ecNormalizePhone(trim($phone), 1);
    $name = trim($name);
    $narration = trim($narration);
    if ($name === "" || mb_strlen($name) > 100) {
        throw new DomainException("Customer name is required and must not exceed 100 characters.");
    }
    $amount = str_replace(",", "", trim($amount));
    if (!preg_match('/^\d{1,11}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0 || (float) $amount >= 1000000000000) {
        throw new DomainException("Amount must be a positive number with up to two decimal places.");
    }
    if (mb_strlen($narration) > 500) {
        throw new DomainException("Narration must not exceed 500 characters.");
    }
    return [$phone, $name, number_format((float) $amount, 2, ".", ""), $narration];
}

function ecRecalculatePaymentUpload(int $uploadId): void
{
    ecQuery(
        "UPDATE payment_lot_uploads x SET x.row_count=(SELECT COUNT(*) FROM payment_lot_entries e WHERE e.upload_id=x.id),x.total_amount=(SELECT COALESCE(SUM(e.amount),0) FROM payment_lot_entries e WHERE e.upload_id=x.id) WHERE x.id=?",
        "i",
        [$uploadId],
    );
}

function ecAddPaymentEntry(int $uploadId, int $userId, string $phone, string $name, string $amount, string $narration): int
{
    $upload = ecAssertPaymentUploadAmendable($uploadId, $userId);
    [$phone, $name, $amount, $narration] = ecValidatePaymentEntry($phone, $name, $amount, $narration);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery("INSERT INTO payment_lot_entries(upload_id,lot_id,district_id,phone,cust_name,amount,narration) VALUES(?,?,?,?,?,?,?)", "iiissss", [$uploadId, $upload["lot_id"], $upload["district_id"], $phone, $name, $amount, $narration]);
        $entryId = $db->insert_id;
        ecQuery("INSERT INTO payment_lot_amendment_log(upload_id,entry_id,action,changed_by,after_json) VALUES(?,?,'ADD',?,?)", "iiis", [$uploadId, $entryId, $userId, json_encode(compact("phone", "name", "amount", "narration"), JSON_UNESCAPED_UNICODE)]);
        ecRecalculatePaymentUpload($uploadId);
        $db->commit();
        return $entryId;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function ecUpdatePaymentEntry(int $entryId, int $uploadId, int $userId, string $phone, string $name, string $amount, string $narration): void
{
    ecAssertPaymentUploadAmendable($uploadId, $userId);
    $before = ecQuery("SELECT phone,cust_name,amount,narration FROM payment_lot_entries WHERE id=? AND upload_id=?", "ii", [$entryId, $uploadId])->get_result()->fetch_assoc();
    if (!$before) {
        throw new DomainException("Payment entry not found.");
    }
    [$phone, $name, $amount, $narration] = ecValidatePaymentEntry($phone, $name, $amount, $narration);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery("UPDATE payment_lot_entries SET phone=?,cust_name=?,amount=?,narration=? WHERE id=? AND upload_id=?", "ssssii", [$phone, $name, $amount, $narration, $entryId, $uploadId]);
        ecQuery("INSERT INTO payment_lot_amendment_log(upload_id,entry_id,action,changed_by,before_json,after_json) VALUES(?,?,'UPDATE',?,?,?)", "iiiss", [$uploadId, $entryId, $userId, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode(compact("phone", "name", "amount", "narration"), JSON_UNESCAPED_UNICODE)]);
        ecRecalculatePaymentUpload($uploadId);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function ecDeletePaymentEntry(int $entryId, int $uploadId, int $userId): void
{
    ecAssertPaymentUploadAmendable($uploadId, $userId);
    $before = ecQuery("SELECT phone,cust_name,amount,narration FROM payment_lot_entries WHERE id=? AND upload_id=?", "ii", [$entryId, $uploadId])->get_result()->fetch_assoc();
    if (!$before) {
        throw new DomainException("Payment entry not found.");
    }
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery("DELETE FROM payment_lot_entries WHERE id=? AND upload_id=?", "ii", [$entryId, $uploadId]);
        ecQuery("INSERT INTO payment_lot_amendment_log(upload_id,entry_id,action,changed_by,before_json) VALUES(?,?,'DELETE',?,?)", "iiis", [$uploadId, $entryId, $userId, json_encode($before, JSON_UNESCAPED_UNICODE)]);
        ecRecalculatePaymentUpload($uploadId);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}
