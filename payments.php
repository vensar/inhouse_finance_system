<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
requireLogin();
$failedOnly = $failedOnly ?? false;
$route = $failedOnly ? "failed_payments" : "payments";
$title = $failedOnly ? "Failed payments" : "Payments";
$phone = trim((string) ($_GET["phone"] ?? ""));
$name = trim((string) ($_GET["cust_name"] ?? ""));
$before = max(0, (int) ($_GET["before"] ?? 0));
$after = max(0, (int) ($_GET["after"] ?? 0));
$result = ecPaymentPage(
    $phone,
    $name,
    isAdmin() ? null : (int) $_SESSION["district_id"],
    $before,
    $after,
    $failedOnly ? "FAILED" : null,
);
$rows = $result["rows"];
$filters = ["phone" => $phone, "cust_name" => $name];
session_write_close();
ecHeader($title, $route);
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Payment records</div>
        <h1><?= $failedOnly ? "Find a failed payment" : "Find a payment" ?></h1>
        <p class="muted">
            <?= $failedOnly
                ? "Only failed payment records are shown. "
                : "" ?>Search by the beginning of a phone number or customer name.
            <?= isAdmin()
                ? "All districts are included."
                : "Includes your assigned district and UNCATEGORIZED records." ?>
        </p>
    </div>
</div>
<section class="panel">
    <form class="search-row" method="get" action="<?= ecEscape($route) ?>">
        <div>
            <label class="form-label" for="phone">Phone starts with</label>
            <input
                class="form-control"
                id="phone"
                name="phone"
                inputmode="tel"
                maxlength="20"
                value="<?= ecEscape($phone) ?>"
            />
        </div>
        <div>
            <label class="form-label" for="cust_name">Customer name starts with</label>
            <input
                class="form-control"
                id="cust_name"
                name="cust_name"
                maxlength="100"
                value="<?= ecEscape($name) ?>"
            />
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary">Search</button>
            <a class="btn btn-outline-secondary" href="<?= ecEscape($route) ?>">Clear</a>
        </div>
    </form>
    <div class="table-responsive" tabindex="0" aria-label="Payment search results">
        <table class="table mobile-records payment-records">
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Amount · UGX</th>
                    <th>Status</th>
                    <th>District</th>
                    <th>Narration</th>
                    <th>Added</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($rows as $p): ?>
                <tr>
                    <td>
                        <strong><?= ecEscape($p["cust_name"] ?: "Name not supplied") ?></strong>
                        <div><?= ecEscape($p["phone"]) ?></div>
                    </td>
                    <td class="money" data-label="Amount · UGX"><?= number_format(
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
                    <td class="money" data-label="Added"><?= ecEscape(
                        date("d M Y", strtotime($p["created_at"])),
                    ) ?></td>
                </tr>
                <?php endforeach;
if (!$rows): ?>
                <tr>
                    <td colspan="6" class="empty">
                        No records match this search.
                        <a href="<?= ecEscape($route) ?>">Reset search</a>
                    </td>
                </tr>
                <?php endif;
?>
            </tbody>
        </table>
    </div>
    <nav class="pagination-bar" aria-label="Payment pages">
        <span class="muted"><?= count($rows) ?> records on this page</span>
        <div class="d-flex gap-2">
            <?php
            if ($rows && $result["newer"]): ?>
            <a class="btn btn-outline-secondary" href="<?= ecEscape($route) ?>?<?= ecEscape(
                http_build_query($filters + ["after" => $rows[0]["id"]]),
            ) ?>">
                Previous
            </a>
            <?php endif;
if ($rows && $result["older"]): ?>
            <a class="btn btn-outline-secondary" href="<?= ecEscape($route) ?>?<?= ecEscape(
                http_build_query($filters + ["before" => end($rows)["id"]]),
            ) ?>">
                Next
            </a>
            <?php endif;
?>
        </div>
    </nav>
</section>
<?php ecFooter(); ?>
