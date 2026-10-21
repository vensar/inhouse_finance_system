<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
require_once __DIR__ . "/bulk_search_service.php";
requireLogin();
header("Cache-Control: no-store");
$owner = (int) $_SESSION["user_id"];
$district = isAdmin() ? null : (int) $_SESSION["district_id"];
$role = $_SESSION["role"];
$error = "";
$mode = $_POST["mode"] ?? "both";
if (isset($_GET["template"])) {
    header("Content-Type: text/csv; charset=utf-8");
    header('Content-Disposition: attachment; filename="bulk_search_template.csv"');
    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ["Phone", "CustName"], ",", '"', "");
    fputcsv($out, ["256700000000", "EXAMPLE CUSTOMER"], ",", '"', "");
    fclose($out);
    exit();
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!is_string($_POST["csrf_token"] ?? null) || !verifyCSRFToken($_POST["csrf_token"])) {
            throw new DomainException(
                "Session expired or upload too large. Reload this page and use a CSV up to 2 MB.",
            );
        }
        if (!in_array($mode, ["both", "either"], true)) {
            throw new DomainException("Choose a valid matching option.");
        }
        $file = $_FILES["csv_file"] ?? null;
        if (
            !$file ||
            $file["error"] !== UPLOAD_ERR_OK ||
            $file["size"] > 2 * 1024 * 1024 ||
            strtolower(pathinfo($file["name"], PATHINFO_EXTENSION)) !== "csv"
        ) {
            throw new DomainException("Choose a CSV file up to 2 MB.");
        }
        $result = ecRunBulkSearch(ecReadSearchCsv($file["tmp_name"]), $mode, $district);
        $id = bin2hex(random_bytes(12));
        $_SESSION["bulk_search"] = $result + [
            "id" => $id,
            "owner" => $owner,
            "role" => $role,
            "district" => $district,
            "mode" => $mode,
            "created" => time(),
        ];
        header("Location: bulk_search?search=" . $id, true, 303);
        exit();
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log("Bulk search: " . $e->getMessage());
        $error = "Search could not be completed. Please try again.";
    }
}
$search = null;
$id = (string) ($_GET["search"] ?? "");
$saved = $_SESSION["bulk_search"] ?? null;
if ($id !== "") {
    if (
        $saved &&
        hash_equals($saved["id"], $id) &&
        $saved["owner"] === $owner &&
        $saved["role"] === $role &&
        $saved["district"] === $district &&
        $saved["created"] > time() - 1800
    ) {
        $search = $saved;
    } else {
        $error =
            "These results have expired or were replaced by a newer search. Upload the file again.";
    }
}
if (isset($_GET["download"]) && $search) {
    if ($search["totals"]["payments"] > 50000) {
        $error =
            "This search exceeds 50,000 payment matches. Narrow the input or use smaller files before downloading.";
    } else {
        session_write_close();
        set_time_limit(120);
        header("Content-Type: text/csv; charset=utf-8");
        header('Content-Disposition: attachment; filename="bulk_search_results.csv"');
        $out = fopen("php://output", "w");
        ecExportSearch($search, $out);
        fclose($out);
        exit();
    }
}
$csrf = generateCSRFToken();
session_write_close();
ecHeader("Bulk search", "bulk_search");
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Payment verification</div>
        <h1>Bulk search</h1>
        <p class="muted">Find payment records for a list of phone numbers and customer names.</p>
    </div>
    <a class="btn btn-outline-secondary" href="bulk_search?template=1">Download CSV template</a>
