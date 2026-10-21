<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/accountability_service.php';
ecRequireAccountability();
$assignedOffice = ecAccountabilityOfficeId();
[$currentFy] = ecFiscalPeriod(date('Y-m-d'));
$fy = (string)($_GET['financial_year'] ?? $currentFy);
$quarter = max(0, min(4, (int)($_GET['quarter'] ?? 0)));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$region = trim((string)($_GET['region'] ?? ''));
$officeId = $assignedOffice ?? max(0, (int)($_GET['office_id'] ?? 0));
$rows = ($from && $to) ? ecAccountabilitySummaryByDate($from, $to, $region ?: null, $officeId ?: null) : ecAccountabilitySummary($fy, $quarter, $region ?: null, $officeId ?: null);
$fys = ecQuery("SELECT financial_year FROM accountability_allocations UNION SELECT financial_year FROM accountability_expenses ORDER BY financial_year DESC")->get_result()->fetch_all(MYSQLI_ASSOC);
if (!in_array($fy, array_column($fys, 'financial_year'), true)) {
    array_unshift($fys, ['financial_year' => $fy]);
}$regions = ecQuery("SELECT DISTINCT region FROM districts WHERE source_code IS NOT NULL ORDER BY region")->get_result()->fetch_all(MYSQLI_ASSOC);
$offices = ecQuery("SELECT id,name,region FROM districts WHERE source_code IS NOT NULL ORDER BY region,name")->get_result()->fetch_all(MYSQLI_ASSOC);
if ($assignedOffice !== null) {
    $offices = array_values(array_filter($offices, fn ($o) => (int)$o["id"] === $assignedOffice));
    $regions = $offices ? [["region" => $offices[0]["region"]]] : [];
}if ($assignedOffice !== null && $offices) {
    $region = $offices[0]["region"];
}$query = http_build_query(['financial_year' => $fy,'quarter' => $quarter,'from' => $from,'to' => $to,'region' => $region,'office_id' => $officeId]);
session_write_close();
ecHeader('Accountability reports', 'accountability_reports');
function reportRows($rows, $field, $empty)
{?><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Region</th><th>Office</th><th>Amount · UGX</th></tr></thead><tbody><?php $total = 0;
    foreach ($rows as $r):$total += (float)$r[$field];?><tr><td><?= ecEscape($r['region'])?></td><td data-label="Office"><?= ecEscape($r['name'])?></td><td data-label="Amount · UGX" class="money"><?= number_format($r[$field], 0)?></td></tr><?php endforeach;
    if (!$rows):?><tr><td colspan="3" class="empty"><?= ecEscape($empty)?></td></tr><?php endif;?></tbody><?php if ($rows):?><tfoot><tr><th colspan="2">Total</th><th class="money"><?= number_format($total, 0)?></th></tr></tfoot><?php endif;?></table></div><?php }?>
<div class="page-heading"><div><div class="eyebrow">Finance team</div><h1>Accountability reports</h1><p class="muted">Use a financial year and quarter, or enter a date range to override the fiscal-period selection.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="accountability">Operations</a><a class="btn btn-primary" href="export_accountability_report?<?= ecEscape($query)?>">Export Excel</a></div></div><section class="panel mb-4"><form method="get" class="panel-body search-row report-filter-grid"><div><label class="form-label">Financial year</label><select class="form-select" name="financial_year"><?php foreach ($fys as $x):?><option <?= $x['financial_year'] === $fy ? 'selected' : ''?>><?= ecEscape($x['financial_year'])?></option><?php endforeach;?></select></div><div><label class="form-label">Quarter</label><select class="form-select" name="quarter"><option value="0">Full year</option><?php for ($q = 1;$q <= 4;$q++):?><option value="<?= $q?>" <?= $quarter === $q ? 'selected' : ''?>>Quarter <?= $q?></option><?php endfor;?></select></div><div><label class="form-label">From date</label><input class="form-control" type="date" name="from" value="<?= ecEscape($from)?>"></div><div><label class="form-label">To date</label><input class="form-control" type="date" name="to" value="<?= ecEscape($to)?>"></div><div><label class="form-label">Region</label><select class="form-select dynamic-region" name="region" data-office-target="report-office"><option value="">All regions</option><?php foreach ($regions as $r):?><option <?= $region === $r['region'] ? 'selected' : ''?>><?= ecEscape($r['region'])?></option><?php endforeach;?></select></div><div><label class="form-label">Office</label><select class="form-select" id="report-office" name="office_id"><option value="0">All offices</option><?php foreach ($offices as $o):?><option value="<?= (int)$o['id']?>" data-region="<?= ecEscape($o['region'])?>" <?= $officeId === (int)$o['id'] ? 'selected' : ''?>><?= ecEscape($o['name'])?></option><?php endforeach;?></select></div><button class="btn btn-primary">Apply filters</button></form></section><div class="metrics mb-4"><?php foreach (['allocated' => 'Money sent','unutilised' => 'Unutilised','unaccounted' => 'Unaccounted','accounted' => 'Accounted'] as $field => $label):?><article class="metric"><div class="metric-label"><?= $label?></div><div class="metric-value"><?= number_format(array_sum(array_column($rows, $field)), 0)?></div><div class="metric-foot">UGX for selected filters</div></article><?php endforeach;?></div><section class="panel mb-4"><div class="panel-heading"><h2>Unutilised money</h2></div><?php reportRows($rows, 'unutilised', 'No unutilised funds found.');?></section><section class="panel mb-4"><div class="panel-heading"><h2>Unaccounted expenditure</h2></div><?php reportRows($rows, 'unaccounted', 'No unaccounted expenditure found.');?></section><section class="panel"><div class="panel-heading"><h2>Accounted expenditure</h2></div><?php reportRows($rows,'accounted','No accounted expenditure found.');?></section><script>document.querySelectorAll('.dynamic-region').forEach(region=>{const office=document.getElementById(region.dataset.officeTarget);const apply=()=>{[...office.options].forEach((o,i)=>{if(i)o.hidden=!!region.value&&o.dataset.region!==region.value});if(office.selectedOptions[0]?.hidden)office.selectedIndex=0};region.addEventListener('change',apply);apply()});</script><?php ecFooter();?>
