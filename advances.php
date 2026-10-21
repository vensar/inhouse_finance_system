<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/advance_service.php';
ecRequireAccountability();
$fixedAdvanceType = $fixedAdvanceType ?? '';
$advancePage = $fixedAdvanceType === 'TRAVEL' ? 'travel_advances' : ($fixedAdvanceType === 'INDIVIDUAL' ? 'individual_advances' : 'advances');
$moduleTitle = $fixedAdvanceType === 'TRAVEL' ? 'Travel Allowance Accountability' : ($fixedAdvanceType === 'INDIVIDUAL' ? 'Individual Allowance Accountability' : 'Travel and Individual Advances');
$admin = ecIsAccountabilityAdmin();
$assigned = ecAccountabilityOfficeId();
$error = '';
$notice = $_SESSION['advance_notice'] ?? '';
unset($_SESSION['advance_notice']);
$activeTab = in_array($_GET['tab'] ?? 'upload', ['upload','account','reports'], true) ? $_GET['tab'] : 'upload';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new DomainException('Session expired. Reload and try again.');
        }$action = (string)($_POST['action'] ?? '');
        if ($action === 'upload') {
            if (!$admin && $assigned === null) {
                throw new DomainException('Your account needs an office assignment.');
            }$file = $_FILES['advance_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 15 * 1024 * 1024) {
                throw new DomainException('Choose an advance .xlsx or .csv file up to 15 MB.');
            }$result = ecImportAdvances($file['tmp_name'], $file['name'], $_FILES['mapping_file'] ?? null, $fixedAdvanceType ?: ((string)$_POST['advance_type']), (string)$_POST['advance_date'], (int)$_SESSION['user_id']);
            $notice = number_format($result['count']).' advance records uploaded (UGX '.number_format($result['total'], 0).').'.($result['unmapped'] ? ' '.number_format($result['unmapped']).' records require office mapping.' : '');
            $activeTab = 'account';
        } elseif ($action === 'review') {
            ecSetAdvanceAccounted((int)$_POST['record_id'], (string)($_POST['accounted_amount'] ?? ''), (string)($_POST['review_note'] ?? ''), (int)$_SESSION['user_id']);
            $notice = 'Advance accountability updated.';
            $activeTab = 'account';
        } else {
            throw new DomainException('Choose a valid action.');
        }$_SESSION['advance_notice'] = $notice;
        header('Location: '.$advancePage.'?tab='.$activeTab, true, 303);
        exit;
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Advances: '.$e->getMessage());
        $error = 'The advance request could not be completed.';
    }
}
$type = $fixedAdvanceType ?: ((in_array($_GET['type'] ?? '', ['TRAVEL','INDIVIDUAL'], true) ? $_GET['type'] : ''));
$status = in_array($_GET['status'] ?? '', ['ACCOUNTED','UNACCOUNTED'], true) ? $_GET['status'] : '';
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$officeId = $assigned ?? max(0, (int)($_GET['office_id'] ?? 0));
$search = trim((string)($_GET['q'] ?? ''));
$requested = (int)($_GET['per_page'] ?? 25);
$per = in_array($requested, [10,25,50,100,250], true) ? $requested : 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$where = [];
$types = '';
$params = [];
if ($assigned !== null || $officeId) {
    $where[] = 'r.office_id=?';
    $types .= 'i';
    $params[] = $officeId;
}if ($type) {
    $where[] = 'r.advance_type=?';
    $types .= 's';
    $params[] = $type;
}if ($status) {
    $where[] = 'r.accountability_status=?';
    $types .= 's';
    $params[] = $status;
}if ($from) {
    $where[] = 'r.advance_date>=?';
    $types .= 's';
    $params[] = $from;
}if ($to) {
    $where[] = 'r.advance_date<=?';
    $types .= 's';
    $params[] = $to;
}if ($search !== '') {
    $like = '%'.$search.'%';
    $where[] = '(r.beneficiary_account LIKE ? OR r.beneficiary_name LIKE ? OR d.name LIKE ? OR r.office_label LIKE ? OR r.reference_number LIKE ?)';
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}$whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';
$total = (int)(ecQuery('SELECT COUNT(*) n FROM advance_records r LEFT JOIN districts d ON d.id=r.office_id'.$whereSql, $types, $params)->get_result()->fetch_assoc()['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;
$rows = ecQuery('SELECT r.*,COALESCE(d.name,r.office_label,"UNMAPPED") office_name,d.region,u.full_name reviewer_name FROM advance_records r LEFT JOIN districts d ON d.id=r.office_id LEFT JOIN users u ON u.id=r.reviewed_by'.$whereSql.' ORDER BY r.advance_date DESC,r.id DESC LIMIT ?,?', $types.'ii', array_merge($params, [$offset,$per]))->get_result()->fetch_all(MYSQLI_ASSOC);
$summary = ecQuery('SELECT COALESCE(SUM(r.advance_amount),0) advanced,COALESCE(SUM(r.accounted_amount),0) accounted,COALESCE(SUM(r.advance_amount-r.accounted_amount),0) unaccounted,COUNT(*) records FROM advance_records r LEFT JOIN districts d ON d.id=r.office_id'.$whereSql, $types, $params)->get_result()->fetch_assoc();
$offices = ecQuery("SELECT id,name,region FROM districts WHERE source_code IS NOT NULL ORDER BY region,name")->get_result()->fetch_all(MYSQLI_ASSOC);
if ($assigned !== null) {
    $offices = array_values(array_filter($offices, fn ($o) => (int)$o['id'] === $assigned));
}$uploadSql = 'SELECT u.*,x.full_name uploader FROM advance_uploads u LEFT JOIN users x ON x.id=u.uploaded_by'.($fixedAdvanceType ? ' WHERE u.advance_type=?' : '').' ORDER BY u.created_at DESC LIMIT 20';
$uploads = ecQuery($uploadSql, $fixedAdvanceType ? 's' : '', $fixedAdvanceType ? [$fixedAdvanceType] : [])->get_result()->fetch_all(MYSQLI_ASSOC);
$csrf = generateCSRFToken();
$exportQuery = http_build_query(['type' => $type,'status' => $status,'from' => $from,'to' => $to,'office_id' => $officeId,'q' => $search]);
session_write_close();
ecHeader($moduleTitle, $advancePage);
?>
<div class="page-heading"><div><div class="eyebrow">Accountability department</div><h1><?= ecEscape($moduleTitle)?></h1><p class="muted">Upload payments, record supporting paperwork, and report on accounted and unaccounted allowance.</p></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary" href="download_advance_template?type=payments"><i class="bi bi-download"></i> Payment template</a><a class="btn btn-outline-secondary" href="download_advance_template?type=mapping"><i class="bi bi-download"></i> Office mapping template</a><a class="btn btn-primary" href="export_advances_report?<?= ecEscape($exportQuery)?>"><i class="bi bi-file-earmark-spreadsheet"></i> Export report</a></div></div><?php if ($notice):?><div class="alert alert-success"><?= ecEscape($notice)?></div><?php endif;
if ($error):?><div class="alert alert-danger"><?= ecEscape($error)?></div><?php endif;?>
<nav class="feature-tabs" aria-label="Advance accountability features"><button class="feature-tab" data-tab="upload"><i class="bi bi-cloud-arrow-up"></i> Upload advances</button><button class="feature-tab" data-tab="account"><i class="bi bi-check2-square"></i> Account for advances</button><button class="feature-tab" data-tab="reports"><i class="bi bi-bar-chart-line"></i> Reports</button></nav>
<div class="accountability-tab-panel" data-tab-panel="upload"><section class="panel mb-4"><div class="panel-heading"><div><h2>Upload advance payments</h2><p class="muted">Upload this allowance category using its dedicated payment and office-mapping files.</p></div></div><form method="post" enctype="multipart/form-data" class="panel-body advance-upload-grid"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf)?>"><input type="hidden" name="action" value="upload"><?php if ($fixedAdvanceType):?><input type="hidden" name="advance_type" value="<?= ecEscape($fixedAdvanceType)?>"><div><label class="form-label">Allowance category</label><div class="form-control advance-fixed-category"><?= ecEscape($moduleTitle)?></div></div><?php else:?><div><label class="form-label">Advance category</label><select class="form-select" name="advance_type" required><option value="TRAVEL">Travel Advances</option><option value="INDIVIDUAL">Individual Advances</option></select></div><?php endif;?><div><label class="form-label">Advance date</label><input class="form-control" type="date" name="advance_date" value="<?= date('Y-m-d')?>" required></div><div><label class="form-label">Advance payment file</label><input class="form-control" type="file" name="advance_file" accept=".xlsx,.csv" required><div class="form-text">Use the supplied .xlsx template. Legacy .xls files should be saved as .xlsx first.</div></div><div><label class="form-label">Beneficiary office mapping (optional)</label><input class="form-control" type="file" name="mapping_file" accept=".csv"><div class="form-text">Supports the supplied headerless Account, Name, Office CSV.</div></div><button class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload advances</button></form></section><section class="panel"><div class="panel-heading"><h2>Recent uploads</h2><span class="muted">Latest 20 batches</span></div><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Category / file</th><th>Advance date</th><th>Records</th><th>Total · UGX</th><th>Uploaded by</th></tr></thead><tbody><?php foreach ($uploads as $u):?><tr><td><strong><?= ecEscape($u['advance_type'] === 'TRAVEL' ? 'Travel Advances' : 'Individual Advances')?></strong><div><?= ecEscape($u['original_filename'])?></div></td><td data-label="Advance date"><?= date('d M Y', strtotime($u['advance_date']))?></td><td data-label="Records"><?= number_format($u['row_count'])?></td><td data-label="Total · UGX" class="money"><?= number_format($u['total_amount'], 0)?></td><td data-label="Uploaded by"><?= ecEscape($u['uploader'] ?: 'Former user')?></td></tr><?php endforeach;
if (!$uploads):?><tr><td colspan="5" class="empty">No advance batches uploaded.</td></tr><?php endif;?></tbody></table></div></section></div>
<div class="accountability-tab-panel" data-tab-panel="account"><section class="panel"><div class="panel-heading"><div><h2>Account for advances</h2><p class="muted">Record the amount supported by paperwork against each beneficiary advance.</p></div></div><?php require __DIR__.'/advance_filters.php';?><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Beneficiary / account</th><th>Category / date</th><th>Office</th><th>Advanced</th><th>Accounted</th><th>Outstanding</th><th>Accountability</th></tr></thead><tbody><?php foreach ($rows as $r):?><tr><td><strong><?= ecEscape($r['beneficiary_name'])?></strong><div><?= ecEscape($r['beneficiary_account'])?></div><small class="muted"><?= ecEscape($r['reference_number'])?></small></td><td data-label="Category / date"><span class="lot-status <?= $r['advance_type'] === 'TRAVEL' ? 'active' : 'inactive'?>"><?= ecEscape($r['advance_type'])?></span><div class="muted"><?= date('d M Y', strtotime($r['advance_date']))?></div></td><td data-label="Office"><?= ecEscape($r['office_name'])?></td><td data-label="Advanced" class="money"><?= number_format($r['advance_amount'], 0)?></td><td data-label="Accounted" class="money"><?= number_format($r['accounted_amount'], 0)?></td><td data-label="Outstanding" class="money"><?= number_format(max(0, (float)$r['advance_amount'] - (float)$r['accounted_amount']), 0)?></td><td><form method="post" class="accountability-line-form"><input type="hidden" name="csrf_token" value="<?= ecEscape($csrf)?>"><input type="hidden" name="action" value="review"><input type="hidden" name="record_id" value="<?= (int)$r['id']?>"><span class="lot-status <?= $r['accountability_status'] === 'ACCOUNTED' ? 'active' : 'inactive'?>"><?= ecEscape($r['accountability_status'] === 'ACCOUNTED' ? 'FULLY ACCOUNTED' : ((float)$r['accounted_amount'] > 0 ? 'PARTLY ACCOUNTED' : 'UNACCOUNTED'))?></span><label class="form-label mt-2">Accounted amount · UGX</label><input class="form-control" type="number" name="accounted_amount" min="0" max="<?= ecEscape($r['advance_amount'])?>" step="0.01" value="<?= ecEscape($r['accounted_amount'])?>" required><label class="form-label mt-2">Paperwork note</label><input class="form-control" name="review_note" maxlength="500" value="<?= ecEscape($r['review_note'])?>"><button class="btn btn-sm btn-primary mt-2">Save accountability</button></form></td></tr><?php endforeach;
if (!$rows):?><tr><td colspan="7" class="empty">No advances match these filters.</td></tr><?php endif;?></tbody></table></div><?php require __DIR__.'/advance_pagination.php';?></section></div>
<div class="accountability-tab-panel" data-tab-panel="reports"><div class="metrics mb-4"><article class="metric"><div class="metric-label">Advances issued</div><div class="metric-value"><?= number_format($summary['advanced'], 0)?></div><div class="metric-foot">UGX · <?= number_format($summary['records'])?> records</div></article><article class="metric"><div class="metric-label">Accounted for</div><div class="metric-value"><?= number_format($summary['accounted'], 0)?></div><div class="metric-foot success-text">UGX supported by paperwork</div></article><article class="metric"><div class="metric-label">Unaccounted for</div><div class="metric-value"><?= number_format($summary['unaccounted'], 0)?></div><div class="metric-foot danger-text">UGX still outstanding</div></article></div><section class="panel"><div class="panel-heading"><div><h2>Advance accountability report</h2><p class="muted">Review this allowance category and export its accounted and unaccounted records.</p></div><a class="btn btn-primary" href="export_advances_report?<?= ecEscape($exportQuery)?>">Export Excel</a></div><?php require __DIR__.'/advance_filters.php';?><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>Beneficiary</th><th>Category</th><th>Office</th><th>Advance date</th><th>Advanced</th><th>Accounted</th><th>Unaccounted</th><th>Status</th></tr></thead><tbody><?php foreach ($rows as $r):?><tr><td><strong><?= ecEscape($r['beneficiary_name'])?></strong><div><?= ecEscape($r['beneficiary_account'])?></div></td><td data-label="Category"><?= ecEscape($r['advance_type'] === 'TRAVEL' ? 'Travel' : 'Individual')?></td><td data-label="Office"><?= ecEscape($r['office_name'])?></td><td data-label="Advance date"><?= date('d M Y', strtotime($r['advance_date']))?></td><td data-label="Advanced" class="money"><?= number_format($r['advance_amount'], 0)?></td><td data-label="Accounted" class="money"><?= number_format($r['accounted_amount'], 0)?></td><td data-label="Unaccounted" class="money"><?= number_format((float)$r['advance_amount'] - (float)$r['accounted_amount'], 0)?></td><td data-label="Status"><?= ecEscape($r['accountability_status'])?></td></tr><?php endforeach;
if (!$rows):?><tr><td colspan="8" class="empty">No advances match these report filters.</td></tr><?php endif;?></tbody></table></div><?php require __DIR__.'/advance_pagination.php';?></section></div>
<script>const activeAdvanceTab=<?= json_encode($activeTab)?>;const showAdvanceTab=name=>{document.querySelectorAll('[data-tab-panel]').forEach(p=>p.hidden=p.dataset.tabPanel!==name);document.querySelectorAll('[data-tab]').forEach(t=>t.classList.toggle('active',t.dataset.tab===name))};document.querySelectorAll('[data-tab]').forEach(t=>t.addEventListener('click',()=>{showAdvanceTab(t.dataset.tab);const u=new URL(location.href);u.searchParams.set('tab',t.dataset.tab);history.replaceState(null,'',u)}));showAdvanceTab(activeAdvanceTab);</script><?php ecFooter(); ?>