</div>
<?php if ($error): ?>
<div class="alert alert-danger" role="alert"><?= ecEscape($error) ?></div>
<?php endif; ?>
<section class="panel mb-4">
    <form method="post" action="bulk_search" enctype="multipart/form-data" class="search-row">
        <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>" />
        <div>
            <label for="csv_file" class="form-label">Phone and CustName CSV</label>
            <input
                type="file"
                id="csv_file"
                name="csv_file"
                class="form-control"
                accept=".csv,text/csv"
                required
            />
        </div>
        <div>
            <label for="mode" class="form-label">Match each row using</label>
            <select id="mode" name="mode" class="form-select">
                <option value="both" <?= $mode === "both"
                    ? "selected"
                    : "" ?>>Both fields when supplied</option>
                <option value="either" <?= $mode === "either"
                    ? "selected"
                    : "" ?>>Either phone or customer name</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Search CSV</button>
    </form>
    <div class="panel-note">
        Up to 1,000 rows and 2 MB. Keep both column headings; a row may supply either field or both.
        Matches use complete values; names are case-insensitive. Phone formatting (+, spaces,
        parentheses, hyphens) is removed, but country codes are not added. <?= $district === null
            ? "Administrators search all districts."
            : "Your results include your assigned district and UNCATEGORIZED records only." ?> A new
        search replaces your previous results, which expire after 30 minutes.
    </div>
</section>
<?php if ($search):

    $totals = $search["totals"];
    $base = "bulk_search?search=" . urlencode($id);
    ?>
<section class="metrics" aria-label="Search summary">
    <?php foreach (
        [
            "submitted" => "Submitted rows",
            "matched" => "Rows with matches",
            "unmatched" => "Rows without matches",
            "invalid" => "Invalid rows",
        ] as $key => $label
    ): ?>
    <article class="metric">
        <div class="metric-label"><?= $label ?></div>
        <div class="metric-value"><?= number_format($totals[$key]) ?></div>
    </article>
    <?php endforeach; ?>
</section>
<div class="page-heading">
    <p class="muted">
        <?= number_format(
            $totals["payments"],
        ) ?> matching payment records across submitted rows · <?= $search["mode"] === "both"
     ? "All supplied fields must match."
     : "Either supplied field may match." ?>
        Repeated input rows can count the same payment more than once. Amounts include all statuses.
    </p>
    <a class="btn btn-outline-secondary" href="<?= ecEscape(
        $base . "&download=1",
    ) ?>">Download results CSV</a>
</div>
<?php if (
    isset($_GET["row"]) &&
    ctype_digit((string) $_GET["row"]) &&
    isset($search["rows"][(int) $_GET["row"]])
):

    $index = (int) $_GET["row"];
    $input = $search["rows"][$index];
    $matches = $input["count"]
        ? ecSearchMatches($input, $search["mode"], $district, max(0, (int) ($_GET["before"] ?? 0)))
        : [];
    $more = count($matches) > 50;
    if ($more) {
        array_pop($matches);
    }
    ?>
<section class="panel mb-4">
    <div class="panel-heading">
        <div>
            <h2>CSV row <?= $input["line"] ?> · <?= ecEscape($input["outcome"]) ?></h2>
            <p class="muted">
                <?= ecEscape($input["phone"]) ?> · <?= ecEscape(
                    $input["name"],
                ) ?> · <?= number_format($input["count"]) ?> matches
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= ecEscape($base) ?>">All search rows</a>
    </div>
    <div class="table-responsive">
        <table class="table mobile-records payment-records">
            <thead>
                <tr>
                    <th>Customer / phone</th>
                    <th>Amount · UGX</th>
                    <th>Status</th>
                    <th>District</th>
                    <th>Narration</th>
                    <th>Added</th>
                </tr>
            </thead>
            <tbody>
                <?php
                               foreach ($matches as $p): ?>
                <tr>
                    <td>
                        <strong><?= ecEscape($p["cust_name"] ?: "Name not supplied") ?></strong>
                        <div><?= ecEscape($p["phone"]) ?></div>
                    </td>
                    <td data-label="Amount · UGX" class="money"><?= number_format(
                        $p["tran_amount"],
                        0,
                    ) ?></td>
                    <td data-label="Status">
                        <span class="status <?= ecEscape($p["status"]) ?>"><?= ecEscape(
                            $p["status"],
                        ) ?></span>
                    </td>
                    <td data-label="District"><?= ecEscape($p["district_name"]) ?></td>
                    <td data-label="Narration" class="narration"><?= ecEscape(
                        $p["narration"],
                    ) ?></td>
                    <td data-label="Added"><?= ecEscape($p["created_at"]) ?></td>
                </tr>
                <?php endforeach;
    if (!$matches): ?>
                <tr><td colspan="6" class="empty"><?= ecEscape(
                    $input["error"] ?: "No matching payments in your accessible records.",
                ) ?></td></tr>
                <?php endif;
    ?>
            </tbody>
        </table>
    </div>
    <div class="pagination-bar">
        <a href="<?= ecEscape($base . "&row=" . $index) ?>">First matches</a>
        <?php if ($more): ?>
        <a class="btn btn-outline-secondary" href="<?= ecEscape(
            $base . "&row=" . $index . "&before=" . end($matches)["id"],
        ) ?>">Next 50</a>
        <?php endif; ?>
    </div>
