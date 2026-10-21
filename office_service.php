<?php

require_once __DIR__ . '/accountability_service.php';

function ecCanReviewOfficeAccountability(): bool
{
    return ecIsAccountabilityAdmin() || ($_SESSION['role'] ?? '') === 'accountability_officer';
}

function ecOfficeFinancialYears(): array
{
    $rows = ecQuery(
        "SELECT financial_year FROM quarterly_office_allocations GROUP BY financial_year ORDER BY financial_year DESC",
    )->get_result()->fetch_all(MYSQLI_ASSOC);
    $years = array_column($rows, 'financial_year');
    [$current] = ecFiscalPeriod(date('Y-m-d'));

    if (!in_array($current, $years, true)) {
        array_unshift($years, $current);
    }

    return $years;
}

function ecOfficeScopeSql(string $officeAlias = 'o'): array
{
    $role = $_SESSION['role'] ?? '';
    $districtId = (int) ($_SESSION['district_id'] ?? 0);

    if (in_array($role, ['district_user', 'accountability_officer'], true) && $districtId > 0) {
        return [" AND {$officeAlias}.district_id=?", 'i', [$districtId]];
    }

    return ['', '', []];
}

function ecRequireOfficeAccess(int $officeId): array
{
    $office = ecQuery(
        'SELECT id,name,office_type,district_id,region,is_active FROM offices WHERE id=?',
        'i',
        [$officeId],
    )->get_result()->fetch_assoc();

    if (!$office) {
        throw new DomainException('Choose a valid office.');
    }

    [$scope, $types, $params] = ecOfficeScopeSql('o');
    if ($scope !== '') {
        $allowed = ecQuery(
            "SELECT o.id FROM offices o WHERE o.id=?{$scope}",
            'i' . $types,
            array_merge([$officeId], $params),
        )->get_result()->fetch_assoc();
        if (!$allowed) {
            throw new DomainException('You can maintain records only for offices in your assigned district.');
        }
    }

    return $office;
}

function ecSaveOffice(array $input, int $userId): void
{
    if (!ecIsAccountabilityAdmin()) {
        throw new DomainException('Only administrators can create or update offices.');
    }

    $id = max(0, (int) ($input['office_id'] ?? 0));
    $name = trim((string) ($input['name'] ?? ''));
    $code = strtoupper(trim((string) ($input['office_code'] ?? '')));
    $type = (string) ($input['office_type'] ?? 'OTHER');
    $districtId = max(0, (int) ($input['district_id'] ?? 0)) ?: null;
    $region = trim((string) ($input['region'] ?? ''));
    $address = trim((string) ($input['address'] ?? ''));

    if ($name === '' || mb_strlen($name) > 150) {
        throw new DomainException('Office name is required and must not exceed 150 characters.');
    }
    if (!in_array($type, ['DISTRICT_OFFICE', 'REGIONAL_OFFICE', 'HEAD_OFFICE', 'OTHER'], true)) {
        throw new DomainException('Choose a valid office type.');
    }
    if ($type === 'DISTRICT_OFFICE' && !$districtId) {
        throw new DomainException('A district office must be linked to a district.');
    }

    if ($districtId) {
        $district = ecQuery('SELECT name,region FROM districts WHERE id=?', 'i', [$districtId])
            ->get_result()->fetch_assoc();
        if (!$district) {
            throw new DomainException('Choose a valid district.');
        }
        if ($region === '') {
            $region = (string) $district['region'];
        }
    }

    try {
        if ($id) {
            ecQuery(
                'UPDATE offices SET name=?,office_code=?,office_type=?,district_id=?,region=?,address=? WHERE id=?',
                'sssissi',
                [$name, $code ?: null, $type, $districtId, $region ?: null, $address ?: null, $id],
            );
        } else {
            ecQuery(
                'INSERT INTO offices(name,office_code,office_type,district_id,region,address,created_by) VALUES(?,?,?,?,?,?,?)',
                'sssissi',
                [$name, $code ?: null, $type, $districtId, $region ?: null, $address ?: null, $userId],
            );
        }
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            throw new DomainException('An office with this name or code already exists.');
        }
        throw $exception;
    }
}

function ecToggleOffice(int $officeId): void
{
    if (!ecIsAccountabilityAdmin()) {
        throw new DomainException('Only administrators can activate or deactivate offices.');
    }

    $result = ecQuery('UPDATE offices SET is_active=IF(is_active=1,0,1) WHERE id=?', 'i', [$officeId]);
    if ($result->affected_rows !== 1) {
        throw new DomainException('Office not found.');
    }
}

