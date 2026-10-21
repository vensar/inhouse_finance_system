<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/accountability_service.php';
ecRequireAccountability();
$error = '';
$notice = $_SESSION['accountability_notice'] ?? '';
unset($_SESSION['accountability_notice']);
$admin = ecIsAccountabilityAdmin();
$assignedOffice = ecAccountabilityOfficeId();
$allowedTabs = $admin ? ['funding','upload','balances','account'] : ['upload','balances','account'];
$activeTab = (string)($_GET['tab'] ?? $allowedTabs[0]);
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = $allowedTabs[0];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new DomainException('Session expired. Reload and try again.');
        }$action = (string)($_POST['action'] ?? '');
        if ($action === 'eft_upload') {
            if (!$admin) {
                throw new DomainException('Only Accountability Admins upload EFT allocations.');
            }$f = $_FILES['eft_file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024 || strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new DomainException('Choose an EFT .xlsx workbook up to 10 MB.');
            }$n = ecImportEftWorkbook($f['tmp_name'], $f['name'], (int)$_SESSION['user_id']);
            $notice = number_format($n).' EFT allocations uploaded.';
        } elseif ($action === 'allocation') {
            if (!$admin) {
                throw new DomainException('Only Accountability Admins record EFT allocations.');
            }ecAddAccountabilityAllocation((int)$_POST['office_id'], (string)($_POST['allocation_date'] ?? (($_POST['allocation_month'] ?? '').'-01')), (string)$_POST['amount'], (string)$_POST['eft_number'], (string)$_POST['description'], (int)$_SESSION['user_id']);
            $notice = 'EFT allocation recorded.';
        } elseif ($action === 'upload') {
            $f = $_FILES['excel_file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024 || strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new DomainException('Choose a consumption .xlsx workbook up to 10 MB.');
            }$n = ecImportAccountabilityWorkbook($f['tmp_name'], $f['name'], (string)$_POST['reporting_month'], (int)$_POST['office_id'], (int)$_SESSION['user_id']);
            $notice = number_format($n).' expenditure records uploaded and matched to EFTs.';
        } elseif ($action === 'review') {
            ecSetExpenseAccounted((int)$_POST['expense_id'], (string)($_POST['accounted_amount'] ?? ''), (string)($_POST['review_note'] ?? ''), (int)$_SESSION['user_id']);
            $notice = $admin ? 'Accountability recorded and confirmed.' : 'Accountability submitted for verification.';
        } elseif ($action === 'verify') {
            ecReviewExpenseSubmission((int)$_POST['expense_id'], (string)($_POST['decision'] ?? ''), (string)($_POST['rejection_reason'] ?? ''), (int)$_SESSION['user_id']);
            $notice = ($_POST['decision'] ?? '') === 'REJECTED' ? 'Accountability rejected with a reason.' : 'Accountability verified and confirmed.';
        } else {
            throw new DomainException('Choose a valid action.');
        }$tabByAction = ['eft_upload' => 'funding','allocation' => 'funding','upload' => 'upload','review' => 'account','verify' => 'account'];
        $_SESSION['accountability_notice'] = $notice;
        header('Location: accountability?tab='.($tabByAction[$action] ?? $allowedTabs[0]), true, 303);
        exit();
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Accountability: '.$e->getMessage());
        $error = 'The accountability request could not be completed.';
    }
}
$regions = ecQuery("SELECT DISTINCT region FROM districts WHERE source_code IS NOT NULL ORDER BY region")->get_result()->fetch_all(MYSQLI_ASSOC);
$offices = ecQuery("SELECT id,name,region,office_type FROM districts WHERE source_code IS NOT NULL ORDER BY region,name")->get_result()->fetch_all(MYSQLI_ASSOC);
if ($assignedOffice !== null) {
    $offices = array_values(array_filter($offices, fn ($o) => (int)$o["id"] === $assignedOffice));
    $regions = $offices ? [["region" => $offices[0]["region"]]] : [];
}$filterRegion = $assignedOffice !== null && $offices ? $offices[0]['region'] : trim((string)($_GET['region'] ?? ''));
$filterOffice = $assignedOffice ?? max(0, (int)($_GET['office_id'] ?? 0));
$filterStatus = in_array($_GET['status'] ?? '', ['ACCOUNTED','UNACCOUNTED'], true) ? $_GET['status'] : '';
$where = [];
$types = '';
$params = [];
if ($filterRegion) {
    $where[] = 'd.region=?';
    $types .= 's';
    $params[] = $filterRegion;
}if ($filterOffice) {
    $where[] = 'e.office_id=?';
    $types .= 'i';
    $params[] = $filterOffice;
}if ($filterStatus) {
    $where[] = 'e.accountability_status=?';
    $types .= 's';
    $params[] = $filterStatus;
}$sql = "SELECT e.*,d.name office_name,d.region,a.amount eft_amount,(SELECT COALESCE(SUM(x.invoice_amount),0) FROM accountability_expenses x WHERE x.allocation_id=a.id) eft_used,u.full_name reviewer_name,v.full_name verifier_name FROM accountability_expenses e JOIN districts d ON d.id=e.office_id LEFT JOIN accountability_allocations a ON a.id=e.allocation_id LEFT JOIN users u ON u.id=e.reviewed_by LEFT JOIN users v ON v.id=e.verified_by".($where ? ' WHERE '.implode(' AND ', $where) : '')." ORDER BY e.invoice_date DESC,e.id DESC LIMIT 300";
$expenses = ecQuery($sql, $types, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
$eftSearch = trim((string)($_GET['eft_q'] ?? ''));
$eftFrom = trim((string)($_GET['eft_from'] ?? ''));
$eftTo = trim((string)($_GET['eft_to'] ?? ''));
if ($eftFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eftFrom)) {
    $eftFrom = '';
}if ($eftTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eftTo)) {
    $eftTo = '';
}$eftPer = in_array((int)($_GET['eft_per_page'] ?? 10), [10,25,50,100,250], true) ? (int)($_GET['eft_per_page'] ?? 10) : 10;
$eftPage = max(1, (int)($_GET['eft_page'] ?? 1));
$eftWhere = [];
$eftTypes = '';
$eftParams = [];
if ($assignedOffice !== null) {
    $eftWhere[] = 'a.office_id=?';
    $eftTypes .= 'i';
    $eftParams[] = $assignedOffice;
}if ($eftSearch !== '') {
    $like = '%'.$eftSearch.'%';
    $eftWhere[] = '(d.name LIKE ? OR d.region LIKE ? OR a.eft_number LIKE ?)';
    $eftTypes .= 'sss';
    array_push($eftParams, $like, $like, $like);
}if ($eftFrom !== '') {
    $eftWhere[] = 'a.allocation_date>=?';
    $eftTypes .= 's';
    $eftParams[] = $eftFrom;
}if ($eftTo !== '') {
    $eftWhere[] = 'a.allocation_date<=?';
    $eftTypes .= 's';
    $eftParams[] = $eftTo;
}$eftWhereSql = $eftWhere ? ' WHERE '.implode(' AND ', $eftWhere) : '';
$eftTotal = (int)(ecQuery("SELECT COUNT(*) total FROM accountability_allocations a JOIN districts d ON d.id=a.office_id".$eftWhereSql, $eftTypes, $eftParams)->get_result()->fetch_assoc()['total'] ?? 0);
$eftPages = max(1, (int)ceil($eftTotal / $eftPer));
$eftPage = min($eftPage, $eftPages);
$eftOffset = ($eftPage - 1) * $eftPer;
$efts = ecQuery("SELECT a.*,d.name office_name,d.region,COALESCE(SUM(e.invoice_amount),0) spent,a.amount-COALESCE(SUM(e.invoice_amount),0) balance FROM accountability_allocations a JOIN districts d ON d.id=a.office_id LEFT JOIN accountability_expenses e ON e.allocation_id=a.id".$eftWhereSql." GROUP BY a.id ORDER BY a.allocation_date DESC,a.id DESC LIMIT ?,?", $eftTypes.'ii', array_merge($eftParams, [$eftOffset,$eftPer]))->get_result()->fetch_all(MYSQLI_ASSOC);
$csrf = generateCSRFToken();
session_write_close();
ecHeader('Accountability', 'accountability');
?>
<div class="page-heading"><div><div class="eyebrow">Finance team</div><h1>Office EFT accountability</h1><p class="muted">Upload EFTs, match expenditure, and review supporting paperwork.</p></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary" href="download_accountability_template?type=consumption">Consumption template</a><?php if ($admin):?><a class="btn btn-outline-secondary" href="download_accountability_template?type=eft">EFT template</a><?php endif;?><a class="btn btn-primary" href="accountability_reports">Reports</a></div></div><?php if ($notice):?><div class="alert alert-success"><?= ecEscape($notice)?></div><?php endif;
if ($error):?><div class="alert alert-danger"><?= ecEscape($error)?></div><?php endif;?>
<nav class="feature-tabs" aria-label="Accountability features">
<?php if ($admin):?><button type="button" class="feature-tab" data-tab="funding"><i class="bi bi-cash-coin"></i><span>Monthly funds</span></button><?php endif;?>
<button type="button" class="feature-tab" data-tab="upload"><i class="bi bi-cloud-arrow-up"></i><span>Upload expenditure</span></button>
<button type="button" class="feature-tab" data-tab="balances"><i class="bi bi-wallet2"></i><span>EFT balances</span></button>
<button type="button" class="feature-tab" data-tab="account"><i class="bi bi-receipt-cutoff"></i><span>Account against EFTs</span></button>
<a class="feature-tab feature-tab-link" href="accountability_reports"><i class="bi bi-bar-chart-line"></i><span>Reports</span></a>
</nav>
<?php if ($admin):?><div class="accountability-tab-panel" data-tab-panel="funding">
<section class="panel mb-4"><div class="panel-heading"><div><h2>Record monthly amount sent</h2><p class="muted">Enter the amount sent to one district or office for the selected month.</p></div></div><form method="post" class="panel-body search-row monthly-allocation-grid"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf)?>"><input type="hidden" name="action" value="allocation"><div><label class="form-label">Region</label><select class="form-select dynamic-region" data-office-target="allocation-office"><option value="">All regions</option><?php foreach ($regions as $r):?><option><?= ecEscape($r['region'])?></option><?php endforeach;?></select></div><div><label class="form-label">District / office</label><select class="form-select" id="allocation-office" name="office_id" required><option value="">Choose office</option><?php foreach ($offices as $o):?><option value="<?= (int)$o['id']?>" data-region="<?= ecEscape($o['region'])?>"><?= ecEscape($o['name'])?></option><?php endforeach;?></select></div><div><label class="form-label">Allocation month</label><input class="form-control" type="month" name="allocation_month" value="<?= date('Y-m')?>" required></div><div><label class="form-label">Amount sent · UGX</label><input class="form-control" type="number" name="amount" min="1" step="1" inputmode="numeric" placeholder="e.g. 25000000" required></div><div><label class="form-label">EFT / reference number</label><input class="form-control" name="eft_number" maxlength="100" placeholder="e.g. EFT-2026-001" required></div><div><label class="form-label">Description</label><input class="form-control" name="description" maxlength="500" placeholder="Monthly operational funds"></div><button class="btn btn-primary"><i class="bi bi-plus-circle"></i> Record monthly amount</button></form></section>
<section class="panel mb-4"><div class="panel-heading"><div><h2>Upload monthly amounts for many offices</h2><p class="muted">Use one row per district or office. A single workbook can allocate the month’s funds to all offices.</p></div><a class="btn btn-outline-secondary btn-sm" href="download_accountability_template?type=eft"><i class="bi bi-download"></i> Download allocation template</a></div><form method="post" enctype="multipart/form-data" class="panel-body search-row eft-upload-grid"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf)?>"><input type="hidden" name="action" value="eft_upload"><div><label class="form-label">Completed monthly allocation workbook</label><input class="form-control" type="file" name="eft_file" accept=".xlsx" required><div class="form-text">Columns: Office, EFT Date, EFT Number, EFT Amount, Description.</div></div><button class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload all amounts</button></form></section></div>
<?php endif;?>
<div class="accountability-tab-panel" data-tab-panel="upload"><section class="panel"><div class="panel-heading"><div><h2>Upload office expenditure</h2><p class="muted"><?= $assignedOffice !== null ? 'Upload expenditure for your assigned office.' : 'Select an office. Every EFT number in the workbook must already exist for that office.' ?></p></div></div><form method="post" enctype="multipart/form-data" class="panel-body search-row accountability-upload-grid"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf)?>"><input type="hidden" name="action" value="upload"><div><label class="form-label">Region</label><select class="form-select dynamic-region" data-office-target="upload-office"><option value="" <?= $assignedOffice !== null ? 'hidden' : ''?>>All regions</option><?php foreach ($regions as $r):?><option <?= $assignedOffice !== null ? 'selected' : ''?>><?= ecEscape($r['region'])?></option><?php endforeach;?></select></div><div><label class="form-label">Office</label><select class="form-select dynamic-office" id="upload-office" name="office_id" required><option value="" <?= $assignedOffice !== null ? 'hidden' : ''?>>Choose office</option><?php foreach ($offices as $o):?><option value="<?= (int)$o['id']?>" data-region="<?= ecEscape($o['region'])?>" <?= $assignedOffice === (int)$o['id'] ? 'selected' : ''?>><?= ecEscape($o['name'])?></option><?php endforeach;?></select></div><div><label class="form-label">Reporting month</label><input class="form-control" type="month" name="reporting_month" required></div><div><label class="form-label">Consumption workbook</label><input class="form-control" type="file" name="excel_file" accept=".xlsx" required></div><button class="btn btn-primary">Upload and match EFTs</button></form></section></div>
<div class="accountability-tab-panel" data-tab-panel="balances"><section class="panel"><div class="panel-heading"><div><h2>Uploaded EFT balances</h2><p class="muted"><?= number_format($eftTotal)?> matching allocation<?= $eftTotal === 1 ? '' : 's' ?></p></div></div><form method="get" class="panel-body eft-balance-filter-grid"><input type="hidden" name="tab" value="balances"><div><label class="form-label">District / office / EFT</label><input class="form-control" name="eft_q" value="<?= ecEscape($eftSearch)?>" placeholder="Search office or EFT number"></div><div><label class="form-label">From date</label><input class="form-control" type="date" name="eft_from" value="<?= ecEscape($eftFrom)?>"></div><div><label class="form-label">To date</label><input class="form-control" type="date" name="eft_to" value="<?= ecEscape($eftTo)?>"></div><div><label class="form-label">Rows per page</label><select class="form-select" name="eft_per_page"><?php foreach ([10,25,50,100,250] as $size):?><option value="<?= $size?>" <?= $eftPer === $size ? 'selected' : ''?>><?= $size?></option><?php endforeach;?></select></div><button class="btn btn-primary"><i class="bi bi-search"></i> Search</button><a class="btn btn-outline-secondary" href="accountability?tab=balances">Clear</a></form><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Office / EFT</th><th>Date</th><th>EFT amount</th><th>Expenditure</th><th>Balance</th></tr></thead><tbody><?php foreach ($efts as $e):?><tr><td><strong><?= ecEscape($e['office_name'])?></strong><div><?= ecEscape($e['eft_number'])?></div><small class="muted"><?= ecEscape($e['region'])?></small></td><td data-label="Date"><?= ecEscape(date('d M Y', strtotime($e['allocation_date'])))?></td><td data-label="EFT amount" class="money"><?= number_format($e['amount'], 0)?></td><td data-label="Expenditure" class="money"><?= number_format($e['spent'], 0)?></td><td data-label="Balance" class="money"><?= number_format($e['balance'], 0)?></td></tr><?php endforeach;
if (!$efts):?><tr><td colspan="5" class="empty">No EFT allocations uploaded.</td></tr><?php endif;?></tbody></table></div><?php if ($eftTotal):?><div class="table-pagination"><div>Showing <?= number_format($eftOffset + 1)?>–<?= number_format(min($eftOffset + $eftPer, $eftTotal))?> of <?= number_format($eftTotal)?></div><div class="pagination-actions"><?php $eftQuery = $_GET;
    $eftQuery['tab'] = 'balances';
    if ($eftPage > 1):$eftQuery['eft_page'] = $eftPage - 1;?><a class="btn btn-sm btn-outline-secondary" href="accountability?<?= ecEscape(http_build_query($eftQuery))?>"><i class="bi bi-chevron-left"></i> Previous</a><?php endif;?><span>Page <?= number_format($eftPage)?> of <?= number_format($eftPages)?></span><?php if ($eftPage < $eftPages):$eftQuery['eft_page'] = $eftPage + 1;?><a class="btn btn-sm btn-outline-secondary" href="accountability?<?= ecEscape(http_build_query($eftQuery))?>">Next <i class="bi bi-chevron-right"></i></a><?php endif;?></div></div><?php endif;?></section></div>
