<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/office_service.php';

ecRequireAccountability();

$financialYear = trim((string) ($_GET['financial_year'] ?? ''));
$quarter = in_array((int) ($_GET['quarter'] ?? 0), [1, 2, 3, 4], true) ? (int) $_GET['quarter'] : 0;
$officeId = max(0, (int) ($_GET['office_id'] ?? 0));
$reviewStatus = in_array($_GET['review_status'] ?? '', ['PENDING', 'CONFIRMED', 'REJECTED'], true)
    ? $_GET['review_status']
    : '';
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$where = ['1=1'];
$types = '';
$params = [];

foreach ([
    [$financialYear !== '', 'a.financial_year=?', 's', $financialYear],
    [$quarter > 0, 'a.quarter=?', 'i', $quarter],
    [$officeId > 0, 'a.office_id=?', 'i', $officeId],
    [$reviewStatus !== '', 'q.review_status=?', 's', $reviewStatus],
    [$from !== '', 'q.expense_date>=?', 's', $from],
    [$to !== '', 'q.expense_date<=?', 's', $to],
] as [$include, $clause, $type, $value]) {
    if ($include) {
        $where[] = $clause;
        $types .= $type;
        $params[] = $value;
    }
}
[$scope, $scopeTypes, $scopeParams] = ecOfficeScopeSql('o');
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);
$rows = ecQuery(
    "SELECT o.name office_name,o.office_code,o.office_type,o.region,a.financial_year,a.quarter,
        a.amount allocation_amount,a.reference_number allocation_reference,q.expense_date,
        q.document_reference,q.description,q.amount,q.review_status,q.review_reason,
        submitter.full_name submitter_name,reviewer.full_name reviewer_name,q.submitted_at,q.reviewed_at
     FROM quarterly_office_accountability q
     JOIN quarterly_office_allocations a ON a.id=q.allocation_id
     JOIN offices o ON o.id=a.office_id
     JOIN users submitter ON submitter.id=q.submitted_by
     LEFT JOIN users reviewer ON reviewer.id=q.reviewed_by
     WHERE " . implode(' AND ', $where) . $scope . '
     ORDER BY o.name,a.financial_year,a.quarter,q.expense_date',
    $types,
    $params,
)->get_result()->fetch_all(MYSQLI_ASSOC);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="quarterly-office-accountability-' . date('Ymd-His') . '.csv"');
echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'wb');
fputcsv($output, [
    'Office', 'Office Code', 'Office Type', 'Region', 'Financial Year', 'Quarter',
    'Quarterly Allocation', 'Allocation Reference', 'Expense Date', 'Document Reference',
    'Description', 'Accounted Amount', 'Verification Status', 'Rejection/Review Reason',
    'Submitted By', 'Submitted At', 'Reviewed By', 'Reviewed At',
]);
foreach ($rows as $row) {
    fputcsv($output, [
        $row['office_name'], $row['office_code'], $row['office_type'], $row['region'],
        $row['financial_year'], $row['quarter'], $row['allocation_amount'],
        $row['allocation_reference'], $row['expense_date'], $row['document_reference'],
        $row['description'], $row['amount'], $row['review_status'], $row['review_reason'],
        $row['submitter_name'], $row['submitted_at'], $row['reviewer_name'], $row['reviewed_at'],
    ]);
}
fclose($output);
exit;
