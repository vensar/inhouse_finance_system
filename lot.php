<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
require_once __DIR__ . "/lot_service.php";
requireLogin();
$lotId = max(0, (int) ($_GET["id"] ?? 0));
$lot = ecPaymentLot($lotId);
if (!$lot) {
    http_response_code(404);
    exit("Payment lot not found.");
}
if (!isAdmin() && !ecUserCanAccessPaymentLot($lotId, (int) $_SESSION["user_id"])) {
    http_response_code(403);
    exit("This payment lot has not been allocated to your account.");
}
$error = "";
$notice = $_SESSION["lot_notice"] ?? "";
unset($_SESSION["lot_notice"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!verifyCSRFToken((string) ($_POST["csrf_token"] ?? ""))) {
            throw new DomainException("Session expired. Reload the page and try again.");
        }
        $action = (string) ($_POST["action"] ?? "upload_workbook");
        if (isAdmin()) {
            if ($action === "update_allocation") {
                ecSetPaymentLotAllocation($lotId, (string) ($_POST["allocation_mode"] ?? "ALL"), (array) ($_POST["allocated_users"] ?? []), (int) $_SESSION["user_id"]);
                $message = "Payment lot allocation updated.";
            } elseif (in_array($action, ["grant_amendment", "revoke_amendment"], true)) {
                ecSetPaymentUploadAmendment((int) ($_POST["upload_id"] ?? 0), $action === "grant_amendment", (int) $_SESSION["user_id"]);
                $message = $action === "grant_amendment" ? "Amendment permission granted to the sender." : "Amendment permission recalled.";
            } else {
                throw new DomainException("Choose a valid payment lot action.");
            }
            $_SESSION["lot_notice"] = $message;
            header("Location: lot?id=" . $lotId, true, 303);
            exit();
        }
        $uploadId = (int) ($_POST["upload_id"] ?? 0);
        if ($action === "add_entry") {
            ecAddPaymentEntry($uploadId, (int) $_SESSION["user_id"], (string) ($_POST["phone"] ?? ""), (string) ($_POST["cust_name"] ?? ""), (string) ($_POST["amount"] ?? ""), (string) ($_POST["narration"] ?? ""));
            $message = "Payment entry added.";
        } elseif ($action === "update_entry") {
            ecUpdatePaymentEntry((int) ($_POST["entry_id"] ?? 0), $uploadId, (int) $_SESSION["user_id"], (string) ($_POST["phone"] ?? ""), (string) ($_POST["cust_name"] ?? ""), (string) ($_POST["amount"] ?? ""), (string) ($_POST["narration"] ?? ""));
            $message = "Payment entry updated.";
        } elseif ($action === "delete_entry") {
            ecDeletePaymentEntry((int) ($_POST["entry_id"] ?? 0), $uploadId, (int) $_SESSION["user_id"]);
            $message = "Payment entry deleted.";
        } else {
            $file = $_FILES["excel_file"] ?? null;
            if (!$file || $file["error"] !== UPLOAD_ERR_OK || $file["size"] > 5 * 1024 * 1024 || strtolower(pathinfo($file["name"], PATHINFO_EXTENSION)) !== "xlsx") {
                throw new DomainException("Choose an .xlsx workbook up to 5 MB.");
            }
            $count = ecImportPaymentLotWorkbook($file["tmp_name"], $file["name"], $lotId, (int) $_SESSION["district_id"], (int) $_SESSION["user_id"]);
            $message = number_format($count) . " payment-list entries uploaded.";
        }
        $_SESSION["lot_notice"] = $message;
        header("Location: lot?id=" . $lotId . ($uploadId ? "&upload=" . $uploadId : ""), true, 303);
        exit();
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log("Lot update: " . $e->getMessage());
        $error = "The payment list could not be updated.";
    }
}$districtUsers = [];
$allocatedUserIds = [];
if (isAdmin()) {
    $districtUsers = ecQuery("SELECT u.id,u.full_name,u.username,d.name district_name FROM users u LEFT JOIN districts d ON d.id=u.district_id WHERE u.role='district_user' AND u.is_active=1 ORDER BY d.name,u.full_name")
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $allocatedUserIds = array_map(
        "intval",
        array_column(
            ecQuery("SELECT user_id FROM payment_lot_allocations WHERE lot_id=?", "i", [$lotId])
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC),
            "user_id",
        ),
    );
}
$params = [$lotId];
$types = "i";
$scope = "";
if (!isAdmin()) {
    $scope = " AND x.district_id=?";
    $params[] = (int) $_SESSION["district_id"];
    $types .= "i";
}
$uploads = ecQuery(
    "SELECT x.*,d.name district_name,u.full_name uploaded_by_name FROM payment_lot_uploads x JOIN districts d ON d.id=x.district_id LEFT JOIN users u ON u.id=x.uploaded_by WHERE x.lot_id=?" .
        $scope .
        " ORDER BY x.created_at DESC LIMIT 100",
    $types,
    $params,
)
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);
$entries = ecQuery(
    "SELECT e.*,d.name district_name FROM payment_lot_entries e JOIN districts d ON d.id=e.district_id WHERE e.lot_id=?" .
        (isAdmin() ? "" : " AND e.district_id=?") .
        " ORDER BY e.id DESC LIMIT 100",
    $types,
    $params,
)
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);
$selectedUploadId = max(0, (int) ($_GET["upload"] ?? 0));
$selectedUpload = $selectedUploadId ? ecPaymentUpload($selectedUploadId) : null;
if ($selectedUpload && ((int) $selectedUpload["lot_id"] !== $lotId || (!isAdmin() && (int) $selectedUpload["uploaded_by"] !== (int) $_SESSION["user_id"]))) {
    $selectedUpload = null;
    $selectedUploadId = 0;
}
$amendmentEntries = $selectedUpload
    ? ecQuery("SELECT * FROM payment_lot_entries WHERE upload_id=? ORDER BY id", "i", [$selectedUploadId])->get_result()->fetch_all(MYSQLI_ASSOC)
    : [];