<div class="accountability-tab-panel" data-tab-panel="account"><section class="panel"><div class="panel-heading"><div><h2>Account expenditure against EFTs</h2><p class="muted">Filter an office, inspect the EFT balance, record the paperwork outcome, and verify district submissions.</p></div></div><form method="get" class="panel-body search-row accountability-filter-grid"><input type="hidden" name="tab" value="account"><div><label class="form-label">Region</label><select class="form-select dynamic-region" name="region" data-office-target="filter-office"><option value="">All regions</option><?php foreach ($regions as $r):?><option <?= $filterRegion === $r['region'] ? 'selected' : ''?>><?= ecEscape($r['region'])?></option><?php endforeach;?></select></div><div><label class="form-label">Office</label><select class="form-select dynamic-office" id="filter-office" name="office_id"><option value="0">All offices</option><?php foreach ($offices as $o):?><option value="<?= (int)$o['id']?>" data-region="<?= ecEscape($o['region'])?>" <?= $filterOffice === (int)$o['id'] ? 'selected' : ''?>><?= ecEscape($o['name'])?></option><?php endforeach;?></select></div><div><label class="form-label">Paperwork status</label><select class="form-select" name="status"><option value="">All statuses</option><option <?= $filterStatus === 'UNACCOUNTED' ? 'selected' : ''?>>UNACCOUNTED</option><option <?= $filterStatus === 'ACCOUNTED' ? 'selected' : ''?>>ACCOUNTED</option></select></div><button class="btn btn-outline-secondary">Apply filters</button></form><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Office / invoice</th><th>EFT</th><th>Invoice amount</th><th>EFT balance</th><th>Accounted</th><th>Outstanding</th><th>Status / verification</th></tr></thead><tbody><?php foreach ($expenses as $e):?><tr><td><strong><?= ecEscape($e['office_name'])?></strong><div><?= ecEscape($e['invoice_number'])?> · <?= ecEscape(date('d M Y', strtotime($e['invoice_date'])))?></div><small class="muted"><?= ecEscape($e['description'])?></small></td><td data-label="EFT"><?= ecEscape($e['eft_number'])?><div class="muted">EFT <?= number_format($e['eft_amount'], 0)?></div></td><td data-label="Invoice amount" class="money"><?= number_format($e['invoice_amount'], 0)?></td><td data-label="EFT balance" class="money"><?= number_format((float)$e['eft_amount'] - (float)$e['eft_used'], 0)?></td><td data-label="Accounted" class="money"><?= number_format($e['accounted_amount'], 0)?></td><td data-label="Outstanding" class="money"><?= number_format(max(0, (float)$e['invoice_amount'] - (float)$e['accounted_amount']), 0)?></td><td data-label="Status / verification"><div class="d-flex gap-2 flex-wrap mb-2"><span class="lot-status <?= $e['accountability_status'] === 'ACCOUNTED' ? 'active' : 'inactive'?>"><?= ecEscape($e['accountability_status'] === 'ACCOUNTED' ? 'FULLY ACCOUNTED' : ((float)$e['accounted_amount'] > 0 ? 'PARTLY ACCOUNTED' : 'UNACCOUNTED'))?></span><span class="review-status review-<?= strtolower($e['verification_status'] ?? 'confirmed')?>"><?= ecEscape($e['verification_status'] ?? 'CONFIRMED')?></span></div><?php if (!empty($e['rejection_reason'])):?><div class="rejection-reason mb-2"><?= ecEscape($e['rejection_reason'])?></div><?php endif;?><form method="post" class="accountability-line-form"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf)?>"><input type="hidden" name="action" value="review"><input type="hidden" name="expense_id" value="<?= (int)$e['id']?>"><label class="form-label">Accountability amount · UGX</label><input class="form-control" type="number" name="accounted_amount" min="0" max="<?= ecEscape($e['invoice_amount'])?>" step="0.01" value="<?= ecEscape($e['accounted_amount'])?>" required><label class="form-label mt-2">Paperwork note</label><input class="form-control" name="review_note" value="<?= ecEscape($e['review_note'])?>" maxlength="500" placeholder="Reference or paperwork note"><button class="btn btn-sm btn-primary mt-2">Save accountability</button></form><?php if (in_array($_SESSION['role'] ?? '', ['admin','accountability_admin','accountability_officer'], true) && ($e['verification_status'] ?? 'CONFIRMED') === 'PENDING' && (int)($e['submitted_by'] ?? 0) !== (int)$_SESSION['user_id']):?><form method="post" class="review-action-form mt-3"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf)?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="expense_id" value="<?= (int)$e['id']?>"><label class="form-label">Verification reason</label><textarea class="form-control" name="rejection_reason" rows="2" maxlength="500" placeholder="Required when rejecting"></textarea><div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary" name="decision" value="CONFIRMED">Confirm</button><button class="btn btn-sm btn-danger" name="decision" value="REJECTED">Reject</button></div></form><?php endif;?></td></tr><?php endforeach;
if (!$expenses):?><tr><td colspan="7" class="empty">No expenditure matches these filters.</td></tr><?php endif;?></tbody></table></div></section></div><script>const activeAccountabilityTab=<?= json_encode($activeTab)?>;const showAccountabilityTab=name=>{document.querySelectorAll('[data-tab-panel]').forEach(panel=>panel.hidden=panel.dataset.tabPanel!==name);document.querySelectorAll('button[data-tab]').forEach(tab=>{const active=tab.dataset.tab===name;tab.classList.toggle('active',active);tab.setAttribute('aria-selected',String(active))})};document.querySelectorAll('button[data-tab]').forEach(tab=>tab.addEventListener('click',()=>{showAccountabilityTab(tab.dataset.tab);const url=new URL(location.href);url.searchParams.set('tab',tab.dataset.tab);history.replaceState(null,'',url)}));showAccountabilityTab(activeAccountabilityTab);document.querySelectorAll('.dynamic-region').forEach(region=>{const office=document.getElementById(region.dataset.officeTarget);if(!office)return;const apply=()=>{const selected=region.value;[...office.options].forEach((option,index)=>{if(index===0)return;option.hidden=!!selected&&option.dataset.region!==selected});if(office.selectedOptions[0]?.hidden)office.selectedIndex=0};region.addEventListener('change',apply);apply()});</script><?php ecFooter();?>
