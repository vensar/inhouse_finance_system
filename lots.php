<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
require_once __DIR__ . "/lot_service.php";
requireLogin();
$error = "";
$notice = $_SESSION["lot_notice"] ?? "";
unset($_SESSION["lot_notice"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!isAdmin()) {
            throw new DomainException("Only administrators can manage payment lots.");
        }
        if (!verifyCSRFToken((string) ($_POST["csrf_token"] ?? ""))) {
            throw new DomainException("Session expired. Reload the page and try again.");
        }
        $action = (string) ($_POST["action"] ?? "");
        if ($action === "create") {
            $id = ecCreatePaymentLot(
                (string) ($_POST["name"] ?? ""),
                (string) ($_POST["description"] ?? ""),
                (int) $_SESSION["user_id"],
                (string) ($_POST["allocation_mode"] ?? "ALL"),
                (array) ($_POST["allocated_users"] ?? []),
            );
            $_SESSION["lot_notice"] = "Payment lot created and activated.";
            header("Location: lot?id=" . $id, true, 303);
            exit();
        }
        $status =
            ["activate" => "ACTIVE", "deactivate" => "INACTIVE", "archive" => "ARCHIVED"][$action] ?? null;
        if (!$status) {
            throw new DomainException("Choose a valid payment lot action.");
        }
        ecSetPaymentLotStatus((int) ($_POST["lot_id"] ?? 0), $status, (int) $_SESSION["user_id"]);
        $_SESSION["lot_notice"] = "Payment lot status updated.";
        header("Location: lots", true, 303);
        exit();
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log("Payment lots: " . $e->getMessage());
        $error = "The payment lot could not be updated.";
    }
}
$district = isAdmin() ? null : (int) $_SESSION["district_id"];
$lots = ecPaymentLots($district, isAdmin() ? null : (int) $_SESSION["user_id"]);
$districtUsers = isAdmin()
    ? ecQuery("SELECT u.id,u.full_name,u.username,d.name district_name FROM users u LEFT JOIN districts d ON d.id=u.district_id WHERE u.role='district_user' AND u.is_active=1 ORDER BY d.name,u.full_name")
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC)
    : [];
$csrf = generateCSRFToken();
session_write_close();
ecHeader("Payment lots", "lots");
?>
<div class="page-heading"><div><div class="eyebrow">Payment preparation</div><h1>Payment lots</h1><p class="muted"><?= isAdmin()
    ? "Create and control payment collection periods."
    : "Upload your district payment list to an active lot." ?></p></div><?php if (
        !isAdmin()
    ): ?><a class="btn btn-outline-secondary" href="download_excel_template?type=payment">Download Excel template</a><?php endif; ?></div>
