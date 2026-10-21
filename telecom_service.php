<?php

require_once __DIR__ . "/xlsx_service.php";

function ecNameMatchPercentage(string $submittedName, string $registeredName): float
{
    $normalize = static function (string $name): string {
        $name = mb_strtoupper(trim($name), "UTF-8");
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
    };
    $submitted = $normalize($submittedName);
    $registered = $normalize($registeredName);
    if ($submitted === '' || $registered === '') {
        return 0.0;
    }
    $length = max(strlen($submitted), strlen($registered));
    return round(max(0, 100 * (1 - levenshtein($submitted, $registered) / $length)), 2);
}

function ecMatchStatus(float $percentage): string
{
    return $percentage >= 90 ? "MATCH" : ($percentage >= 60 ? "PARTIAL" : "NO_MATCH");
}
function ecTelecomAccess(int $userId): bool
{
    $row = ecQuery("SELECT is_enabled FROM telecom_api_permissions WHERE user_id=?", "i", [$userId])
        ->get_result()
        ->fetch_assoc();
    return (bool) ($row["is_enabled"] ?? false);
}

function ecSetTelecomAccess(int $userId, bool $enabled, int $adminId): void
{
    $user = ecQuery("SELECT id FROM users WHERE id=? AND role='district_user' AND is_active=1", "i", [
        $userId,
    ])
        ->get_result()
        ->fetch_assoc();
    if (!$user) {
        throw new DomainException("Choose an active district user.");
    }
    if ($enabled) {
        ecQuery(
            "INSERT INTO telecom_api_permissions(user_id,is_enabled,granted_by,granted_at,revoked_by,revoked_at) " .
                "VALUES(?,1,?,NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE is_enabled=1,granted_by=VALUES(granted_by)," .
                "granted_at=NOW(),revoked_by=NULL,revoked_at=NULL",
            "ii",
            [$userId, $adminId],
        );
    } else {
        ecQuery(
            "INSERT INTO telecom_api_permissions(user_id,is_enabled,revoked_by,revoked_at) VALUES(?,0,?,NOW()) " .
                "ON DUPLICATE KEY UPDATE is_enabled=0,revoked_by=VALUES(revoked_by),revoked_at=NOW()",
            "ii",
            [$userId, $adminId],
        );
    }
}

