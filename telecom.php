<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
require_once __DIR__ . "/telecom_service.php";
requireLogin();
$allowed = isAdmin() || ecTelecomAccess((int) $_SESSION["user_id"]);
$error = "";
$notice = $_SESSION["telecom_notice"] ?? "";
unset($_SESSION["telecom_notice"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (isAdmin()) {
            throw new DomainException("District users submit telecom verification workbooks.");
        }
        if (!$allowed) {
            throw new DomainException(
                "Your telecom verification access is disabled. Ask an administrator to grant access.",
            );
        }
        if (!verifyCSRFToken((string) ($_POST["csrf_token"] ?? ""))) {
            throw new DomainException("Session expired. Reload the page and try again.");
        }
        $file = $_FILES["excel_file"] ?? null;
        if (
            !$file ||
            $file["error"] !== UPLOAD_ERR_OK ||
            $file["size"] > 5 * 1024 * 1024 ||
            strtolower(pathinfo($file["name"], PATHINFO_EXTENSION)) !== "xlsx"
        ) {
            throw new DomainException("Choose an .xlsx workbook up to 5 MB.");
        }
        $count = ecQueueTelecomWorkbook(
            $file["tmp_name"],
            $file["name"],
            (string) ($_POST["provider"] ?? "AUTO"),
            (int) $_SESSION["district_id"],
            (int) $_SESSION["user_id"],
        );
        $_SESSION["telecom_notice"] =
            number_format($count) .
            " records queued. They will remain pending until MTN/Airtel integration is enabled.";
        header("Location: telecom", true, 303);
        exit();
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log("Telecom verification: " . $e->getMessage());
        $error = "The verification workbook could not be queued.";
    }
}
$reportTo = (string) ($_GET["to"] ?? date("Y-m-d"));
$reportFrom = (string) ($_GET["from"] ?? date("Y-m-01"));
try {
    $usage = ecTelecomUsage(isAdmin() ? null : (int) $_SESSION["user_id"], $reportFrom, $reportTo);
} catch (DomainException $usageError) {
    $error = $usageError->getMessage();
    $reportTo = date("Y-m-d");
    $reportFrom = date("Y-m-01");
    $usage = ecTelecomUsage(isAdmin() ? null : (int) $_SESSION["user_id"], $reportFrom, $reportTo);
}$batches = ecTelecomBatches(isAdmin() ? null : (int) $_SESSION["user_id"]);
$selected = max(0, (int) ($_GET["batch"] ?? 0));
$entries = $selected ? ecTelecomBatchEntries($selected, isAdmin() ? null : (int) $_SESSION["user_id"]) : [];
$csrf = generateCSRFToken();
session_write_close();
ecHeader("Telecom verification", "telecom");
?>
<div class="page-heading"><div><div class="eyebrow">MTN and Airtel</div><h1>Telecom name verification</h1><p class="muted">Submit phone numbers and customer names for provider verification and matching scores.</p></div><div class="d-flex gap-2"><?php if (
    isAdmin()
): ?><a class="btn btn-primary" href="telecom_access">Manage API access</a><?php else: ?><a class="btn btn-outline-secondary" href="download_excel_template?type=telecom">Download Excel template</a><?php endif; ?></div></div>
<div class="alert alert-warning"><strong>Integration pending.</strong> Workbooks are securely queued, but no request is sent to MTN or Airtel until provider credentials and endpoints are configured. Once enabled, a phone with a stored provider response is removed from the outbound request list and its saved result is reused.</div>
<section class="panel mb-4"><div class="panel-heading"><div><h2>Validation usage</h2><p class="muted">Provider calls and saved-result reuse for the selected period.</p></div></div><form method="get" class="panel-body search-row"><div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= ecEscape($reportFrom) ?>" required></div><div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= ecEscape($reportTo) ?>" required></div><button class="btn btn-outline-secondary">Apply period</button></form><div class="panel-body pt-0"><div class="metrics"><article class="metric"><div class="metric-label">Telecom validations completed</div><div class="metric-value"><?= number_format((int) ($usage["validated_numbers"] ?? 0)) ?></div><div class="metric-foot">Unique numbers with provider feedback</div></article><article class="metric"><div class="metric-label">Billable API calls</div><div class="metric-value"><?= number_format((int) ($usage["billable_calls"] ?? 0)) ?></div><div class="metric-foot"><?= number_format((int) ($usage["mtn_calls"] ?? 0)) ?> MTN · <?= number_format((int) ($usage["airtel_calls"] ?? 0)) ?> Airtel</div></article><article class="metric"><div class="metric-label">Saved results reused</div><div class="metric-value"><?= number_format((int) ($usage["cached_results"] ?? 0)) ?></div><div class="metric-foot">Provider calls avoided</div></article><article class="metric"><div class="metric-label">Records submitted</div><div class="metric-value"><?= number_format((int) ($usage["submitted_records"] ?? 0)) ?></div><div class="metric-foot"><?= number_format((int) ($usage["unique_phones"] ?? 0)) ?> unique phone numbers</div></article></div></div></section>
<?php
if ($notice): ?><div class="alert alert-success" role="status"><?= ecEscape($notice) ?></div><?php endif;
if ($error): ?><div class="alert alert-danger" role="alert"><?= ecEscape($error) ?></div><?php endif;
?>
<?php if (
    !isAdmin()
): ?><section class="panel mb-4"><div class="panel-heading"><div><h2>Submit verification batch</h2><p class="muted">Access status: <strong><?= $allowed
    ? "Granted"
    : "Not granted" ?></strong></p></div></div><?php if (
        $allowed
    ): ?><form method="post" enctype="multipart/form-data" class="panel-body search-row"><input type="hidden" name="csrf_token" value="<?= ecEscape(
        $csrf,
    ) ?>"><div><label class="form-label" for="excel_file">Phone and customer name workbook</label><input class="form-control" type="file" id="excel_file" name="excel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div><div><label class="form-label" for="provider">Provider route</label><select class="form-select" id="provider" name="provider"><option value="AUTO">Auto detect</option><option value="MTN">MTN</option><option value="AIRTEL">Airtel</option></select></div><button class="btn btn-primary">Queue verification</button></form><?php else: ?><div class="panel-body"><p class="mb-0">An administrator must grant your account access before you can submit a workbook.</p></div><?php endif; ?></section><?php endif; ?>
