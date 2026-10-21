<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/office_service.php';

ecRequireAccountability();

$admin = ecIsAccountabilityAdmin();
$canReview = ecCanReviewOfficeAccountability();
$allowedTabs = array_values(array_filter([
    $admin ? 'funding' : null,
    'account',
    $canReview ? 'review' : null,
    'reports',
]));
$activeTab = (string) ($_GET['tab'] ?? $allowedTabs[0]);
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = $allowedTabs[0];
}

$error = '';
$notice = $_SESSION['quarterly_office_notice'] ?? '';
unset($_SESSION['quarterly_office_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new DomainException('Session expired. Reload and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'allocation') {
            ecAddQuarterlyOfficeAllocation($_POST, (int) $_SESSION['user_id']);
            $notice = 'Quarterly office funding recorded.';
            $activeTab = 'funding';
        } elseif ($action === 'submit') {
            ecSubmitQuarterlyOfficeAccountability($_POST, (int) $_SESSION['user_id']);
            $notice = $admin
                ? 'Office accountability recorded and confirmed.'
                : 'Office accountability submitted for verification.';
            $activeTab = 'account';
        } elseif ($action === 'review') {
            ecReviewQuarterlyOfficeAccountability(
                (int) ($_POST['record_id'] ?? 0),
                (string) ($_POST['decision'] ?? ''),
                (string) ($_POST['review_reason'] ?? ''),
                (int) $_SESSION['user_id'],
            );
            $notice = ($_POST['decision'] ?? '') === 'REJECTED'
                ? 'Accountability rejected with the recorded reason.'
                : 'Accountability verified and confirmed.';
            $activeTab = 'review';
        } else {
            throw new DomainException('Choose a valid action.');
        }
        $_SESSION['quarterly_office_notice'] = $notice;
        header('Location: quarterly_office_accountability?tab=' . $activeTab, true, 303);
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Quarterly office Imprest: ' . $exception->getMessage());
        $error = 'The quarterly office Imprest request could not be completed.';
    }
}

$financialYears = ecOfficeFinancialYears();
$financialYear = trim((string) ($_GET['financial_year'] ?? ($financialYears[0] ?? '')));
$quarter = in_array((int) ($_GET['quarter'] ?? 0), [1, 2, 3, 4], true)
    ? (int) $_GET['quarter']
    : 0;
$officeId = max(0, (int) ($_GET['office_id'] ?? 0));
$reviewStatus = in_array($_GET['review_status'] ?? '', ['PENDING', 'CONFIRMED', 'REJECTED'], true)
    ? $_GET['review_status']
    : '';
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

[$officeScope, $officeScopeTypes, $officeScopeParams] = ecOfficeScopeSql('o');
$offices = ecQuery(
    "SELECT o.id,o.name,o.office_code,o.office_type,o.region,d.name district_name
     FROM offices o
     LEFT JOIN districts d ON d.id=o.district_id
     WHERE o.is_active=1{$officeScope}
     ORDER BY o.region,o.name",
    $officeScopeTypes,
    $officeScopeParams,
)->get_result()->fetch_all(MYSQLI_ASSOC);

$allocationWhere = ['1=1'];
$allocationTypes = '';
$allocationParams = [];
if ($financialYear !== '') {
    $allocationWhere[] = 'a.financial_year=?';
    $allocationTypes .= 's';
    $allocationParams[] = $financialYear;
}
if ($quarter) {
    $allocationWhere[] = 'a.quarter=?';
    $allocationTypes .= 'i';
    $allocationParams[] = $quarter;
}
if ($officeId) {
    $allocationWhere[] = 'a.office_id=?';
    $allocationTypes .= 'i';
    $allocationParams[] = $officeId;
}
$allocationWhereSql = implode(' AND ', $allocationWhere) . $officeScope;
$allocationTypes .= $officeScopeTypes;
$allocationParams = array_merge($allocationParams, $officeScopeParams);

$allocations = ecQuery(
    "SELECT a.*,o.name office_name,o.region,
        COALESCE(SUM(CASE WHEN q.review_status='CONFIRMED' THEN q.amount ELSE 0 END),0) confirmed_amount,
        COALESCE(SUM(CASE WHEN q.review_status='PENDING' THEN q.amount ELSE 0 END),0) pending_amount
     FROM quarterly_office_allocations a
     JOIN offices o ON o.id=a.office_id
     LEFT JOIN quarterly_office_accountability q ON q.allocation_id=a.id
     WHERE {$allocationWhereSql}
     GROUP BY a.id
     ORDER BY a.allocation_date DESC,a.id DESC",
    $allocationTypes,
    $allocationParams,
)->get_result()->fetch_all(MYSQLI_ASSOC);

