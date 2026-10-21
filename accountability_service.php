<?php

require_once __DIR__ . '/xlsx_service.php';
function ecIsAccountabilityAdmin(): bool
{
    return ($_SESSION['role'] ?? '') === 'accountability_admin' || isAdmin();
}
function ecCanUseAccountability(): bool
{
    return in_array($_SESSION['role'] ?? '', ['admin','accountability_admin','accountability_officer','district_user'], true);
}
function ecAccountabilityOfficeId(): ?int
{
    return in_array($_SESSION['role'] ?? '', ['accountability_officer','district_user'], true) ? (int)($_SESSION['district_id'] ?? 0) : null;
}
function ecRequireAccountability(): void
{
    requireLogin();
    if (!ecCanUseAccountability()) {
        http_response_code(403);
        exit('Access denied. Accountability users only.');
    }if (in_array($_SESSION['role'] ?? '', ['accountability_officer','district_user'], true) && !ecAccountabilityOfficeId()) {
        http_response_code(403);
        exit('Your accountability account needs an office assignment. Please contact an administrator.');
    }
}
function ecAssertAccountabilityOffice(int $officeId): void
{
    $assigned = ecAccountabilityOfficeId();
    if ($assigned !== null && $assigned !== $officeId) {
        throw new DomainException('You can maintain accountability records only for your assigned office.');
    }
}
function ecFiscalPeriod(string $date): array
{
    $d = new DateTimeImmutable($date);
    $y = (int)$d->format('Y');
    $m = (int)$d->format('n');
    $start = $m >= 7 ? $y : $y - 1;
    $q = $m >= 7 ? (int)floor(($m - 7) / 3) + 1 : (int)floor(($m + 5) / 3) + 1;
    return [$start.'/'.($start + 1),$q];
}
function ecParseInvoiceDate(string $value, int $line): string
{
    $value = trim($value);
    if (is_numeric($value)) {
        $date = (new DateTimeImmutable('1899-12-30'))->modify('+'.(int)floor((float)$value).' days');
        return $date->format('Y-m-d');
    } if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T/', $value)) {
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable $ignored) {
        }
    } foreach (['Y-m-d','d/m/Y','d-m-Y','m/d/Y'] as $f) {
        $d = DateTimeImmutable::createFromFormat('!'.$f, $value);
        if ($d && $d->format($f) === $value) {
            return $d->format('Y-m-d');
        }
    }throw new DomainException("Row {$line}: Invoice Date must be a valid Excel date or DD/MM/YYYY date.");
}
function ecAddAccountabilityAllocation(int $officeId, string $date, string $amount, string $eft, string $description, int $userId): void
{
    $office = ecQuery('SELECT id FROM districts WHERE id=?', 'i', [$officeId])->get_result()->fetch_assoc();
    if (!$office) {
        throw new DomainException('Choose a valid office.');
    }$d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        throw new DomainException('Choose a valid allocation date.');
    }$amount = str_replace(',', '', trim($amount));
    if (!is_numeric($amount) || (float)$amount <= 0) {
        throw new DomainException('Allocation amount must be greater than zero.');
    }[$fy,$q] = ecFiscalPeriod($date);
    ecQuery('INSERT INTO accountability_allocations(office_id,allocation_date,financial_year,quarter,amount,eft_number,description,created_by) VALUES(?,?,?,?,?,?,?,?)', 'issidssi', [$officeId,$date,$fy,$q,number_format((float)$amount, 2, '.', ''),trim($eft),trim($description),$userId]);
}
function ecImportAccountabilityWorkbook(string $path, string $filename, string $month, int $officeId, int $userId): int
{
    ecAssertAccountabilityOffice($officeId);
    $m = DateTimeImmutable::createFromFormat('!Y-m', $month);
    if (!$m || $m->format('Y-m') !== $month) {
        throw new DomainException('Choose a valid reporting month.');
    }$office = ecQuery('SELECT name FROM districts WHERE id=?', 'i', [$officeId])->get_result()->fetch_assoc();
    if (!$office) {
        throw new DomainException('Your account needs an office assignment.');
    }$source = ecSpreadsheetUpload($path, ['district','invoicedate','invoicenumber','eftnumber','invoiceamount','description']);
    $rows = [];
    $total = 0;
    foreach ($source as $r) {
        $line = $r['_line'];
        if (strtoupper(trim($r['district'])) !== strtoupper($office['name'])) {
            throw new DomainException("Row {$line}: District must be {$office['name']}.");
        }$date = ecParseInvoiceDate($r['invoicedate'], $line);
        if (substr($date, 0, 7) !== $month) {
            throw new DomainException("Row {$line}: Invoice Date must fall within {$month}.");
        }$invoice = trim($r['invoicenumber']);
        $eft = trim($r['eftnumber']);
        if ($invoice === '' || mb_strlen($invoice) > 100 || $eft === '' || mb_strlen($eft) > 100) {
            throw new DomainException("Row {$line}: Invoice Number and EFT Number are required.");
        }$amount = str_replace(',', '', trim($r['invoiceamount']));
        if (!is_numeric($amount) || (float)$amount <= 0) {
            throw new DomainException("Row {$line}: Invoice Amount must be greater than zero.");
        }$desc = trim($r['description']);
        if (mb_strlen($desc) > 500) {
            throw new DomainException("Row {$line}: Description is too long.");
        }[$fy,$q] = ecFiscalPeriod($date);
        $amount = number_format((float)$amount, 2, '.', '');
        $total += (float)$amount;
        $allocation = ecQuery('SELECT a.id,a.amount,COALESCE((SELECT SUM(e.invoice_amount) FROM accountability_expenses e WHERE e.allocation_id=a.id),0) used FROM accountability_allocations a WHERE a.office_id=? AND a.eft_number=?', 'is', [$officeId,$eft])->get_result()->fetch_assoc();
        if (!$allocation) {
            throw new DomainException("Row {$line}: EFT Number {$eft} has not been uploaded for {$office['name']}.");
        }$rows[] = [$date,$invoice,$eft,$amount,$desc,$fy,$q,(int)$allocation['id'],(float)$allocation['amount'],(float)$allocation['used']];
    }$hash = hash_file('sha256', $path);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery('INSERT INTO accountability_uploads(office_id,reporting_month,uploaded_by,original_filename,file_hash,row_count,total_amount) VALUES(?,?,?,?,?,?,?)', 'isissid', [$officeId,$m->format('Y-m-01'),$userId,basename($filename),$hash,count($rows),$total]);
        $upload = $db->insert_id;
        $batchUsage = [];
        foreach ($rows as $row) {
            $allocationId = $row[7];
            $batchUsage[$allocationId] = ($batchUsage[$allocationId] ?? 0) + (float)$row[3];
            if ($row[9] + $batchUsage[$allocationId] > $row[8] + 0.001) {
                throw new DomainException("EFT {$row[2]} expenditure exceeds its uploaded EFT amount.");
            }
        }foreach ($rows as [$date,$invoice,$eft,$amount,$desc,$fy,$q,$allocationId]) {
            ecQuery('INSERT INTO accountability_expenses(upload_id,allocation_id,office_id,invoice_date,invoice_number,eft_number,invoice_amount,description,financial_year,quarter) VALUES(?,?,?,?,?,?,?,?,?,?)', 'iiisssdssi', [$upload,$allocationId,$officeId,$date,$invoice,$eft,$amount,$desc,$fy,$q]);
        }$db->commit();
        return count($rows);
    } catch (mysqli_sql_exception $e) {
        $db->rollback();
        if ((int)$e->getCode() === 1062) {
            throw new DomainException('This workbook has already been uploaded for the selected month.');
        }throw $e;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
function ecSetExpenseAccounted(int $id, string $amount, string $note, int $reviewer): void
{
    $expense = ecQuery('SELECT office_id,invoice_amount FROM accountability_expenses WHERE id=?', 'i', [$id])->get_result()->fetch_assoc();
    if (!$expense) {
        throw new DomainException('Expenditure record not found.');
    }ecAssertAccountabilityOffice((int)$expense['office_id']);
    $amount = str_replace(',', '', trim($amount));
    if ($amount === '' || !is_numeric($amount) || (float)$amount < 0) {
        throw new DomainException('Accountability amount must be zero or greater.');
    }$amount = round((float)$amount, 2);
    $invoice = round((float)$expense['invoice_amount'], 2);
    if ($amount > $invoice) {
        throw new DomainException('Accountability amount cannot exceed the expenditure amount.');
    }$status = abs($amount - $invoice) < 0.005 ? 'ACCOUNTED' : 'UNACCOUNTED';
    $verification = ecIsAccountabilityAdmin() ? 'CONFIRMED' : 'PENDING';
    $verifiedBy = $verification === 'CONFIRMED' ? $reviewer : null;
    ecQuery('UPDATE accountability_expenses SET accounted_amount=?,accountability_status=?,review_note=?,submitted_by=?,submitted_at=NOW(),verification_status=?,verified_by=?,verified_at=IF(? IS NULL,NULL,NOW()),rejection_reason=NULL WHERE id=?', 'dssisiii', [$amount,$status,trim($note),$reviewer,$verification,$verifiedBy,$verifiedBy,$id]);
}

function ecReviewExpenseSubmission(int $id, string $decision, string $reason, int $reviewer): void
{
    if (!in_array($_SESSION['role'] ?? '', ['admin','accountability_admin','accountability_officer'], true)) {
        throw new DomainException('You are not authorized to verify district accountability.');
    }
    if (!in_array($decision, ['CONFIRMED','REJECTED'], true)) {
        throw new DomainException('Choose confirm or reject.');
    }
    if ($decision === 'REJECTED' && trim($reason) === '') {
        throw new DomainException('A rejection reason is required.');
    }
    $expense = ecQuery('SELECT office_id,submitted_by FROM accountability_expenses WHERE id=?', 'i', [$id])->get_result()->fetch_assoc();
    if (!$expense) {
        throw new DomainException('Expenditure record not found.');
    }
    ecAssertAccountabilityOffice((int)$expense['office_id']);
    if ((int)$expense['submitted_by'] === $reviewer) {
        throw new DomainException('You cannot verify accountability that you submitted yourself.');
    }
    ecQuery('UPDATE accountability_expenses SET verification_status=?,verified_by=?,verified_at=NOW(),rejection_reason=? WHERE id=?', 'sisi', [$decision,$reviewer,trim($reason) ?: null,$id]);
}
function ecAccountabilitySummary(string $fy, int $quarter, ?string $region, ?int $officeId): array
{
    $assigned = ecAccountabilityOfficeId();
    if ($assigned !== null) {
        $officeId = $assigned;
        $region = null;
    }$params = [$fy];
    $types = 's';
    $aq = 'a.financial_year=?';
    $eq = 'e.financial_year=?';
    if ($quarter) {
        $aq .= ' AND a.quarter=?';
        $eq .= ' AND e.quarter=?';
        $params[] = $quarter;
        $types .= 'i';
    }$officeWhere = '';
    if ($region) {
        $officeWhere .= ' AND d.region=?';
        $params[] = $region;
        $types .= 's';
    }if ($officeId) {
        $officeWhere .= ' AND d.id=?';
        $params[] = $officeId;
        $types .= 'i';
    }$sql = "SELECT d.id,d.name,d.region,d.office_type,COALESCE(al.allocated,0) allocated,COALESCE(ex.spent,0) spent,COALESCE(ex.accounted,0) accounted,COALESCE(ex.unaccounted,0) unaccounted,COALESCE(al.allocated,0)-COALESCE(ex.spent,0) unutilised FROM districts d LEFT JOIN (SELECT office_id,SUM(amount) allocated FROM accountability_allocations a WHERE $aq GROUP BY office_id) al ON al.office_id=d.id LEFT JOIN (SELECT office_id,SUM(invoice_amount) spent,SUM(accounted_amount) accounted,SUM(invoice_amount-accounted_amount) unaccounted FROM accountability_expenses e WHERE $eq GROUP BY office_id) ex ON ex.office_id=d.id WHERE (al.allocated IS NOT NULL OR ex.spent IS NOT NULL)$officeWhere ORDER BY d.region,d.name";
    $sqlParams = [];
    $sqlTypes = '';
    $sqlParams[] = $fy;
    $sqlTypes .= 's';
    if ($quarter) {
        $sqlParams[] = $quarter;
        $sqlTypes .= 'i';
    }$sqlParams[] = $fy;
    $sqlTypes .= 's';
    if ($quarter) {
        $sqlParams[] = $quarter;
        $sqlTypes .= 'i';
    }if ($region) {
        $sqlParams[] = $region;
        $sqlTypes .= 's';
    }if ($officeId) {
        $sqlParams[] = $officeId;
        $sqlTypes .= 'i';
    }return ecQuery($sql, $sqlTypes, $sqlParams)->get_result()->fetch_all(MYSQLI_ASSOC);
}

function ecImportEftWorkbook(string $path, string $filename, int $userId): int
{
    $source = ecSpreadsheetUpload($path, ['office','eftdate','eftnumber','eftamount','description']);
    $rows = [];
    $total = 0;
    foreach ($source as $r) {
        $line = $r['_line'];
        $officeName = strtoupper(trim($r['office']));
        $office = ecQuery('SELECT id,name FROM districts WHERE UPPER(name)=?', 's', [$officeName])->get_result()->fetch_assoc();
        if (!$office) {
            throw new DomainException("Row {$line}: Office {$officeName} is not recognized.");
        }$date = ecParseInvoiceDate($r['eftdate'], $line);
        $eft = trim($r['eftnumber']);
        if ($eft === '' || mb_strlen($eft) > 100) {
            throw new DomainException("Row {$line}: EFT Number is required.");
        }$amount = str_replace(',', '', trim($r['eftamount']));
        if (!is_numeric($amount) || (float)$amount <= 0) {
            throw new DomainException("Row {$line}: EFT Amount must be greater than zero.");
        }$desc = trim($r['description']);
        [$fy,$q] = ecFiscalPeriod($date);
        $amount = number_format((float)$amount, 2, '.', '');
        $total += (float)$amount;
        $rows[] = [(int)$office['id'],$date,$fy,$q,$amount,$eft,$desc];
    }$hash = hash_file('sha256', $path);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery('INSERT INTO accountability_eft_uploads(uploaded_by,original_filename,file_hash,row_count,total_amount) VALUES(?,?,?,?,?)', 'issid', [$userId,basename($filename),$hash,count($rows),$total]);
        $upload = $db->insert_id;
        foreach ($rows as [$office,$date,$fy,$q,$amount,$eft,$desc]) {
            ecQuery('INSERT INTO accountability_allocations(upload_id,office_id,allocation_date,financial_year,quarter,amount,eft_number,description,created_by) VALUES(?,?,?,?,?,?,?,?,?)', 'iissidssi', [$upload,$office,$date,$fy,$q,$amount,$eft,$desc,$userId]);
        }$db->commit();
        return count($rows);
    } catch (mysqli_sql_exception $e) {
        $db->rollback();
        if ((int)$e->getCode() === 1062) {
            throw new DomainException('This EFT file or one of its office/EFT numbers has already been uploaded.');
        }throw $e;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
function ecAccountabilitySummaryByDate(string $from, string $to, ?string $region, ?int $officeId): array
{
    $assigned = ecAccountabilityOfficeId();
    if ($assigned !== null) {
        $officeId = $assigned;
        $region = null;
    }$f = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$f || !$t || $f > $t) {
        throw new DomainException('Choose a valid date range.');
    }$end = $t->modify('+1 day')->format('Y-m-d');
    $officeWhere = '';
    $params = [$from,$end,$from,$end];
    $types = 'ssss';
    if ($region) {
        $officeWhere .= ' AND d.region=?';
        $params[] = $region;
        $types .= 's';
    }if ($officeId) {
        $officeWhere .= ' AND d.id=?';
        $params[] = $officeId;
        $types .= 'i';
    }$sql = "SELECT d.id,d.name,d.region,d.office_type,COALESCE(al.allocated,0) allocated,COALESCE(ex.spent,0) spent,COALESCE(ex.accounted,0) accounted,COALESCE(ex.unaccounted,0) unaccounted,COALESCE(al.allocated,0)-COALESCE(ex.spent,0) unutilised FROM districts d LEFT JOIN (SELECT office_id,SUM(amount) allocated FROM accountability_allocations WHERE allocation_date>=? AND allocation_date<? GROUP BY office_id) al ON al.office_id=d.id LEFT JOIN (SELECT office_id,SUM(invoice_amount) spent,SUM(accounted_amount) accounted,SUM(invoice_amount-accounted_amount) unaccounted FROM accountability_expenses WHERE invoice_date>=? AND invoice_date<? GROUP BY office_id) ex ON ex.office_id=d.id WHERE (al.allocated IS NOT NULL OR ex.spent IS NOT NULL)$officeWhere ORDER BY d.region,d.name";
    return ecQuery($sql,$types,$params)->get_result()->fetch_all(MYSQLI_ASSOC);
}