<?php
if ($notice): ?><div class="alert alert-success" role="status"><?= ecEscape($notice) ?></div><?php endif;
if ($error): ?><div class="alert alert-danger" role="alert"><?= ecEscape($error) ?></div><?php endif;
?>
<?php if (
    isAdmin()
): ?><section class="panel mb-4"><div class="panel-heading"><h2>Create payment lot</h2></div><form method="post" class="panel-body lot-create-form"><input type="hidden" name="csrf_token" value="<?= ecEscape(
    $csrf,
) ?>"><input type="hidden" name="action" value="create"><div><label class="form-label" for="name">Lot name</label><input class="form-control" id="name" name="name" maxlength="120" required></div><div><label class="form-label" for="description">Description</label><input class="form-control" id="description" name="description" maxlength="500"></div><fieldset class="lot-allocation"><legend class="form-label">Allocate this lot to</legend><label><input type="radio" name="allocation_mode" value="ALL" checked> All district users</label><label><input type="radio" name="allocation_mode" value="SELECTED"> Selected district users</label><div id="lot-user-picker" class="lot-user-picker" hidden><label class="form-label" for="lot-user-filter">Find a user</label><input class="form-control mb-2" id="lot-user-filter" type="search" placeholder="Search by name, username, or district"><div class="lot-user-list"><?php foreach ($districtUsers as $districtUser): ?><label class="lot-user-option" data-search="<?= ecEscape(strtolower($districtUser["full_name"] . " " . $districtUser["username"] . " " . $districtUser["district_name"])) ?>"><input type="checkbox" name="allocated_users[]" value="<?= (int) $districtUser["id"] ?>"><span><strong><?= ecEscape($districtUser["full_name"]) ?></strong><small><?= ecEscape($districtUser["username"] . " · " . ($districtUser["district_name"] ?: "No district")) ?></small></span></label><?php endforeach; ?></div><div class="muted mt-2"><span id="lot-user-count">0</span> users selected</div></div></fieldset><button class="btn btn-primary">Create active lot</button></form></section><script>(()=>{const modes=document.querySelectorAll('input[name="allocation_mode"]'),picker=document.getElementById('lot-user-picker'),filter=document.getElementById('lot-user-filter'),options=[...document.querySelectorAll('.lot-user-option')],count=document.getElementById('lot-user-count');function sync(){const selected=document.querySelector('input[name="allocation_mode"]:checked').value==='SELECTED';picker.hidden=!selected;options.forEach(option=>option.querySelector('input').disabled=!selected);count.textContent=options.filter(option=>option.querySelector('input').checked).length}modes.forEach(mode=>mode.addEventListener('change',sync));options.forEach(option=>option.querySelector('input').addEventListener('change',sync));filter.addEventListener('input',()=>{const query=filter.value.trim().toLowerCase();options.forEach(option=>option.hidden=!option.dataset.search.includes(query))});sync()})();</script><?php endif; ?>
<section class="panel"><div class="panel-heading"><h2><?= isAdmin()
    ? "All payment lots"
    : "Active payment lots" ?></h2><span class="muted"><?= number_format(
        count($lots),
    ) ?> lots</span></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Payment lot</th><th>Status</th><th>Uploads</th><th>Entries</th><th>Total · UGX</th><th>Created</th><?php if (
        isAdmin()
    ): ?><th>Manage</th><?php endif; ?></tr></thead><tbody><?php
foreach ($lots as $lot): ?><tr><td><strong><a href="lot?id=<?= (int) $lot["id"] ?>"><?= ecEscape(
    $lot["name"],
) ?></a></strong><div class="muted"><?= ecEscape(
    $lot["description"],
) ?></div><div class="muted"><?= $lot["allocation_mode"] === "ALL" ? "All district users" : "Selected users only" ?></div></td><td data-label="Status"><span class="lot-status <?= strtolower(
    $lot["status"],
) ?>"><?= ecEscape($lot["status"]) ?></span></td><td data-label="Uploads"><?= number_format(
    $lot["upload_count"],
) ?></td><td data-label="Entries"><?= number_format(
    $lot["entry_count"],
) ?></td><td data-label="Total · UGX" class="money"><?= number_format(
    $lot["total_amount"],
    0,
) ?></td><td data-label="Created"><?= ecEscape(date("d M Y", strtotime($lot["created_at"]))) ?></td><?php if (
    isAdmin()
): ?><td><form method="post" class="lot-actions"><input type="hidden" name="csrf_token" value="<?= ecEscape(
    $csrf,
) ?>"><input type="hidden" name="lot_id" value="<?= (int) $lot["id"] ?>"><?php
if (
    $lot["status"] === "ACTIVE"
): ?><button class="btn btn-sm btn-outline-secondary" name="action" value="deactivate">Deactivate</button><?php elseif (
    $lot["status"] === "INACTIVE"
): ?><button class="btn btn-sm btn-outline-secondary" name="action" value="activate">Activate</button><?php endif;
    if (
        $lot["status"] !== "ARCHIVED"
    ): ?><button class="btn btn-sm btn-outline-danger" name="action" value="archive">Archive</button><?php endif;
    ?></form></td><?php endif; ?></tr><?php endforeach;
if (!$lots): ?><tr><td colspan="7" class="empty"><?= isAdmin()
    ? "No payment lots have been created."
    : "There are no active payment lots." ?></td></tr><?php endif;
?></tbody></table></div></section><?php ecFooter(); ?>