</section>
<?php
else:

    $page = max(1, min((int) ($_GET["page"] ?? 1), (int) ceil(count($search["rows"]) / 25)));
    $offset = ($page - 1) * 25;
    ?>
<section class="panel">
    <div class="panel-heading">
        <h2>Results by CSV row</h2>
        <span class="muted">Page <?= $page ?> of <?= (int) ceil(
            count($search["rows"]) / 25,
        ) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table mobile-records">
            <thead>
                <tr>
                    <th>Submitted details</th>
                    <th>Matched customer names</th>
                    <th>Outcome</th>
                    <th>Matches</th>
                    <th>Total · UGX</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($search["rows"], $offset, 25, true) as $index => $r): ?>
                <tr>
                    <td>
                        <strong>Row <?= $r["line"] ?> · <?= ecEscape(
                            $r["name"] ?: "Phone only",
                        ) ?></strong>
                        <div><?= ecEscape($r["phone"]) ?></div>
                    </td>
                    <td data-label="Matched customer names">
                        <?php if (!empty($r["matched_names"])):
                            foreach ($r["matched_names"] as $matchedName): ?>
                        <div><?= ecEscape($matchedName ?: "no name") ?></div>
                        <?php endforeach;
                            if (!empty($r["more_names"])): ?>
                        <small class="muted">More names in View matches</small>
                        <?php endif;
                        elseif ($r["count"]): ?>
                        <a href="<?= ecEscape($base . "&row=" . $index) ?>">View customer names</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="Outcome">
                        <?= ecEscape($r["outcome"]) ?>
                        <?php if ($r["error"]): ?>
                        <div class="muted"><?= ecEscape($r["error"]) ?></div>
                        <?php endif;
                    ?>
                    </td>
                    <td data-label="Matches"><?= number_format($r["count"]) ?></td>
                    <td data-label="Total · UGX" class="money"><?= number_format(
                        $r["amount"],
                        0,
                    ) ?></td>
                    <td>
                        <?php if ($r["count"]): ?>
                        <a href="<?= ecEscape($base . "&row=" . $index) ?>">View matches</a>
                        <div class="muted">
                            <?= number_format($r["success"]) ?> successful · <?= number_format(
                                $r["failed"],
                            ) ?> failed
                        </div>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav class="pagination-bar" aria-label="Search result pages">
        <span class="muted">25 submitted rows per page</span>
        <div class="d-flex gap-2">
            <?php
                                       if ($page > 1): ?>
            <a class="btn btn-outline-secondary" href="<?= ecEscape(
                $base . "&page=" . ($page - 1),
            ) ?>">Previous</a>
            <?php endif;
if ($offset + 25 < count($search["rows"])): ?>
            <a class="btn btn-outline-secondary" href="<?= ecEscape(
                $base . "&page=" . ($page + 1),
            ) ?>">Next</a>
            <?php endif;
?>
        </div>
    </nav>
</section>
<?php
endif;
endif; ?>
<script>
    document.querySelector("form").addEventListener("submit", function () {
        const button = this.querySelector("button");
        button.disabled = true;
        button.textContent = "Searching…";
    });
</script>
<?php ecFooter(); ?>
