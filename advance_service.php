<?php

require_once __DIR__ . '/accountability_service.php';

function ecAdvanceType(string $value): string
{
    $value = strtoupper(trim($value));
    if (!in_array($value, ['TRAVEL','INDIVIDUAL'], true)) {
        throw new DomainException('Choose Travel Advances or Individual Advances.');
    }
    return $value;
}

function ecAdvanceDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new DomainException('Choose a valid advance date.');
    }
    return $value;
}

function ecAdvanceOfficeMap(): array
{
    $rows = ecQuery("SELECT id,name FROM districts WHERE source_code IS NOT NULL")->get_result()->fetch_all(MYSQLI_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[mb_strtoupper(trim($row['name']))] = (int)$row['id'];
    }
    return $map;
}

function ecAdvanceOfficeName(string $value): string
{
    $value = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    $value = preg_replace('/\s+(PA|PC|PO|BVVK)$/', '', $value);
    $aliases = ['KOLE' => 'KOOLE','KIKUBE' => 'KIKUUBE','ADJUMAN' => 'ADJUMANI','KIBALE' => 'KIBAALE','BULISA' => 'BULIISA','KYENJONJO' => 'KYENJOJO','WAKKISO' => 'WAKISO','KASASE' => 'KASESE','BULAMBULU' => 'BULAMBULI','KAMAPALA' => 'KAMPALA','HOMA' => 'HOIMA','KIRYANDINGO' => 'KIRYANDONGO'];
    return $aliases[$value] ?? $value;
}

function ecReadAdvanceMapping(?array $file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        throw new DomainException('Choose a beneficiary mapping .csv file up to 5 MB.');
    }
    $handle = fopen($file['tmp_name'], 'rb');
    if (!$handle) {
        throw new DomainException('The beneficiary mapping file could not be read.');
    }
    $map = [];
    $line = 0;
    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $line++;
        if (count($cells) < 3) {
            continue;
        }
        $account = preg_replace('/\D/', '', (string)$cells[0]);
        $office = trim((string)$cells[2]);
        if ($line === 1 && preg_match('/account|phone/i', (string)$cells[0])) {
            continue;
        }
        if ($account !== '' && $office !== '') {
            $map[$account] = ['name' => trim((string)$cells[1]),'office' => $office];
        }
        if (count($map) > 20000) {
            throw new DomainException('The mapping file may contain at most 20,000 rows.');
        }
    }
    fclose($handle);
    return $map;
}

function ecReadAdvanceWorkbook(string $path, string $filename): array
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === 'xls') {
        throw new DomainException('The supplied file is an older .xls workbook. Open it in Excel and save it as .xlsx, or download the advance template.');
    }
    if ($extension === 'xlsx') {
        try {
            return ecSpreadsheetUpload($path, ['beneficiaryaccount','beneficiaryname','advanceamount','office','referencenumber','description'], 10000);
        } catch (DomainException $templateError) {
            try {
                $legacy = ecSpreadsheetUpload($path, ['beneficaryaccount','beneficiaryname','amount','district'], 10000);
                return array_map(fn ($row) => ['beneficiaryaccount' => $row['beneficaryaccount'],'beneficiaryname' => $row['beneficiaryname'],'advanceamount' => $row['amount'],'office' => $row['district'],'referencenumber' => '','description' => '','_line' => $row['_line']], $legacy);
            } catch (DomainException $legacyError) {
                throw $templateError;
            }
        }
    }
    if ($extension !== 'csv') {
        throw new DomainException('Choose an .xlsx or .csv advance payment file.');
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new DomainException('The advance payment file could not be read.');
    }
    $first = fgetcsv($handle, 0, ',', '"', '');
    if (!$first) {
        throw new DomainException('The advance payment file is empty.');
    }
    $normalized = array_map(fn ($v) => strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string)$v))), $first);
    $hasHeader = in_array('beneficiaryaccount', $normalized, true) || in_array('beneficaryaccount', $normalized, true);
    $rows = [];
    $line = 1;
    if (!$hasHeader) {
        rewind($handle);
    }
    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $line++;
        if (implode('', array_map('trim', $cells)) === '') {
            continue;
        }
        $rows[] = ['beneficiaryaccount' => trim((string)($cells[0] ?? '')),'beneficiaryname' => trim((string)($cells[1] ?? '')),'advanceamount' => trim((string)($cells[2] ?? '')),'office' => trim((string)($cells[3] ?? '')),'referencenumber' => trim((string)($cells[4] ?? '')),'description' => trim((string)($cells[5] ?? '')),'_line' => $line];
        if (count($rows) > 10000) {
            throw new DomainException('Upload at most 10,000 advance rows per file.');
        }
    }
    fclose($handle);
    return $rows;
}