function ecAddQuarterlyOfficeAllocation(array $input, int $userId): void
{
    if (!ecIsAccountabilityAdmin()) {
        throw new DomainException('Only administrators can record quarterly office funding.');
    }

    $officeId = (int) ($input['office_id'] ?? 0);
    ecRequireOfficeAccess($officeId);
    $date = (string) ($input['allocation_date'] ?? '');
    $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dateValue || $dateValue->format('Y-m-d') !== $date) {
        throw new DomainException('Choose a valid allocation date.');
    }
    [$financialYear, $quarter] = ecFiscalPeriod($date);
    $amount = str_replace(',', '', trim((string) ($input['amount'] ?? '')));
    if (!is_numeric($amount) || (float) $amount <= 0) {
        throw new DomainException('Allocation amount must be greater than zero.');
    }
    $reference = trim((string) ($input['reference_number'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));

    try {
        ecQuery(
            'INSERT INTO quarterly_office_allocations(office_id,financial_year,quarter,allocation_date,amount,reference_number,description,created_by) VALUES(?,?,?,?,?,?,?,?)',
            'isisdssi',
            [$officeId, $financialYear, $quarter, $date, (float) $amount, $reference ?: null, $description ?: null, $userId],
        );
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            throw new DomainException('This quarterly office allocation reference already exists.');
        }
        throw $exception;
    }
}

function ecSubmitQuarterlyOfficeAccountability(array $input, int $userId): void
{
    $allocationId = (int) ($input['allocation_id'] ?? 0);
    $allocation = ecQuery(
        'SELECT a.*,o.district_id,o.name office_name FROM quarterly_office_allocations a JOIN offices o ON o.id=a.office_id WHERE a.id=?',
        'i',
        [$allocationId],
    )->get_result()->fetch_assoc();
    if (!$allocation) {
        throw new DomainException('Choose a valid quarterly allocation.');
    }
    ecRequireOfficeAccess((int) $allocation['office_id']);

    $date = (string) ($input['expense_date'] ?? '');
    $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dateValue || $dateValue->format('Y-m-d') !== $date) {
        throw new DomainException('Choose a valid expenditure date.');
    }
    [$financialYear, $quarter] = ecFiscalPeriod($date);
    if ($financialYear !== $allocation['financial_year'] || $quarter !== (int) $allocation['quarter']) {
        throw new DomainException('The expenditure date must fall within the allocation financial year and quarter.');
    }

    $amount = str_replace(',', '', trim((string) ($input['amount'] ?? '')));
    if (!is_numeric($amount) || (float) $amount <= 0) {
        throw new DomainException('Accountability amount must be greater than zero.');
    }
    $used = ecQuery(
        "SELECT COALESCE(SUM(amount),0) total FROM quarterly_office_accountability WHERE allocation_id=? AND review_status<>'REJECTED'",
        'i',
        [$allocationId],
    )->get_result()->fetch_assoc();
    if ((float) $used['total'] + (float) $amount > (float) $allocation['amount'] + 0.001) {
        throw new DomainException('The submitted accountability would exceed the quarterly allocation.');
    }

    $reference = trim((string) ($input['document_reference'] ?? ''));
    if ($reference === '') {
        throw new DomainException('Enter the supporting document reference.');
    }
    $status = ecIsAccountabilityAdmin() ? 'CONFIRMED' : 'PENDING';
    $reviewer = $status === 'CONFIRMED' ? $userId : null;
    ecQuery(
        'INSERT INTO quarterly_office_accountability(allocation_id,expense_date,document_reference,amount,description,submitted_by,review_status,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,?,?, ?,IF(? IS NULL,NULL,NOW()))',
        'issdsisii',
        [$allocationId, $date, $reference, (float) $amount, trim((string) ($input['description'] ?? '')), $userId, $status, $reviewer, $reviewer],
    );
}

function ecReviewQuarterlyOfficeAccountability(int $recordId, string $decision, string $reason, int $reviewer): void
{
    if (!ecCanReviewOfficeAccountability()) {
        throw new DomainException('You are not authorized to review accountability submissions.');
    }
    if (!in_array($decision, ['CONFIRMED', 'REJECTED'], true)) {
        throw new DomainException('Choose confirm or reject.');
    }
    if ($decision === 'REJECTED' && trim($reason) === '') {
        throw new DomainException('A rejection reason is required.');
    }
    $record = ecQuery(
        'SELECT q.submitted_by,a.office_id FROM quarterly_office_accountability q JOIN quarterly_office_allocations a ON a.id=q.allocation_id WHERE q.id=?',
        'i',
        [$recordId],
    )->get_result()->fetch_assoc();
    if (!$record) {
        throw new DomainException('Accountability submission not found.');
    }
    ecRequireOfficeAccess((int) $record['office_id']);
    if ((int) $record['submitted_by'] === $reviewer) {
        throw new DomainException('You cannot verify an accountability submission that you entered yourself.');
    }
    ecQuery(
        'UPDATE quarterly_office_accountability SET review_status=?,reviewed_by=?,reviewed_at=NOW(),review_reason=? WHERE id=?',
        'sisi',
        [$decision, $reviewer, trim($reason) ?: null, $recordId],
    );
}