$recordWhere = ['1=1'];
$recordTypes = '';
$recordParams = [];
if ($financialYear !== '') {
    $recordWhere[] = 'a.financial_year=?';
    $recordTypes .= 's';
    $recordParams[] = $financialYear;
}
if ($quarter) {
    $recordWhere[] = 'a.quarter=?';
    $recordTypes .= 'i';
    $recordParams[] = $quarter;
}
if ($officeId) {
    $recordWhere[] = 'a.office_id=?';
    $recordTypes .= 'i';
    $recordParams[] = $officeId;
}
if ($reviewStatus !== '') {
    $recordWhere[] = 'q.review_status=?';
    $recordTypes .= 's';
    $recordParams[] = $reviewStatus;
}
if ($from !== '') {
    $recordWhere[] = 'q.expense_date>=?';
    $recordTypes .= 's';
    $recordParams[] = $from;
}
if ($to !== '') {
    $recordWhere[] = 'q.expense_date<=?';
    $recordTypes .= 's';
    $recordParams[] = $to;
}
$recordWhereSql = implode(' AND ', $recordWhere) . $officeScope;
$recordTypes .= $officeScopeTypes;
$recordParams = array_merge($recordParams, $officeScopeParams);

$records = ecQuery(
    "SELECT q.*,a.financial_year,a.quarter,a.amount allocation_amount,a.reference_number allocation_reference,
        o.name office_name,submitter.full_name submitter_name,reviewer.full_name reviewer_name
     FROM quarterly_office_accountability q
     JOIN quarterly_office_allocations a ON a.id=q.allocation_id
     JOIN offices o ON o.id=a.office_id
     JOIN users submitter ON submitter.id=q.submitted_by
     LEFT JOIN users reviewer ON reviewer.id=q.reviewed_by
     WHERE {$recordWhereSql}
     ORDER BY q.submitted_at DESC,q.id DESC",
    $recordTypes,
    $recordParams,
)->get_result()->fetch_all(MYSQLI_ASSOC);

$summary = ecQuery(
    "SELECT
        COALESCE(SUM(a.amount),0) allocated,
        COALESCE(SUM(x.confirmed),0) confirmed,
        COALESCE(SUM(x.pending),0) pending,
        COALESCE(SUM(x.rejected),0) rejected
     FROM quarterly_office_allocations a
     JOIN offices o ON o.id=a.office_id
     LEFT JOIN (
        SELECT allocation_id,
            SUM(CASE WHEN review_status='CONFIRMED' THEN amount ELSE 0 END) confirmed,
            SUM(CASE WHEN review_status='PENDING' THEN amount ELSE 0 END) pending,
            SUM(CASE WHEN review_status='REJECTED' THEN amount ELSE 0 END) rejected
        FROM quarterly_office_accountability
        GROUP BY allocation_id
     ) x ON x.allocation_id=a.id
     WHERE {$allocationWhereSql}",
    $allocationTypes,
    $allocationParams,
)->get_result()->fetch_assoc();

$csrf = generateCSRFToken();
$exportQuery = http_build_query([
    'financial_year' => $financialYear,
    'quarter' => $quarter,
    'office_id' => $officeId,
    'review_status' => $reviewStatus,
    'from' => $from,
    'to' => $to,
]);
session_write_close();

ecHeader('Quarterly office Imprest', 'quarterly_office_accountability');
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Accountability department</div>
        <h1>Quarterly office Imprest</h1>
        <p class="muted">Track quarterly funding issued to offices and verify the supporting accountability submitted against it.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary" href="offices"><i class="bi bi-buildings"></i> Manage offices</a>
        <a class="btn btn-primary" href="export_quarterly_office_report?<?= ecEscape($exportQuery) ?>">
            <i class="bi bi-file-earmark-spreadsheet"></i> Export report
        </a>
    </div>
</div>