function ecQueueTelecomWorkbook(
    string $path,
    string $filename,
    string $provider,
    int $districtId,
    int $userId,
): int {
    if (!ecTelecomAccess($userId)) {
        throw new DomainException(
            "Your telecom verification access is disabled. Ask an administrator to grant access.",
        );
    }
    if (!in_array($provider, ["AUTO", "MTN", "AIRTEL"], true)) {
        throw new DomainException("Choose Auto, MTN, or Airtel.");
    }
    $source = ecSpreadsheetUpload($path, ["phone", "custname"]);
    $rows = [];
    foreach ($source as $row) {
        $line = $row["_line"];
        $name = trim($row["custname"]);
        if ($name === "" || mb_strlen($name) > 100) {
            throw new DomainException(
                "Row {$line}: CustName is required and must not exceed 100 characters.",
            );
        }
        $rows[] = [ecNormalizePhone($row["phone"], $line), $name];
    }
    $hash = hash_file("sha256", $path);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery(
            "INSERT INTO telecom_verification_batches(district_id,uploaded_by,original_filename,file_hash,provider_requested,row_count) VALUES(?,?,?,?,?,?)",
            "iisssi",
            [$districtId, $userId, basename($filename), $hash, $provider, count($rows)],
        );
        $batchId = $db->insert_id;
        foreach ($rows as [$phone, $name]) {
            ecQuery(
                "INSERT IGNORE INTO telecom_verification_cache(phone,provider,first_requested_by,first_district_id) VALUES(?,?,?,?)",
                "ssii",
                [$phone, $provider, $userId, $districtId],
            );
            $cache = ecQuery(
                "SELECT id,provider,registered_name,match_percentage,match_status,provider_reference,response_received_at,error_message FROM telecom_verification_cache WHERE phone=?",
                "s",
                [$phone],
            )
                ->get_result()
                ->fetch_assoc();
            if (!$cache) {
                throw new RuntimeException("Could not reserve the phone verification record.");
            }
            $cacheHit = $cache["match_status"] !== "PENDING" ? 1 : 0;
            $entryPercentage = $cache["match_percentage"];
            $entryStatus = $cache["match_status"];
            if ($cacheHit && $cache["registered_name"] && $cache["match_status"] !== "ERROR") {
                $entryPercentage = ecNameMatchPercentage($name, $cache["registered_name"]);
                $entryStatus = ecMatchStatus($entryPercentage);
            }
            ecQuery(
                "INSERT INTO telecom_verification_entries(batch_id,cache_id,cache_hit,phone,submitted_name,provider,registered_name,match_percentage,match_status,provider_reference,response_received_at,error_message) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                "iiisssssssss",
                [
                    $batchId,
                    $cache["id"],
                    $cacheHit,
                    $phone,
                    $name,
                    $cache["provider"] === "AUTO" ? "PENDING" : $cache["provider"],
                    $cache["registered_name"],
                    $entryPercentage,
                    $entryStatus,
                    $cache["provider_reference"],
                    $cache["response_received_at"],
                    $cache["error_message"],
                ],
            );
        }
        $db->commit();
        return count($rows);
    } catch (mysqli_sql_exception $error) {
        $db->rollback();
        if ((int) $error->getCode() === 1062) {
            throw new DomainException("You have already submitted this workbook for telecom verification.");
        }
        throw $error;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function ecTelecomUsage(?int $userId, string $from, string $to): array
{
    $fromDate = DateTimeImmutable::createFromFormat("!Y-m-d", $from);
    $toDate = DateTimeImmutable::createFromFormat("!Y-m-d", $to);
    if (!$fromDate || !$toDate || $fromDate->format("Y-m-d") !== $from || $toDate->format("Y-m-d") !== $to) {
        throw new DomainException("Choose valid reporting dates.");
    }
    if ($fromDate > $toDate || $fromDate->diff($toDate)->days > 366) {
        throw new DomainException("The reporting period must be between 1 and 367 days.");
    }
    $endExclusive = $toDate->modify("+1 day")->format("Y-m-d");
    $userEntrySql = $userId === null ? "" : " AND b.uploaded_by=?";
    $entryTypes = "ss" . ($userId === null ? "" : "i");
    $entryParams = [$from, $endExclusive];
    if ($userId !== null) {
        $entryParams[] = $userId;
    }
    $entries = ecQuery(
        "SELECT COUNT(*) submitted_records,COUNT(DISTINCT e.phone) unique_phones,SUM(e.cache_hit=1) cached_results FROM telecom_verification_entries e JOIN telecom_verification_batches b ON b.id=e.batch_id WHERE e.created_at>=? AND e.created_at<?" . $userEntrySql,
        $entryTypes,
        $entryParams,
    )->get_result()->fetch_assoc();
    $userCallSql = $userId === null ? "" : " AND l.requested_by=?";
    $calls = ecQuery(
        "SELECT COUNT(*) api_calls,SUM(l.billable=1) billable_calls,SUM(l.provider='MTN') mtn_calls,SUM(l.provider='AIRTEL') airtel_calls FROM telecom_api_call_log l WHERE l.requested_at>=? AND l.requested_at<?" . $userCallSql,
        $entryTypes,
        $entryParams,
    )->get_result()->fetch_assoc();
    $validated = ecQuery(
        "SELECT COUNT(DISTINCT e.phone) validated_numbers FROM telecom_verification_entries e JOIN telecom_verification_batches b ON b.id=e.batch_id WHERE e.response_received_at>=? AND e.response_received_at<?" . $userEntrySql,
        $entryTypes,
        $entryParams,
    )->get_result()->fetch_assoc();
    return array_merge($entries, $calls, $validated);
}

function ecPendingTelecomRequests(int $limit = 100): array
{
    $limit = max(1, min(1000, $limit));
    return ecQuery(
        "SELECT c.* FROM telecom_verification_cache c WHERE c.match_status='PENDING' AND NOT EXISTS (SELECT 1 FROM telecom_api_call_log l WHERE l.cache_id=c.id) ORDER BY c.created_at,c.id LIMIT " . $limit,
    )->get_result()->fetch_all(MYSQLI_ASSOC);
}
function ecRecordTelecomApiCall(int $cacheId, string $provider, ?int $userId, ?int $districtId): int
{
    if (!in_array($provider, ["MTN", "AIRTEL"], true)) {
        throw new DomainException("Choose MTN or Airtel before calling the provider.");
    }
    try {
        ecQuery(
            "INSERT INTO telecom_api_call_log(cache_id,provider,requested_by,district_id) VALUES(?,?,?,?)",
            "isii",
            [$cacheId, $provider, $userId, $districtId],
        );
    } catch (mysqli_sql_exception $error) {
        if ((int) $error->getCode() === 1062) {
            throw new DomainException("This phone number already has a provider call or a saved provider response.");
        }
        throw $error;
    }
    ecQuery("UPDATE telecom_verification_cache SET api_call_started_at=NOW(),provider=? WHERE id=?", "si", [$provider, $cacheId]);
    return getDBConnection()->insert_id;
}

function ecCompleteTelecomVerification(
    int $cacheId,
    int $callId,
    string $provider,
    ?string $registeredName,
    ?float $matchPercentage,
    string $matchStatus,
    ?string $providerReference,
    ?string $errorMessage = null,
): void {
    if (!in_array($provider, ["MTN", "AIRTEL"], true) || !in_array($matchStatus, ["MATCH", "PARTIAL", "NO_MATCH", "ERROR"], true)) {
        throw new DomainException("The telecom response contains an invalid provider or status.");
    }
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery(
            "UPDATE telecom_verification_cache SET provider=?,registered_name=?,match_percentage=?,match_status=?,provider_reference=?,error_message=?,response_received_at=NOW() WHERE id=?",
            "ssdsssi",
            [$provider, $registeredName, $matchPercentage, $matchStatus, $providerReference, $errorMessage, $cacheId],
        );
        $linkedEntries = ecQuery(
            "SELECT id,submitted_name FROM telecom_verification_entries WHERE cache_id=? FOR UPDATE",
            "i",
            [$cacheId],
        )->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($linkedEntries as $linkedEntry) {
            $entryPercentage = $matchPercentage;
            $entryStatus = $matchStatus;
            if ($registeredName && $matchStatus !== "ERROR") {
                $entryPercentage = ecNameMatchPercentage($linkedEntry["submitted_name"], $registeredName);
                $entryStatus = ecMatchStatus($entryPercentage);
            }
            ecQuery(
                "UPDATE telecom_verification_entries SET provider=?,registered_name=?,match_percentage=?,match_status=?,provider_reference=?,error_message=?,response_received_at=NOW() WHERE id=?",
                "ssdsssi",
                [$provider, $registeredName, $entryPercentage, $entryStatus, $providerReference, $errorMessage, $linkedEntry["id"]],
            );
        }
        ecQuery(
            "UPDATE telecom_api_call_log SET successful=?,provider_reference=?,error_message=?,completed_at=NOW() WHERE id=? AND cache_id=?",
            "issii",
            [$matchStatus === "ERROR" ? 0 : 1, $providerReference, $errorMessage, $callId, $cacheId],
        );
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}
function ecTelecomBatches(?int $userId): array
{
    $where = $userId === null ? "" : " WHERE b.uploaded_by=?";
    return ecQuery(
        "SELECT b.*,d.name district_name,u.full_name uploaded_by_name," .
            'SUM(e.match_status="MATCH") matched,SUM(e.match_status="PARTIAL") partial_matches,' .
            'SUM(e.match_status="NO_MATCH") no_matches FROM telecom_verification_batches b ' .
            "JOIN districts d ON d.id=b.district_id LEFT JOIN users u ON u.id=b.uploaded_by " .
            "LEFT JOIN telecom_verification_entries e ON e.batch_id=b.id" .
            $where .
            " GROUP BY b.id ORDER BY b.created_at DESC LIMIT 100",
        $userId === null ? "" : "i",
        $userId === null ? [] : [$userId],
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
}

function ecTelecomBatchEntries(int $batchId, ?int $userId): array
{
    $where = " WHERE e.batch_id=?";
    $types = "i";
    $params = [$batchId];
    if ($userId !== null) {
        $where .= " AND b.uploaded_by=?";
        $types .= "i";
        $params[] = $userId;
    }
    return ecQuery(
        "SELECT e.* FROM telecom_verification_entries e JOIN telecom_verification_batches b ON b.id=e.batch_id" .
            $where .
            " ORDER BY e.id LIMIT 500",
        $types,
        $params,
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
}