function ecImportAdvances(string $path, string $filename, ?array $mappingFile, string $type, string $date, int $userId): array
{
    $type = ecAdvanceType($type);
    $date = ecAdvanceDate($date);
    [$fy,$quarter] = ecFiscalPeriod($date);
    $source = ecReadAdvanceWorkbook($path, $filename);
    $mapping = ecReadAdvanceMapping($mappingFile);
    $offices = ecAdvanceOfficeMap();
    $assigned = ecAccountabilityOfficeId();
    $rows = [];
    $total = 0;
    $unmapped = 0;
    foreach ($source as $row) {
        $line = (int)$row['_line'];
        $rawAccount = trim((string)$row['beneficiaryaccount']);
        if (preg_match('/\b(?:SUB\s*TOTAL|GRAND\s*TOTAL|TOTAL)\b/i', $rawAccount) || (trim((string)$row['advanceamount']) === '' && !preg_match('/^\d{7,15}$/D', $rawAccount))) {
            continue;
        }
        $account = preg_replace('/\D/', '', $rawAccount);
        $name = trim((string)$row['beneficiaryname']);
        if ($name === '') {
            $name = 'NO NAME';
        }
        $amount = str_replace([',',' '], '', (string)$row['advanceamount']);
        if ($account === '' || strlen($account) > 30) {
            throw new DomainException("Row {$line}: Beneficiary Account is required.");
        }
        if (!is_numeric($amount) || (float)$amount <= 0) {
            throw new DomainException("Row {$line}: Advance Amount must be greater than zero.");
        }
        if (mb_strlen($name) > 150) {
            throw new DomainException("Row {$line}: Beneficiary Name is too long.");
        }
        $mapped = $mapping[$account] ?? null;
        $officeLabel = trim((string)$row['office']);
        if ($officeLabel === '' && $mapped) {
            $officeLabel = $mapped['office'];
        }
        $officeName = ecAdvanceOfficeName($officeLabel);
        $officeId = $offices[$officeName] ?? null;
        if ($assigned !== null) {
            if ($officeId === null) {
                $officeId = $assigned;
            } ecAssertAccountabilityOffice((int)$officeId);
        }
        if ($officeId === null) {
            $unmapped++;
        }
        $reference = trim((string)$row['referencenumber']);
        $description = trim((string)$row['description']);
        if (mb_strlen($reference) > 100 || mb_strlen($description) > 500) {
            throw new DomainException("Row {$line}: Reference Number or Description is too long.");
        }
        $rows[] = [$officeId,$officeLabel,$account,$name,number_format((float)$amount, 2, '.', ''),$reference,$description];
        $total += (float)$amount;
    }
    if (!$rows) {
        throw new DomainException('The advance payment file contains no records.');
    }
    $hash = hash_file('sha256', $path);
    $db = getDBConnection();
    $db->begin_transaction();
    try {
        ecQuery('INSERT INTO advance_uploads(advance_type,advance_date,financial_year,quarter,original_filename,mapping_filename,file_hash,row_count,total_amount,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?)', 'sssisssidi', [$type,$date,$fy,$quarter,basename($filename),$mappingFile ? basename($mappingFile['name']) : null,$hash,count($rows),$total,$userId]);
        $uploadId = $db->insert_id;
        foreach ($rows as [$officeId,$officeLabel,$account,$name,$amount,$reference,$description]) {
            ecQuery('INSERT INTO advance_records(upload_id,advance_type,office_id,office_label,beneficiary_account,beneficiary_name,advance_amount,advance_date,reference_number,description,financial_year,quarter) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', 'isisssdssssi', [$uploadId,$type,$officeId,$officeLabel,$account,$name,$amount,$date,$reference,$description,$fy,$quarter]);
        }
        $db->commit();
        return ['count' => count($rows),'total' => $total,'unmapped' => $unmapped];
    } catch (mysqli_sql_exception $e) {
        $db->rollback();
        if ((int)$e->getCode() === 1062) {
            throw new DomainException('This advance file has already been uploaded under the selected type.');
        }throw $e;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function ecSetAdvanceAccounted(int $id, string $amount, string $note, int $reviewer): void
{
    $row = ecQuery('SELECT office_id,advance_amount FROM advance_records WHERE id=?', 'i', [$id])->get_result()->fetch_assoc();
    if (!$row) {
        throw new DomainException('Advance record not found.');
    } if ($row['office_id'] !== null) {
        ecAssertAccountabilityOffice((int)$row['office_id']);
    }
    $amount = str_replace(',', '', trim($amount));
    if ($amount === '' || !is_numeric($amount) || (float)$amount < 0) {
        throw new DomainException('Accounted amount must be zero or greater.');
    }
    $amount = round((float)$amount, 2);
    $advance = round((float)$row['advance_amount'], 2);
    if ($amount > $advance) {
        throw new DomainException('Accounted amount cannot exceed the advance amount.');
    }
    $status = abs($amount - $advance) < 0.005 ? 'ACCOUNTED' : 'UNACCOUNTED';
    ecQuery('UPDATE advance_records SET accounted_amount=?,accountability_status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?','dssii',[$amount,$status,trim($note),$reviewer,$id]);
}