<section class="panel mb-4"><div class="panel-heading"><h2><?= isAdmin()
        ? "All verification batches"
        : "Your verification batches" ?></h2><span class="muted"><?= number_format(
            count($batches),
        ) ?> shown</span></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Batch</th><th>District</th><th>Provider</th><th>Records</th><th>Status</th><th>Matches</th><th>Submitted</th></tr></thead><tbody><?php
 foreach ($batches as $batch): ?><tr><td><strong><a href="telecom?batch=<?= (int) $batch[
        "id"
] ?>"><?= ecEscape($batch["original_filename"]) ?></a></strong><div class="muted"><?= ecEscape(
    $batch["uploaded_by_name"] ?: "Former user",
) ?></div></td><td data-label="District"><?= ecEscape(
    $batch["district_name"],
) ?></td><td data-label="Provider"><?= ecEscape(
    $batch["provider_requested"],
) ?></td><td data-label="Records"><?= number_format(
    $batch["row_count"],
) ?></td><td data-label="Status"><span class="lot-status <?= strtolower($batch["status"]) ?>"><?= ecEscape(
    $batch["status"],
) ?></span></td><td data-label="Matches"><?= number_format(
    $batch["matched"] ?? 0,
) ?> exact · <?= number_format(
    $batch["partial_matches"] ?? 0,
) ?> partial</td><td data-label="Submitted"><?= ecEscape(
    date("d M Y H:i", strtotime($batch["created_at"])),
) ?></td></tr><?php endforeach;
if (
    !$batches
): ?><tr><td colspan="7" class="empty">No verification batches have been submitted.</td></tr><?php endif;
?></tbody></table></div></section>
<?php
if (
    $selected
): ?><section class="panel"><div class="panel-heading"><h2>Verification details</h2><span class="muted">Up to 500 records</span></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Submitted details</th><th>Provider</th><th>Registered name</th><th>Match</th><th>Status</th></tr></thead><tbody><?php
foreach ($entries as $entry): ?><tr><td><strong><?= ecEscape(
    $entry["submitted_name"],
) ?></strong><div><?= ecEscape($entry["phone"]) ?></div></td><td data-label="Provider"><?= ecEscape(
    $entry["provider"],
) ?></td><td data-label="Registered name"><?= ecEscape(
    $entry["registered_name"] ?: "Pending provider response",
) ?></td><td data-label="Match"><?= $entry["match_percentage"] === null
    ? "—"
    : number_format($entry["match_percentage"], 1) .
        "%" ?></td><td data-label="Status"><span class="lot-status <?= strtolower(
            $entry["match_status"],
        ) ?>"><?= ecEscape($entry["match_status"]) ?></span></td></tr><?php endforeach;
    if (
        !$entries
    ): ?><tr><td colspan="5" class="empty">This batch is unavailable or contains no entries.</td></tr><?php endif;
    ?></tbody></table></div></section><?php endif;
ecFooter();
?>