$csrf = generateCSRFToken();
session_write_close();
ecHeader($lot["name"], "lots");
?>
<div class="page-heading"><div><div class="eyebrow">Payment lot</div><h1><?= ecEscape(
    $lot["name"],
) ?></h1><p class="muted"><?= ecEscape(
    $lot["description"] ?: "No description supplied.",
) ?></p></div><div class="d-flex gap-2 align-items-center"><span class="lot-status <?= strtolower(
    $lot["status"],
) ?>"><?= ecEscape(
    $lot["status"],
) ?></span><a class="btn btn-outline-secondary" href="lots">All lots</a></div></div>
<?php
if ($notice): ?><div class="alert alert-success" role="status"><?= ecEscape($notice) ?></div><?php endif;
if ($error): ?><div class="alert alert-danger" role="alert"><?= ecEscape($error) ?></div><?php endif;
?>
<?php if (isAdmin()): ?><section class="panel mb-4"><div class="panel-heading"><div><h2>User allocation</h2><p class="muted">Control which district users can view and upload to this lot.</p></div></div><form method="post" class="panel-body"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>"><input type="hidden" name="action" value="update_allocation"><fieldset class="lot-allocation"><legend class="form-label">Allocate this lot to</legend><label><input type="radio" name="allocation_mode" value="ALL" <?= $lot["allocation_mode"] === "ALL" ? "checked" : "" ?>> All district users</label><label><input type="radio" name="allocation_mode" value="SELECTED" <?= $lot["allocation_mode"] === "SELECTED" ? "checked" : "" ?>> Selected district users</label><div id="lot-user-picker" class="lot-user-picker" <?= $lot["allocation_mode"] === "ALL" ? "hidden" : "" ?>><label class="form-label" for="lot-user-filter">Find a user</label><input class="form-control mb-2" id="lot-user-filter" type="search" placeholder="Search by name, username, or district"><div class="lot-user-list"><?php foreach ($districtUsers as $districtUser): ?><label class="lot-user-option" data-search="<?= ecEscape(strtolower($districtUser["full_name"] . " " . $districtUser["username"] . " " . $districtUser["district_name"])) ?>"><input type="checkbox" name="allocated_users[]" value="<?= (int) $districtUser["id"] ?>" <?= in_array((int) $districtUser["id"], $allocatedUserIds, true) ? "checked" : "" ?>><span><strong><?= ecEscape($districtUser["full_name"]) ?></strong><small><?= ecEscape($districtUser["username"] . " · " . ($districtUser["district_name"] ?: "No district")) ?></small></span></label><?php endforeach; ?></div><div class="muted mt-2"><span id="lot-user-count"><?= count($allocatedUserIds) ?></span> users selected</div></div></fieldset><button class="btn btn-primary mt-3">Save allocation</button></form></section><script>(()=>{const modes=document.querySelectorAll('input[name="allocation_mode"]'),picker=document.getElementById('lot-user-picker'),filter=document.getElementById('lot-user-filter'),options=[...document.querySelectorAll('.lot-user-option')],count=document.getElementById('lot-user-count');function sync(){const selected=document.querySelector('input[name="allocation_mode"]:checked').value==='SELECTED';picker.hidden=!selected;options.forEach(option=>option.querySelector('input').disabled=!selected);count.textContent=options.filter(option=>option.querySelector('input').checked).length}modes.forEach(mode=>mode.addEventListener('change',sync));options.forEach(option=>option.querySelector('input').addEventListener('change',sync));filter.addEventListener('input',()=>{const query=filter.value.trim().toLowerCase();options.forEach(option=>option.hidden=!option.dataset.search.includes(query))});sync()})();</script><?php endif; ?>
<?php if (
    !isAdmin()
): ?><section class="panel mb-4"><div class="panel-heading"><div><h2>Upload district payment list</h2><p class="muted">The list will be assigned to your district and this payment lot.</p></div><a class="btn btn-outline-secondary" href="download_excel_template?type=payment">Download Excel template</a></div><?php if (
    $lot["status"] === "ACTIVE"
): ?><form method="post" enctype="multipart/form-data" class="panel-body search-row"><input type="hidden" name="csrf_token" value="<?= ecEscape(
    $csrf,
) ?>"><div><label class="form-label" for="excel_file">Completed Excel template</label><input class="form-control" type="file" id="excel_file" name="excel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div><button class="btn btn-primary">Upload payment list</button></form><?php else: ?><div class="panel-body"><div class="alert alert-warning mb-0">Uploads are closed because this payment lot is <?= strtolower(
    ecEscape($lot["status"]),
) ?>.</div></div><?php endif; ?></section><?php endif; ?>
<section class="panel mb-4"><div class="panel-heading"><h2>Upload history</h2><span class="muted"><?= number_format(count($uploads)) ?> shown</span></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Workbook</th><th>District</th><th>Uploaded by</th><th>Entries</th><th>Total · UGX</th><th>Amendment</th><th>Actions</th></tr></thead><tbody><?php foreach ($uploads as $upload): ?><tr><td><strong><?= ecEscape($upload["original_filename"]) ?></strong><div class="muted"><?= ecEscape(date("d M Y H:i", strtotime($upload["created_at"]))) ?></div></td><td data-label="District"><?= ecEscape($upload["district_name"]) ?></td><td data-label="Uploaded by"><?= ecEscape($upload["uploaded_by_name"] ?: "Former user") ?></td><td data-label="Entries"><?= number_format($upload["row_count"]) ?></td><td data-label="Total · UGX" class="money"><?= number_format($upload["total_amount"], 0) ?></td><td data-label="Amendment"><span class="lot-status <?= $upload["amendment_enabled"] ? "active" : "inactive" ?>"><?= $upload["amendment_enabled"] ? "GRANTED" : "LOCKED" ?></span></td><td><div class="lot-actions"><a class="btn btn-sm btn-outline-secondary" href="download_payment_list?upload=<?= (int) $upload["id"] ?>">Download clean copy</a><?php if (isAdmin()): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>"><input type="hidden" name="upload_id" value="<?= (int) $upload["id"] ?>"><button class="btn btn-sm <?= $upload["amendment_enabled"] ? "btn-outline-danger" : "btn-primary" ?>" name="action" value="<?= $upload["amendment_enabled"] ? "revoke_amendment" : "grant_amendment" ?>"><?= $upload["amendment_enabled"] ? "Recall amendment" : "Grant amendment" ?></button></form><?php elseif ($upload["amendment_enabled"] && $lot["status"] === "ACTIVE"): ?><a class="btn btn-sm btn-primary" href="lot?id=<?= $lotId ?>&upload=<?= (int) $upload["id"] ?>">Amend list</a><?php endif; ?></div></td></tr><?php endforeach;
if (!$uploads): ?><tr><td colspan="7" class="empty">No lists have been uploaded to this payment lot.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if ($selectedUpload && !isAdmin()): ?><section class="panel mb-4"><div class="panel-heading"><div><h2>Amend payment list</h2><p class="muted"><?= ecEscape($selectedUpload["original_filename"]) ?> · changes are recorded in the audit log.</p></div><a class="btn btn-outline-secondary" href="download_payment_list?upload=<?= $selectedUploadId ?>">Download clean copy</a></div><?php if ($selectedUpload["amendment_enabled"] && $selectedUpload["lot_status"] === "ACTIVE"): ?><div class="panel-body"><h3>Add new payment</h3><form method="post" class="search-row"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>"><input type="hidden" name="action" value="add_entry"><input type="hidden" name="upload_id" value="<?= $selectedUploadId ?>"><div><label class="form-label">Phone</label><input class="form-control" name="phone" placeholder="256788045372" pattern="256[0-9]{9}" required></div><div><label class="form-label">Customer name</label><input class="form-control" name="cust_name" maxlength="100" required></div><div><label class="form-label">Amount · UGX</label><input class="form-control" name="amount" type="number" min="0.01" step="0.01" required></div><div><label class="form-label">Narration</label><input class="form-control" name="narration" maxlength="500"></div><button class="btn btn-primary">Add payment</button></form></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Phone</th><th>Customer name</th><th>Amount · UGX</th><th>Narration</th><th>Actions</th></tr></thead><tbody><?php foreach ($amendmentEntries as $entry): ?><tr><form method="post"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>"><input type="hidden" name="upload_id" value="<?= $selectedUploadId ?>"><input type="hidden" name="entry_id" value="<?= (int) $entry["id"] ?>"><td data-label="Phone"><input class="form-control" name="phone" value="<?= ecEscape($entry["phone"]) ?>" pattern="256[0-9]{9}" required></td><td data-label="Customer name"><input class="form-control" name="cust_name" value="<?= ecEscape($entry["cust_name"]) ?>" maxlength="100" required></td><td data-label="Amount · UGX"><input class="form-control" name="amount" value="<?= ecEscape($entry["amount"]) ?>" type="number" min="0.01" step="0.01" required></td><td data-label="Narration"><input class="form-control" name="narration" value="<?= ecEscape($entry["narration"]) ?>" maxlength="500"></td><td><div class="lot-actions"><button class="btn btn-sm btn-primary" name="action" value="update_entry">Save</button><button class="btn btn-sm btn-outline-danger" name="action" value="delete_entry" formnovalidate onclick="return confirm('Delete this payment entry?')">Delete</button></div></td></form></tr><?php endforeach;
    if (!$amendmentEntries): ?><tr><td colspan="5" class="empty">This payment list has no entries. Use the form above to add one.</td></tr><?php endif; ?></tbody></table></div><?php else: ?><div class="panel-body"><div class="alert alert-warning mb-0">Amendment permission is locked. Ask an administrator to grant access.</div></div><?php endif; ?></section><?php endif; ?><section class="panel"><div class="panel-heading"><h2>Recent payment-list entries</h2><span class="muted">Latest 100</span></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Customer / phone</th><th>Amount · UGX</th><th>District</th><th>Narration</th></tr></thead><tbody><?php
foreach ($entries as $entry): ?><tr><td><strong><?= ecEscape(
    $entry["cust_name"],
) ?></strong><div><?= ecEscape(
    $entry["phone"],
) ?></div></td><td data-label="Amount · UGX" class="money"><?= number_format(
    $entry["amount"],
    0,
) ?></td><td data-label="District"><?= ecEscape(
    $entry["district_name"],
) ?></td><td data-label="Narration"><?= ecEscape($entry["narration"]) ?></td></tr><?php endforeach;
if (
    !$entries
): ?><tr><td colspan="4" class="empty">No payment-list entries are available.</td></tr><?php endif;
?></tbody></table></div></section><?php ecFooter(); ?>


