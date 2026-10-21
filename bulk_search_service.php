<?php

function ecReadSearchCsv($path)
{
    $file = fopen($path, "rb");
    if (!$file) {
        throw new DomainException("Unable to read the CSV.");
    }
    try {
        if (fread($file, 3) !== "\xEF\xBB\xBF") {
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
            throw new DomainException("Duplicate column headings. Use the downloadable template.");
        }
        if (!in_array("phone", $headers, true) || !in_array("custname", $headers, true)) {
            throw new DomainException(
                "The CSV must contain Phone and CustName columns. Use CSV UTF-8, comma delimited.",
            );
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($file, 0, ",", '"', "")) !== false) {
            $line++;
            if (count($cells) === 1 && trim((string) $cells[0]) === "") {
                continue;
            }
            if (count($rows) >= 1000) {
                throw new DomainException("Upload no more than 1,000 search rows per file.");
            }
            $phone = "";
            $name = "";
            $error = "";
            if (count($cells) !== count($headers)) {
                $error = "Column count does not match the header.";
            } else {
                $r = array_combine($headers, array_map(fn ($v) => trim((string) $v), $cells));
                $phone = $r["phone"];
                $name = $r["custname"];
                if (!mb_check_encoding($phone . $name, "UTF-8")) {
                    $error = "Save the file as CSV UTF-8.";
                } elseif ($phone === "" && $name === "") {
                    $error = "Enter a phone number or customer name.";
                } elseif (mb_strlen($name) > 100) {
                    $error = "Customer name exceeds 100 characters.";
                } elseif (
                    $phone !== "" &&
                    (!preg_match('/^\+?[0-9 ()-]+$/D', $phone) ||
                        strlen(preg_replace("/[^0-9]/", "", $phone)) > 20)
                ) {
                    $error =
                        "Phone must contain digits, optionally with +, spaces, parentheses or hyphens.";
                }
            }
            $normalized = preg_replace("/[^0-9]/", "", $phone);
            if ($phone !== "" && $normalized === "" && $error === "") {
                $error = "Enter a valid phone number.";
            }
            $rows[] = [
                "line" => $line,
                "phone" => mb_substr($phone, 0, 100),
                "name" => mb_substr($name, 0, 150),
                "search_phone" => $normalized,
                "error" => $error,
            ];
        }
        if (!$rows) {
            throw new DomainException("The CSV has no search rows.");
        }
        return $rows;
    } finally {
        fclose($file);
    }
}
function ecSearchWhere($row, $mode, $districtId)
{
    if ($row["error"] !== "") {
        throw new DomainException("Invalid search row.");
    }
    $conditions = [];
    $params = [];
    $types = "";
    if ($row["search_phone"] !== "") {
        $conditions[] = "p.phone=?";
        $params[] = $row["search_phone"];
        $types .= "s";
    }
    if ($row["name"] !== "") {
        $conditions[] = "p.cust_name=?";
        $params[] = $row["name"];
        $types .= "s";
    }
    if (!$conditions) {
        throw new DomainException("Search criteria are required.");
    }
    $where = "(" . implode($mode === "either" ? " OR " : " AND ", $conditions) . ")";
    if (isset($row["max_id"])) {
        $where .= " AND p.id<=?";
        $params[] = (int) $row["max_id"];
        $types .= "i";
    }
    if ($districtId !== null) {
        $where .= " AND p.district_id IN (?,?)";
        $params[] = (int) $districtId;
        $params[] = ecUncategorizedDistrictId();
        $types .= "ii";
    }
    return [$where, $types, $params];
}
function ecRunBulkSearch($rows, $mode, $districtId)
{
    $totals = [
        "submitted" => count($rows),
        "matched" => 0,
        "unmatched" => 0,
        "invalid" => 0,
        "payments" => 0,
    ];
    $cache = [];
    $cutoff = (int) ecQuery("SELECT COALESCE(MAX(id),0) AS max_id FROM payments")
        ->get_result()
        ->fetch_assoc()["max_id"];
    foreach ($rows as &$row) {
        $row["max_id"] = $cutoff;
        $row["count"] = 0;
        $row["amount"] = "0.00";
        $row["success"] = 0;
        $row["failed"] = 0;
        $row["matched_names"] = [];
        $row["more_names"] = false;
        if ($row["error"] !== "") {
            $row["outcome"] = "Invalid";
            $totals["invalid"]++;
            continue;
        }
        $key = json_encode([$row["search_phone"], $row["name"]]);
        if (!isset($cache[$key])) {
            [$where, $types, $params] = ecSearchWhere($row, $mode, $districtId);
            $stmt = ecQuery(
                "SELECT COUNT(*) AS matches,COALESCE(SUM(p.tran_amount),0) AS amount,COALESCE(SUM(p.status='SUCCESS'),0) AS success,COALESCE(SUM(p.status='FAILED'),0) AS failed FROM payments p WHERE " .
                    $where,
                $types,
                $params,
            );
            $cache[$key] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            // Keep the summary bounded while preserving every payment in details/export.
            $names = [];
            if ((int) $cache[$key]["matches"] > 0) {
                $nameStatement = ecQuery(
                    "SELECT DISTINCT p.cust_name FROM payments p WHERE " .
                        $where .
                        " ORDER BY p.cust_name LIMIT 6",
                    $types,
                    $params,
                );
                $names = array_column(
                    $nameStatement->get_result()->fetch_all(MYSQLI_ASSOC),
                    "cust_name",
                );
                $nameStatement->close();
            }
            $cache[$key]["more_names"] = count($names) > 5;
            $cache[$key]["matched_names"] = array_slice($names, 0, 5);
        }
        $found = $cache[$key];
        $row["matched_names"] = $found["matched_names"];
        $row["more_names"] = $found["more_names"];
        $row["count"] = (int) $found["matches"];
        $row["amount"] = $found["amount"];
        $row["success"] = (int) $found["success"];
        $row["failed"] = (int) $found["failed"];
        $row["outcome"] = $row["count"] ? "Matched" : "No match";
        $totals[$row["count"] ? "matched" : "unmatched"]++;
        $totals["payments"] += $row["count"];
    }
    unset($row);
    return ["rows" => $rows, "totals" => $totals];
}
function ecSearchMatches($row, $mode, $districtId, $before = 0)
{
    [$where, $types, $params] = ecSearchWhere($row, $mode, $districtId);
    if ($before > 0) {
        $where .= " AND p.id<?";
        $types .= "i";
        $params[] = $before;
    }
    return ecQuery(
        "SELECT p.id,p.phone,p.cust_name,p.tran_amount,p.status,p.narration,p.created_at,d.name AS district_name FROM payments p LEFT JOIN districts d ON d.id=p.district_id WHERE " .
            $where .
            " ORDER BY p.id DESC LIMIT 51",
        $types,
        $params,
    )
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
}
function ecCsvSafe($value)
{
    $text = (string) $value;
    return preg_match("/^[\s]*[=+@\-]/u", $text) ? "'" . $text : $text;
}
function ecExportSearch($search, $out)
{
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv(
        $out,
        [
            "InputRow",
            "InputPhone",
            "InputCustName",
            "Outcome",
            "MatchCount",
            "PaymentID",
            "MatchedPhone",
            "MatchedCustName",
            "AmountUGX",
            "PaymentStatus",
            "District",
            "Narration",
            "Added",
            "Note",
        ],
        ",",
        '"',
        "",
    );
    foreach ($search["rows"] as $r) {
        $input = [
            $r["line"],
            $r["phone"] !== "" ? "'" . $r["phone"] : "",
            $r["name"],
            $r["outcome"],
            $r["count"],
        ];
        if (!$r["count"]) {
            fputcsv(
                $out,
                array_map(
                    "ecCsvSafe",
                    array_merge($input, ["", "", "", "", "", "", "", "", $r["error"]]),
                ),
                ",",
                '"',
                "",
            );
            continue;
        }
        [$where, $types, $params] = ecSearchWhere($r, $search["mode"], $search["district"]);
        $stmt = ecQuery(
            "SELECT p.id,p.phone,p.cust_name,p.tran_amount,p.status,p.narration,p.created_at,d.name AS district_name FROM payments p LEFT JOIN districts d ON d.id=p.district_id WHERE " .
                $where .
                " ORDER BY p.id DESC",
            $types,
            $params,
        );
        $result = $stmt->get_result();
        while ($p = $result->fetch_assoc()) {
            fputcsv(
                $out,
                array_map(
                    "ecCsvSafe",
                    array_merge($input, [
                        $p["id"],
                        "'" . $p["phone"],
                        $p["cust_name"],
                        $p["tran_amount"],
                        $p["status"],
                        $p["district_name"],
                        $p["narration"],
                        $p["created_at"],
                        "",
                    ]),
                ),
                ",",
                '"',
                "",
            );
        }
        $result->free();
        $stmt->close();
    }
}
