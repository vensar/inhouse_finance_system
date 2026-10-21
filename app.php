<?php

// Shared query, dashboard, upload, and conversation services.
function ecQuery($sql, $types = "", $params = [])
{
    $stmt = getDBConnection()->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}
function ecEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
function ecDashboard($userId, $role, $districtId, $from = null, $to = null)
{
    $from = $from ?: "1900-01-01";
    $to = $to ?: date("Y-m-d");
    $end = (new DateTimeImmutable($to))->modify("+1 day")->format("Y-m-d");
    $scope = $role === "admin" ? "all" : "district-" . (int) $districtId;
    $path = __DIR__ . "/var/cache/dashboard-" . $scope . "-" . hash("sha256", $from . "|" . $to) . ".json";
    $stats =
        is_file($path) && filemtime($path) > time() - 60
            ? json_decode(file_get_contents($path), true)
            : null;
    if (!$stats) {
        $where = " WHERE created_at>=? AND created_at<?";
        $params = [$from, $end];
        $types = "ss";
        if ($role !== "admin") {
            $where .= " AND district_id=?";
            $params[] = (int) $districtId;
            $types .= "i";
        }
        $stats = ecQuery(
            "SELECT COUNT(*) AS total_payments, COALESCE(SUM(tran_amount),0) AS total_amount, COALESCE(SUM(status='SUCCESS'),0) AS success_payments, COALESCE(SUM(status='FAILED'),0) AS failed_payments FROM payments" .
                $where,
            $types,
            $params,
        )
            ->get_result()
            ->fetch_assoc();
        $stats["total_districts"] = (int) ecQuery(
            "SELECT COUNT(*) AS n FROM districts WHERE name<>'UNCATEGORIZED'",
        )
            ->get_result()
            ->fetch_assoc()["n"];
        $stats["by_district"] =
            $role === "admin"
                ? ecQuery(
                    "SELECT d.id,d.name,t.payment_count,t.total_amount,t.failed_count,t.success_rate FROM (SELECT district_id,SUM(status='SUCCESS') AS payment_count,SUM(IF(status='SUCCESS',tran_amount,0)) AS total_amount,SUM(status='FAILED') AS failed_count,100*SUM(status='SUCCESS')/NULLIF(COUNT(*),0) AS success_rate FROM payments WHERE created_at>=? AND created_at<? GROUP BY district_id) t JOIN districts d ON d.id=t.district_id WHERE d.name<>'UNCATEGORIZED' AND t.payment_count>0 ORDER BY t.total_amount DESC,d.name LIMIT 10",
                    "ss",
                    [$from, $end],
                )
                    ->get_result()
                    ->fetch_all(MYSQLI_ASSOC)
                : [];
        $stats["updated_at"] = time();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, json_encode($stats), LOCK_EX);
    }
    $where = " WHERE p.created_at>=? AND p.created_at<?";
    $recentTypes = "ss";
    $recentParams = [$from, $end];
    if ($role !== "admin") {
        $where .= " AND p.district_id=?";
        $recentTypes .= "i";
        $recentParams[] = (int) $districtId;
    }
    $stats["recent_payments"] = ecQuery(
        "SELECT p.*,d.name AS district_name FROM payments p LEFT JOIN districts d ON d.id=p.district_id" .
            $where .
            " ORDER BY p.id DESC LIMIT 10",
        $recentTypes,
        $recentParams,
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $stats["unread_messages"] = ecQuery(
        "SELECT COUNT(*) AS n FROM messages WHERE receiver_id=? AND is_read=0",
        "i",
        [(int) $userId],
    )
        ->get_result()
        ->fetch_assoc()["n"];
    $lotScope = $role === "admin" ? "" : " AND x.district_id=?";
    $lotTypes = $role === "admin" ? "ss" : "ssi";
    $lotParams = $role === "admin" ? [$from, $end] : [$from, $end, (int) $districtId];
    $stats["lot_activity"] = ecQuery(
        "SELECT COUNT(DISTINCT x.id) uploads,COALESCE(SUM(x.row_count),0) entries,COALESCE(SUM(x.total_amount),0) amount FROM payment_lot_uploads x WHERE x.created_at>=? AND x.created_at<?" . $lotScope,
        $lotTypes,
        $lotParams,
    )->get_result()->fetch_assoc();
    $stats["active_lots"] = (int) ecQuery("SELECT COUNT(*) n FROM payment_lots WHERE status='ACTIVE'")->get_result()->fetch_assoc()["n"];
    $telecomScope = $role === "admin" ? "" : " AND b.district_id=?";
    $telecomTypes = $role === "admin" ? "ss" : "ssi";
    $telecomParams = $role === "admin" ? [$from, $end] : [$from, $end, (int) $districtId];
    $stats["telecom_activity"] = ecQuery(
        "SELECT COUNT(*) submitted,SUM(e.response_received_at IS NOT NULL) completed,SUM(e.cache_hit=1) cache_hits FROM telecom_verification_entries e JOIN telecom_verification_batches b ON b.id=e.batch_id WHERE e.created_at>=? AND e.created_at<?" . $telecomScope,
        $telecomTypes,
        $telecomParams,
    )->get_result()->fetch_assoc();
    if ($role === "admin") {
        $stats["accountability"] = ecQuery(
            "SELECT (SELECT COALESCE(SUM(amount),0) FROM accountability_allocations WHERE allocation_date>=? AND allocation_date<?) allocated,(SELECT COALESCE(SUM(invoice_amount),0) FROM accountability_expenses WHERE invoice_date>=? AND invoice_date<?) spent,(SELECT COALESCE(SUM(accounted_amount),0) FROM accountability_expenses WHERE invoice_date>=? AND invoice_date<?) accounted",
            "ssssss",
            [$from, $end, $from, $end, $from, $end],
        )->get_result()->fetch_assoc();
        $stats["active_users"] = (int) ecQuery("SELECT COUNT(*) n FROM users WHERE is_active=1")->get_result()->fetch_assoc()["n"];
    }
    return $stats;
}
function ecInvalidateDashboard()
{
    foreach (glob(__DIR__ . "/var/cache/dashboard-*.json") ?: [] as $file) {
        unlink($file);
    }
}
function ecUncategorizedDistrictId()
{
    static $id = null;
    if ($id === null) {
        $row = ecQuery("SELECT id FROM districts WHERE name='UNCATEGORIZED' LIMIT 1")
            ->get_result()
            ->fetch_assoc();
        $id = (int) ($row["id"] ?? 0);
    }
    return $id;
}
function ecPaymentPage($phone, $name, $districtId, $before = 0, $after = 0, $status = null)
{
    $where = [];
    $params = [];
    $types = "";
    if ($status !== null) {
        if (!in_array($status, ["SUCCESS", "FAILED", "PENDING"], true)) {
            throw new InvalidArgumentException("Unsupported payment status.");
        }
        $where[] = "p.status=?";
        $params[] = $status;
        $types .= "s";
    }
    foreach (["p.phone" => $phone, "p.cust_name" => $name] as $field => $value) {
        if ($value !== "") {
            $where[] = "$field LIKE ?";
            $params[] = addcslashes($value, "\\%_") . "%";
            $types .= "s";
        }
    }
    if ($districtId !== null) {
        $where[] = "p.district_id IN (?,?)";
        $params[] = (int) $districtId;
        $params[] = ecUncategorizedDistrictId();
        $types .= "ii";
    }
    if ($after > 0) {
        $where[] = "p.id>?";
        $params[] = $after;
        $types .= "i";
    } elseif ($before > 0) {
        $where[] = "p.id<?";
        $params[] = $before;
        $types .= "i";
    }
    $sql =
        "SELECT p.*,d.name AS district_name FROM payments p LEFT JOIN districts d ON d.id=p.district_id" .
        ($where ? " WHERE " . implode(" AND ", $where) : "") .
        " ORDER BY p.id " .
        ($after > 0 ? "ASC" : "DESC") .
        " LIMIT 51";
    $rows = ecQuery($sql, $types, $params)
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $extra = count($rows) > 50;
    if ($extra) {
        array_pop($rows);
    }
    if ($after > 0) {
        $rows = array_reverse($rows);
    }
    return [
        "rows" => $rows,
        "older" => $after > 0 || $extra,
        "newer" => $after > 0 ? $extra : $before > 0,
    ];
}
function ecChatPeer($sender, $receiverId)
{
    $peer = ecQuery(
        "SELECT id,role,full_name,district_id FROM users WHERE id=? AND is_active=1",
        "i",
        [(int) $receiverId],
    )
        ->get_result()
        ->fetch_assoc();
    if (
        !$peer ||
        (int) $peer["id"] === (int) $sender["id"] ||
        !in_array($sender["role"], ["admin", "district_user"], true) ||
        ($sender["role"] === "admin"
            ? $peer["role"] !== "district_user"
            : $peer["role"] !== "admin")
    ) {
        throw new DomainException(
            "Choose an active " .
                ($sender["role"] === "admin" ? "district user" : "administrator") .
                ".",
        );
    }
    return $peer;
}
function ecChatContacts($user)
{
    $other = $user["role"] === "admin" ? "district_user" : "admin";
    return ecQuery(
        "SELECT u.id,u.full_name,u.username,d.name AS district_name,COALESCE(m.unread,0) AS unread,m.latest FROM users u LEFT JOIN districts d ON d.id=u.district_id LEFT JOIN (SELECT IF(sender_id=?,receiver_id,sender_id) AS peer,MAX(id) AS latest,SUM(receiver_id=? AND is_read=0) AS unread FROM messages WHERE sender_id=? OR receiver_id=? GROUP BY peer) m ON m.peer=u.id WHERE u.is_active=1 AND u.role=? ORDER BY m.latest DESC,u.full_name,u.id",
        "iiiis",
        [(int) $user["id"], (int) $user["id"], (int) $user["id"], (int) $user["id"], $other],
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
}
function ecChatRead($user, $peerId, $after = 0, $before = 0)
{
    ecChatPeer($user, $peerId);
    $params = [(int) $user["id"], (int) $peerId, (int) $peerId, (int) $user["id"]];
    $types = "iiii";
    $filter = "";
    if ($after > 0) {
        $filter = " AND id>?";
        $params[] = $after;
        $types .= "i";
    } elseif ($before > 0) {
        $filter = " AND id<?";
        $params[] = $before;
        $types .= "i";
    }
    $rows = ecQuery(
        "SELECT id,sender_id,receiver_id,message,is_read,created_at FROM messages WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?))" .
            $filter .
            " ORDER BY id " .
            ($after > 0 ? "ASC" : "DESC") .
            " LIMIT 100",
        $types,
        $params,
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    if (!$after) {
        $rows = array_reverse($rows);
    }
    if ($rows) {
        $max = max(array_column($rows, "id"));
        ecQuery(
            "UPDATE messages SET is_read=1 WHERE receiver_id=? AND sender_id=? AND id<=? AND is_read=0",
            "iii",
            [(int) $user["id"], (int) $peerId, (int) $max],
        );
    }
    return $rows;
}
function ecChatSend($user, $peerId, $message)
{
    ecChatPeer($user, $peerId);
    $message = trim($message);
    if ($message === "" || mb_strlen($message) > 4000) {
        throw new DomainException("Enter a message of 1–4,000 characters.");
    }
    $recent = ecQuery(
        "SELECT COUNT(*) AS n FROM messages WHERE sender_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE)",
        "i",
        [(int) $user["id"]],
    )
        ->get_result()
        ->fetch_assoc()["n"];
    if ($recent >= 30) {
        throw new DomainException("Please wait a moment before sending another message.");
    }
    ecQuery("INSERT INTO messages(sender_id,receiver_id,message) VALUES(?,?,?)", "iis", [
        (int) $user["id"],
        (int) $peerId,
        $message,
    ]);
    return getDBConnection()->insert_id;
}
function ecCsvPayments($path, $defaultDistrict = 0)
{
    $districts = getAllDistricts();
    $byName = [];
    $byId = [];
    foreach ($districts as $d) {
        $byName[mb_strtoupper(trim($d["name"]))] = $d;
        $byId[(int) $d["id"]] = $d;
    }
    if ($defaultDistrict && !isset($byId[$defaultDistrict])) {
        throw new DomainException("Choose a valid district.");
    }
    usort($districts, fn ($a, $b) => strlen($b["name"]) <=> strlen($a["name"]));
    $file = fopen($path, "rb");
    if (!$file) {
        throw new DomainException("Unable to read the CSV.");
    }
    try {
        $bom = fread($file, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file);
        }
        $raw = fgetcsv($file, 0, ",", '"', "");
        if (!$raw) {
            throw new DomainException("The CSV is empty.");
        }
        $headers = array_map(
            fn ($h) => strtolower(preg_replace("/[^a-z0-9]/i", "", trim((string) $h))),
            $raw,
        );
        if (count(array_unique($headers)) !== count($headers)) {
            throw new DomainException("The CSV has duplicate column headings.");
        }
        foreach (["phone", "custname", "tranamount", "status", "narration"] as $required) {
            if (!in_array($required, $headers, true)) {
                throw new DomainException(
                    "Missing column: " . $required . ". Use the comma-separated CSV template.",
                );
            }
        }
        $rows = [];
        $errors = [];
        $number = 1;
        while (($values = fgetcsv($file, 0, ",", '"', "")) !== false) {
            $number++;
            if (count($values) === 1 && trim((string) $values[0]) === "") {
                continue;
            }
            if ($number > 10001) {
                throw new DomainException(
                    "Upload at most 10,000 records per file. Split larger files first.",
                );
            }
            if (count($values) !== count($headers)) {
                $errors[] = "Row $number: column count does not match the header.";
                continue;
            }
            $r = array_combine($headers, array_map(fn ($v) => trim((string) $v), $values));
            if (!mb_check_encoding(implode("", $r), "UTF-8")) {
                $errors[] = "Row $number: save the CSV as UTF-8.";
                continue;
            }
            $amount = str_replace(",", "", $r["tranamount"]);
            if (
                !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount) ||
                (float) $amount >= 100000000 ||
                strlen($r["phone"]) > 20 ||
                $r["phone"] === "" ||
                mb_strlen($r["custname"]) > 100 ||
                mb_strlen($r["narration"]) > 15000 ||
                !in_array(strtoupper($r["status"]), ["SUCCESS", "FAILED", "PENDING"], true)
            ) {
                $errors[] = "Row $number: check phone, amount, status, or field length.";
                continue;
            }
            $district = $defaultDistrict ? $byId[$defaultDistrict] : null;
            if (!$district && !empty($r["district"])) {
                $district = $byName[mb_strtoupper($r["district"])] ?? null;
            }
            if (!$district && empty($r["district"])) {
                $matches = [];
                foreach ($districts as $d) {
                    if (
                        $d["name"] !== "UNCATEGORIZED" &&
                        preg_match(
                            "/(?<![\pL\pN])" . preg_quote($d["name"], "/") . "(?![\pL\pN])/iu",
                            $r["narration"],
                        )
                    ) {
                        $contained = false;
                        foreach ($matches as $m) {
                            if (stripos($m["name"], $d["name"]) !== false) {
                                $contained = true;
                            }
                        }
                        if (!$contained) {
                            $matches[] = $d;
                        }
                    }
                }
                if (count($matches) === 1) {
                    $district = $matches[0];
                }
            }
            if (!$district) {
                $errors[] = "Row $number: district is missing or ambiguous. Set District explicitly or choose a district for the file.";
                continue;
            }
            $rows[] = [
                "phone" => $r["phone"],
                "cust_name" => $r["custname"],
                "tran_amount" => $amount,
                "status" => strtoupper($r["status"]),
                "narration" => $r["narration"],
                "district_id" => (int) $district["id"],
            ];
        }
        if ($errors) {
            throw new DomainException(
                implode("\n", array_slice($errors, 0, 10)) .
                    (count($errors) > 10
                        ? "\nAdditional invalid rows: " . (count($errors) - 10)
                        : ""),
            );
        }
        if (!$rows) {
            throw new DomainException("The CSV has no payment records.");
        }
        return $rows;
    } finally {
        fclose($file);
    }
}
function ecImportCsv($path, $districtId, $userId)
{
    $rows = ecCsvPayments($path, $districtId);
    $hash = hash("sha256", hash_file("sha256", $path) . ":" . $districtId);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery(
            "INSERT INTO payment_imports(file_hash,uploaded_by,record_count) VALUES(?,?,?)",
            "sii",
            [$hash, (int) $userId, count($rows)],
        );
        foreach (array_chunk($rows, 250) as $chunk) {
            $params = [];
            foreach ($chunk as $r) {
                foreach (
                    ["phone", "cust_name", "tran_amount", "status", "narration", "district_id"] as $k
                ) {
                    $params[] = $r[$k];
                };
            }
            ecQuery(
                "INSERT INTO payments(phone,cust_name,tran_amount,status,narration,district_id,uploaded_by) VALUES " .
                    implode(
                        ",",
                        array_fill(0, count($chunk), "(?,?,?,?,?,?," . (int) $userId . ")"),
                    ),
                str_repeat("sssssi", count($chunk)),
                $params,
            );
        }
        $db->commit();
        ecInvalidateDashboard();
        return count($rows);
    } catch (Throwable $e) {
        $db->rollback();
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            throw new DomainException(
                "This exact file has already been imported with this district selection.",
            );
        }
        throw $e;
    }
}