<?php if ($notice): ?><div class="alert alert-success"><?= ecEscape($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= ecEscape($error) ?></div><?php endif; ?>

<nav class="feature-tabs" aria-label="Quarterly office Imprest features">
    <?php if ($admin): ?>
        <button class="feature-tab" data-tab="funding"><i class="bi bi-wallet2"></i> Record quarterly funding</button>
    <?php endif; ?>
    <button class="feature-tab" data-tab="account"><i class="bi bi-receipt"></i> Submit accountability</button>
    <?php if ($canReview): ?>
        <button class="feature-tab" data-tab="review"><i class="bi bi-patch-check"></i> Verify submissions</button>
    <?php endif; ?>
    <button class="feature-tab" data-tab="reports"><i class="bi bi-bar-chart-line"></i> Reports</button>
</nav>

<?php if ($admin): ?>
    <div class="accountability-tab-panel" data-tab-panel="funding">
        <section class="panel mb-4">
            <div class="panel-heading">
                <div>
                    <h2>Record quarterly money issued</h2>
                    <p class="muted">The financial year and quarter are derived automatically from the allocation date.</p>
                </div>
            </div>
            <form method="post" class="panel-body office-allocation-grid">
                <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>">
                <input type="hidden" name="action" value="allocation">
                <div>
                    <label class="form-label">Office</label>
                    <select class="form-select" name="office_id" required>
                        <option value="">Choose office</option>
                        <?php foreach ($offices as $office): ?>
                            <option value="<?= (int) $office['id'] ?>"><?= ecEscape($office['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Allocation date</label>
                    <input class="form-control" type="date" name="allocation_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label class="form-label">Amount · UGX</label>
                    <input class="form-control" type="number" name="amount" min="0.01" step="0.01" required>
                </div>
                <div>
                    <label class="form-label">Reference number</label>
                    <input class="form-control" name="reference_number" maxlength="100">
                </div>
                <div class="office-wide-field">
                    <label class="form-label">Description</label>
                    <input class="form-control" name="description" maxlength="500">
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i> Record funding</button>
            </form>
        </section>
    </div>
<?php endif; ?>

<div class="accountability-tab-panel" data-tab-panel="account">
    <section class="panel mb-4">
        <div class="panel-heading">
            <div>
                <h2>Submit office accountability</h2>
                <p class="muted">District entries remain pending until an administrator or the assigned accountability officer confirms them.</p>
            </div>
        </div>
        <form method="post" class="panel-body office-accountability-grid">
            <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>">
            <input type="hidden" name="action" value="submit">
            <div class="office-wide-field">
                <label class="form-label">Quarterly allocation</label>
                <select class="form-select" name="allocation_id" required>
                    <option value="">Choose allocation</option>
                    <?php foreach ($allocations as $allocation): ?>
                        <?php $available = (float) $allocation['amount'] - (float) $allocation['confirmed_amount'] - (float) $allocation['pending_amount']; ?>
                        <option value="<?= (int) $allocation['id'] ?>">
                            <?= ecEscape($allocation['office_name']) ?> · <?= ecEscape($allocation['financial_year']) ?> Q<?= (int) $allocation['quarter'] ?> · UGX <?= number_format($available, 0) ?> available
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Expenditure date</label>
                <input class="form-control" type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label class="form-label">Document / invoice reference</label>
                <input class="form-control" name="document_reference" maxlength="100" required>
            </div>
            <div>
                <label class="form-label">Accounted amount · UGX</label>
                <input class="form-control" type="number" name="amount" min="0.01" step="0.01" required>
            </div>
            <div class="office-wide-field">
                <label class="form-label">Description</label>
                <input class="form-control" name="description" maxlength="500">
            </div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-send-check"></i> Submit accountability</button>
        </form>
    </section>
</div>

<?php if ($canReview): ?>
    <div class="accountability-tab-panel" data-tab-panel="review">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Verify submitted accountability</h2>
                    <p class="muted">Confirm valid submissions or reject them with a clear reason for correction.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mobile-records">
                    <thead><tr><th>Office / period</th><th>Document</th><th>Amount</th><th>Submitted by</th><th>Status</th><th>Verification</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><strong><?= ecEscape($record['office_name']) ?></strong><div><?= ecEscape($record['financial_year']) ?> · Q<?= (int) $record['quarter'] ?></div></td>
                                <td data-label="Document"><strong><?= ecEscape($record['document_reference']) ?></strong><div><?= date('d M Y', strtotime($record['expense_date'])) ?></div><small class="muted"><?= ecEscape($record['description']) ?></small></td>
                                <td data-label="Amount" class="money"><?= number_format($record['amount'], 0) ?></td>
                                <td data-label="Submitted by"><?= ecEscape($record['submitter_name']) ?><div class="muted"><?= date('d M Y H:i', strtotime($record['submitted_at'])) ?></div></td>
                                <td data-label="Status"><span class="review-status review-<?= strtolower($record['review_status']) ?>"><?= ecEscape($record['review_status']) ?></span><?php if ($record['review_reason']): ?><div class="rejection-reason"><?= ecEscape($record['review_reason']) ?></div><?php endif; ?></td>
                                <td data-label="Verification">
                                    <?php if ($record['review_status'] === 'PENDING' && (int) $record['submitted_by'] !== (int) $_SESSION['user_id']): ?>
                                        <form method="post" class="review-action-form">
                                            <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>">
                                            <input type="hidden" name="action" value="review">
                                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                            <textarea class="form-control" name="review_reason" rows="2" maxlength="500" placeholder="Required when rejecting"></textarea>
                                            <div class="d-flex gap-2 mt-2">
                                                <button class="btn btn-sm btn-primary" name="decision" value="CONFIRMED">Confirm</button>
                                                <button class="btn btn-sm btn-danger" name="decision" value="REJECTED">Reject</button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted"><?= ecEscape($record['reviewer_name'] ?: 'Awaiting another reviewer') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$records): ?><tr><td colspan="6" class="empty">No submissions match the filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
<?php endif; ?>

<div class="accountability-tab-panel" data-tab-panel="reports">
    <div class="metrics mb-4">
        <article class="metric"><div class="metric-label">Quarterly funding</div><div class="metric-value"><?= number_format($summary['allocated'], 0) ?></div><div class="metric-foot">UGX issued to offices</div></article>
        <article class="metric"><div class="metric-label">Confirmed accountability</div><div class="metric-value"><?= number_format($summary['confirmed'], 0) ?></div><div class="metric-foot success-text">UGX verified</div></article>
        <article class="metric"><div class="metric-label">Pending verification</div><div class="metric-value"><?= number_format($summary['pending'], 0) ?></div><div class="metric-foot">UGX awaiting review</div></article>
        <article class="metric"><div class="metric-label">Unaccounted balance</div><div class="metric-value"><?= number_format(max(0, (float) $summary['allocated'] - (float) $summary['confirmed']), 0) ?></div><div class="metric-foot danger-text">UGX not yet confirmed</div></article>
    </div>
    <section class="panel">
        <div class="panel-heading"><div><h2>Quarterly office report</h2><p class="muted">Filter by financial year, quarter, office, date, and verification status.</p></div></div>
        <form method="get" class="panel-body office-report-filters">
            <input type="hidden" name="tab" value="reports">
            <div><label class="form-label">Financial year</label><select class="form-select" name="financial_year"><option value="">All years</option><?php foreach ($financialYears as $year): ?><option value="<?= ecEscape($year) ?>" <?= $financialYear === $year ? 'selected' : '' ?>><?= ecEscape($year) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label">Quarter</label><select class="form-select" name="quarter"><option value="0">All quarters</option><?php for ($q = 1; $q <= 4; $q++): ?><option value="<?= $q ?>" <?= $quarter === $q ? 'selected' : '' ?>>Quarter <?= $q ?></option><?php endfor; ?></select></div>
            <div><label class="form-label">Office</label><select class="form-select" name="office_id"><option value="0">All offices</option><?php foreach ($offices as $office): ?><option value="<?= (int) $office['id'] ?>" <?= $officeId === (int) $office['id'] ? 'selected' : '' ?>><?= ecEscape($office['name']) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label">Verification</label><select class="form-select" name="review_status"><option value="">All statuses</option><?php foreach (['PENDING', 'CONFIRMED', 'REJECTED'] as $value): ?><option value="<?= $value ?>" <?= $reviewStatus === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label">From date</label><input class="form-control" type="date" name="from" value="<?= ecEscape($from) ?>"></div>
            <div><label class="form-label">To date</label><input class="form-control" type="date" name="to" value="<?= ecEscape($to) ?>"></div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Apply filters</button>
        </form>
        <div class="table-responsive">
            <table class="table mobile-records">
                <thead><tr><th>Office</th><th>Period</th><th>Document</th><th>Amount</th><th>Submitted by</th><th>Verification</th><th>Reason / reviewer</th></tr></thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr><td><strong><?= ecEscape($record['office_name']) ?></strong></td><td data-label="Period"><?= ecEscape($record['financial_year']) ?> · Q<?= (int) $record['quarter'] ?></td><td data-label="Document"><?= ecEscape($record['document_reference']) ?><div class="muted"><?= date('d M Y', strtotime($record['expense_date'])) ?></div></td><td data-label="Amount" class="money"><?= number_format($record['amount'], 0) ?></td><td data-label="Submitted by"><?= ecEscape($record['submitter_name']) ?></td><td data-label="Verification"><span class="review-status review-<?= strtolower($record['review_status']) ?>"><?= ecEscape($record['review_status']) ?></span></td><td data-label="Reason / reviewer"><?= ecEscape($record['review_reason'] ?: ($record['reviewer_name'] ?: 'Awaiting review')) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$records): ?><tr><td colspan="7" class="empty">No accountability records match these filters.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
    (() => {
        const activeTab = <?= json_encode($activeTab) ?>;
        const showTab = (name) => {
            document.querySelectorAll('[data-tab-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.tabPanel !== name;
            });
            document.querySelectorAll('[data-tab]').forEach((tab) => {
                tab.classList.toggle('active', tab.dataset.tab === name);
            });
        };
        document.querySelectorAll('[data-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                showTab(tab.dataset.tab);
                const url = new URL(location.href);
                url.searchParams.set('tab', tab.dataset.tab);
                history.replaceState(null, '', url);
            });
        });
        showTab(activeTab);
    })();
</script>
<?php ecFooter(); ?>
