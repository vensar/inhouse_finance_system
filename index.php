<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
requireLogin();
$role = $_SESSION["role"];
$userData = getUserData($_SESSION["user_id"]);
$bounds = ecQuery("SELECT DATE(MIN(created_at)) first_date FROM payments")->get_result()->fetch_assoc();
$validDate = static function ($value): bool {
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", (string) $value);
    return $date && $date->format("Y-m-d") === $value;
};
$from = $validDate($_GET["from"] ?? "") ? $_GET["from"] : ($bounds["first_date"] ?: date("Y-m-01"));
$to = $validDate($_GET["to"] ?? "") ? $_GET["to"] : date("Y-m-d");
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$stats = ecDashboard($_SESSION["user_id"], $role, $_SESSION["district_id"], $from, $to);
$rate = $stats["total_payments"]
    ? (100 * $stats["success_payments"]) / $stats["total_payments"]
    : 0;
session_write_close();
ecHeader("Overview", "index");
$periodLabel = date("d M Y", strtotime($from)) . " – " . date("d M Y", strtotime($to));
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Operations overview</div>
        <h1>Dashboard</h1>
        <p class="muted">
            Welcome, <?= ecEscape(
                $_SESSION["full_name"],
            ) ?>. Figures below cover <?= $periodLabel ?>.
        </p>
    </div>
    <span class="role-tag"><?= $role === "admin"
        ? "Administrator"
        : ecEscape($userData["district_name"] ?? "District user") ?></span>
</div>
<section class="panel dashboard-filter-panel mb-4">
    <form method="get" class="dashboard-period-form">
        <div><label class="form-label" for="from">From date</label><input class="form-control" id="from" name="from" type="date" value="<?= ecEscape($from) ?>" max="<?= ecEscape($to) ?>" required></div>
        <div><label class="form-label" for="to">To date</label><input class="form-control" id="to" name="to" type="date" value="<?= ecEscape($to) ?>" min="<?= ecEscape($from) ?>" required></div>
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply period</button>
        <a class="btn btn-outline-secondary" href="index">All available data</a>
        <div class="period-summary"><i class="bi bi-calendar3"></i><span><strong>Selected period</strong><small><?= $periodLabel ?></small></span></div>
    </form>
</section>
<section class="metrics" aria-label="Payment summary">
    <article class="metric">
        <div class="metric-label">Total payment records</div>
        <div class="metric-value"><?= number_format($stats["total_payments"]) ?></div>
        <div class="metric-foot">All recorded payment statuses</div>
    </article>
    <article class="metric">
        <div class="metric-label">Successful payments</div>
        <div class="metric-value"><?= number_format($stats["success_payments"]) ?></div>
        <div class="metric-foot success-text"><?= number_format(
            $rate,
            1,
        ) ?>% of payment records</div>
    </article>
    <article class="metric amount">
        <div class="metric-label">Total amount · UGX</div>
        <div class="metric-value"><?= number_format($stats["total_amount"], 0) ?></div>
        <div class="metric-foot">Recorded amount across all statuses</div>
    </article>
    <article class="metric">
        <div class="metric-label">Failed payments</div>
        <div class="metric-value"><?= number_format($stats["failed_payments"]) ?></div>
        <div class="metric-foot danger-text">Review these records in Payments</div>
    </article>
