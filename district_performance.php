<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
requireAdmin();
$validDate = static function ($value): bool {
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", (string) $value);
    return $date && $date->format("Y-m-d") === $value;
};
$bounds = ecQuery("SELECT DATE(MIN(created_at)) first_date FROM payments")->get_result()->fetch_assoc();
$from = $validDate($_GET["from"] ?? "") ? $_GET["from"] : ($bounds["first_date"] ?: date("Y-m-01"));
$to = $validDate($_GET["to"] ?? "") ? $_GET["to"] : date("Y-m-d");
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$end = (new DateTimeImmutable($to))->modify("+1 day")->format("Y-m-d");
$search = trim((string) ($_GET["q"] ?? ""));
$requestedPerPage = (int) ($_GET["per_page"] ?? 25);
$perPage = in_array($requestedPerPage, [10,25,50,100,250], true) ? $requestedPerPage : 25;
$page = max(1, (int) ($_GET["page"] ?? 1));
$where = "p.created_at>=? AND p.created_at<? AND d.name<>'UNCATEGORIZED'";
$types = "ss";
$params = [$from, $end];
if ($search !== "") {
    $where .= " AND (d.name LIKE ? OR d.region LIKE ?)";
    $like = "%" . $search . "%";
    $types .= "ss";
    array_push($params, $like, $like);
}
$count = (int) (ecQuery("SELECT COUNT(DISTINCT d.id) n FROM payments p JOIN districts d ON d.id=p.district_id WHERE " . $where, $types, $params)->get_result()->fetch_assoc()["n"] ?? 0);
$pages = max(1, (int) ceil($count / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$rows = ecQuery("SELECT d.id,d.name,d.region,COUNT(*) total_records,SUM(p.status='SUCCESS') successful,SUM(p.status='FAILED') failed,SUM(p.status='PENDING') pending,SUM(IF(p.status='SUCCESS',p.tran_amount,0)) paid_amount,SUM(p.tran_amount) total_amount,100*SUM(p.status='SUCCESS')/NULLIF(COUNT(*),0) success_rate FROM payments p JOIN districts d ON d.id=p.district_id WHERE " . $where . " GROUP BY d.id,d.name,d.region ORDER BY paid_amount DESC,d.name LIMIT ?,?", $types . "ii", array_merge($params, [$offset,$perPage]))->get_result()->fetch_all(MYSQLI_ASSOC);
$summary = ecQuery("SELECT COUNT(*) total_records,SUM(p.status='SUCCESS') successful,SUM(p.status='FAILED') failed,SUM(IF(p.status='SUCCESS',p.tran_amount,0)) paid_amount FROM payments p JOIN districts d ON d.id=p.district_id WHERE " . $where, $types, $params)->get_result()->fetch_assoc();
$query = $_GET;
$query["from"] = $from;
$query["to"] = $to;
$query["per_page"] = $perPage;
session_write_close();
ecHeader("District performance", "index");
?>
<div class="page-heading"><div><div class="eyebrow">Payment performance</div><h1>All district performance</h1><p class="muted">Compare successful, failed and pending payment records by district.</p></div><a class="btn btn-outline-secondary" href="index?from=<?= urlencode($from)?>&amp;to=<?= urlencode($to)?>"><i class="bi bi-arrow-left"></i> Dashboard</a></div>
<section class="panel mb-4"><form method="get" class="district-performance-filter"><div><label class="form-label" for="q">District or region</label><input class="form-control" id="q" name="q" type="search" value="<?= ecEscape($search)?>" placeholder="Search district or region"></div><div><label class="form-label" for="from">From date</label><input class="form-control" id="from" name="from" type="date" value="<?= ecEscape($from)?>" required></div><div><label class="form-label" for="to">To date</label><input class="form-control" id="to" name="to" type="date" value="<?= ecEscape($to)?>" required></div><div><label class="form-label" for="per_page">Rows</label><select class="form-select" id="per_page" name="per_page"><?php foreach ([10,25,50,100,250] as $size):?><option value="<?= $size?>" <?= $perPage === $size ? 'selected' : ''?>><?= $size?></option><?php endforeach;?></select></div><button class="btn btn-primary"><i class="bi bi-search"></i> Apply</button><a class="btn btn-outline-secondary" href="district_performance">Clear</a></form></section>
<section class="metrics performance-summary" aria-label="District performance summary"><article class="metric"><div class="metric-label">Districts found</div><div class="metric-value"><?= number_format($count)?></div><div class="metric-foot">Uncategorized records excluded</div></article><article class="metric"><div class="metric-label">Payment records</div><div class="metric-value"><?= number_format((int)$summary["total_records"])?></div><div class="metric-foot"><?= number_format((int)$summary["successful"])?> successful</div></article><article class="metric"><div class="metric-label">Successful amount · UGX</div><div class="metric-value"><?= number_format((float)$summary["paid_amount"], 0)?></div><div class="metric-foot">Selected districts and period</div></article><article class="metric"><div class="metric-label">Failed payments</div><div class="metric-value"><?= number_format((int)$summary["failed"])?></div><div class="metric-foot danger-text">Records needing review</div></article></section>
<section class="panel"><div class="panel-heading"><div><h2>District ranking</h2><p class="muted"><?= date("d M Y", strtotime($from))?> – <?= date("d M Y", strtotime($to))?></p></div><span class="muted"><?= number_format($count)?> district<?= $count === 1 ? '' : 's' ?></span></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Rank</th><th>District / region</th><th>Records</th><th>Successful</th><th>Failed</th><th>Pending</th><th>Success rate</th><th>Paid amount · UGX</th></tr></thead><tbody><?php foreach ($rows as $index => $row):?><tr><td data-label="Rank"><strong>#<?= number_format($offset + $index + 1)?></strong></td><td><strong><?= ecEscape($row["name"])?></strong><div class="muted"><?= ecEscape($row["region"])?></div></td><td data-label="Records"><?= number_format($row["total_records"])?></td><td data-label="Successful"><?= number_format($row["successful"])?></td><td data-label="Failed"><?= number_format($row["failed"])?></td><td data-label="Pending"><?= number_format($row["pending"])?></td><td data-label="Success rate"><span class="performance-rate"><?= number_format($row["success_rate"], 1)?>%</span></td><td data-label="Paid amount · UGX" class="money"><?= number_format($row["paid_amount"], 0)?></td></tr><?php endforeach;
if (!$rows):?><tr><td class="empty" colspan="8">No district payment records match these filters.</td></tr><?php endif;?></tbody></table></div><?php if ($count):?><div class="table-pagination"><div>Showing <?= number_format($offset + 1)?>–<?= number_format(min($offset + $perPage, $count))?> of <?= number_format($count)?></div><div class="pagination-actions"><?php if ($page > 1):$query["page"] = $page - 1;?><a class="btn btn-sm btn-outline-secondary" href="district_performance?<?= ecEscape(http_build_query($query))?>"><i class="bi bi-chevron-left"></i> Previous</a><?php endif;?><span>Page <?= number_format($page)?> of <?= number_format($pages)?></span><?php if ($page < $pages):$query["page"] = $page + 1;?><a class="btn btn-sm btn-outline-secondary" href="district_performance?<?= ecEscape(http_build_query($query))?>">Next <i class="bi bi-chevron-right"></i></a><?php endif;?></div></div><?php endif;?></section>
<?php ecFooter(); ?>