</section>
<section class="dashboard-feature-section" aria-labelledby="system-features">
    <div class="section-heading"><div><div class="eyebrow">System activity</div><h2 id="system-features">Operational modules</h2></div><span class="muted">Updated for <?= $periodLabel ?></span></div>
    <div class="dashboard-feature-grid">
        <a class="feature-card" href="lots"><span class="feature-icon"><i class="bi bi-collection"></i></span><div><strong>Payment lots</strong><span><?= number_format((int) $stats["active_lots"]) ?> active lots</span><small><?= number_format((int) $stats["lot_activity"]["uploads"]) ?> lists · <?= number_format((int) $stats["lot_activity"]["entries"]) ?> entries</small></div><i class="bi bi-arrow-right"></i></a>
        <a class="feature-card" href="telecom"><span class="feature-icon"><i class="bi bi-phone"></i></span><div><strong>Telecom verification</strong><span><?= number_format((int) $stats["telecom_activity"]["completed"]) ?> completed</span><small><?= number_format((int) $stats["telecom_activity"]["cache_hits"]) ?> saved results reused</small></div><i class="bi bi-arrow-right"></i></a>
        <a class="feature-card" href="bulk_search"><span class="feature-icon"><i class="bi bi-search"></i></span><div><strong>Bulk payment search</strong><span>Search names and phones</span><small>Upload a template for detailed results</small></div><i class="bi bi-arrow-right"></i></a>
        <a class="feature-card" href="chat"><span class="feature-icon"><i class="bi bi-chat-dots"></i></span><div><strong>Messages</strong><span><?= number_format((int) $stats["unread_messages"]) ?> unread</span><small>District and administrator communication</small></div><i class="bi bi-arrow-right"></i></a>
        <?php if ($role === "admin"): ?>
        <a class="feature-card" href="accountability"><span class="feature-icon"><i class="bi bi-receipt-cutoff"></i></span><div><strong>Accountability</strong><span>UGX <?= number_format((float) $stats["accountability"]["accounted"], 0) ?> accounted</span><small>UGX <?= number_format((float) $stats["accountability"]["allocated"], 0) ?> EFTs sent</small></div><i class="bi bi-arrow-right"></i></a>
        <a class="feature-card" href="users"><span class="feature-icon"><i class="bi bi-people"></i></span><div><strong>User management</strong><span><?= number_format((int) $stats["active_users"]) ?> active users</span><small>Roles, offices and access control</small></div><i class="bi bi-arrow-right"></i></a>
        <?php endif; ?>
    </div>
</section>
<div class="dashboard-grid" <?= $role !== "admin" ? 'style="grid-template-columns:1fr"' : "" ?>>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Recent payments</h2>
                <p class="muted">Latest 10 records in the selected period</p>
            </div>
            <a class="btn btn-outline-secondary" href="payments">View payments</a>
        </div>
        <div class="table-responsive" tabindex="0" aria-label="Recent payment records">
            <table class="table mobile-records">
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Amount · UGX</th>
                        <th>Status</th>
                        <th>District</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats["recent_payments"] as $p): ?>
                    <tr>
                        <td>
                            <strong><?= ecEscape($p["cust_name"] ?: "Name not supplied") ?></strong>
                            <div class="muted"><?= ecEscape($p["phone"]) ?></div>
                        </td>
                        <td class="money" data-label="Amount � UGX"><?= number_format(
                            $p["tran_amount"],
                            0,
                        ) ?></td>
                        <td data-label="Status">
                            <span class="status <?= ecEscape($p["status"]) ?>"><?= ecEscape(
                                $p["status"],
                            ) ?></span>
                        </td>
                        <td data-label="District"><?= ecEscape($p["district_name"]) ?></td>
                    </tr>
                    <?php endforeach; ?> <?php if (!$stats["recent_payments"]): ?>
                    <tr><td colspan="4" class="empty">No payment records in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-note">
            <a href="chat">Open messages</a>
            · <?= number_format($stats["unread_messages"]) ?> unread
        </div>
    </section>
    <?php if ($role === "admin"): ?>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Top 10 paid districts</h2>
                <p class="muted">Ranked by successful payment amount · UGX</p>
            </div>
        </div>
        <ol class="rank-list">
            <?php
                                        $max = $stats["by_district"][0]["total_amount"] ?? 0;
        foreach ($stats["by_district"] as $i => $d): ?>
            <li class="rank-row">
                <span class="rank-number"><?= str_pad($i + 1, 2, "0", STR_PAD_LEFT) ?></span>
                <div>
                    <div class="rank-copy">
                        <strong><?= ecEscape($d["name"]) ?></strong>
                        <span class="money"><?= number_format($d["total_amount"], 0) ?></span>
                    </div>
                    <div class="rank-track" aria-hidden="true">
                        <span style="width: <?= $max > 0
                        ? round((100 * $d["total_amount"]) / $max, 2)
                        : 0 ?>%"></span>
                    </div>
                </div>
            </li>
            <?php endforeach;
        ?>
        </ol>
        <?php if (!$stats["by_district"]): ?>
        <p class="empty">No successful district payments yet.</p>
        <?php endif; ?>
        <div class="panel-note">
            <span>Uncategorized records excluded.</span>
            <a href="district_performance?from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>">View all district performance <i class="bi bi-arrow-right"></i></a>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php ecFooter(); ?>
