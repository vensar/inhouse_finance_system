<?php

function ecSpreadsheetUpload(string $path, array $requiredHeaders, int $maximumRows = 5000): array
{
    if (!class_exists("ZipArchive")) {
        throw new DomainException(
            "Excel support is unavailable. Ask the administrator to enable the PHP zip extension.",
        );
    }
    $archive = new ZipArchive();
    if ($archive->open($path) !== true) {
        throw new DomainException(
            "The Excel workbook could not be opened. Download and use the supplied template.",
        );
    }
    try {
        $sharedStrings = [];
        $sharedXml = $archive->getFromName("xl/sharedStrings.xml");
        if ($sharedXml !== false) {
            $shared = simplexml_load_string($sharedXml);
            if ($shared === false) {
                throw new DomainException("The workbook text table is invalid.");
            }
            $shared->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
            foreach ($shared->xpath("//x:si") ?: [] as $item) {
                $item->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
                $parts = [];
                foreach ($item->xpath(".//x:t") ?: [] as $text) {
                    $parts[] = (string) $text;
                }
                $sharedStrings[] = implode("", $parts);
            }
        }
        $sheetXml = $archive->getFromName("xl/worksheets/sheet1.xml");
        if ($sheetXml === false) {
            throw new DomainException("The workbook must contain a first worksheet.");
        }
        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new DomainException("The first worksheet is invalid.");
        }
        $sheet->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
        $matrix = [];
        foreach ($sheet->xpath("//x:sheetData/x:row") ?: [] as $row) {
            $row->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
            $values = [];
            foreach ($row->xpath("./x:c") ?: [] as $cell) {
                $cell->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
                $reference = (string) $cell["r"];
                preg_match("/^[A-Z]+/", $reference, $columnMatch);
                $column = ecSpreadsheetColumnIndex($columnMatch[0] ?? "A");
                $type = (string) $cell["t"];
                if ($type === "inlineStr") {
                    $parts = [];
                    foreach ($cell->xpath(".//x:t") ?: [] as $text) {
                        $parts[] = (string) $text;
                    }
                    $value = implode("", $parts);
                } else {
                    $valueNodes = $cell->xpath("./x:v") ?: [];
                    $raw = (string) ($valueNodes[0] ?? "");
                    $value = $type === "s" ? $sharedStrings[(int) $raw] ?? "" : $raw;
                }
                $values[$column] = trim((string) $value);
            }
            if ($values) {
                $width = max(array_keys($values)) + 1;
                $matrix[] = array_replace(array_fill(0, $width, ""), $values);
            }
        }
    } finally {
        $archive->close();
    }
    if (!$matrix) {
        throw new DomainException("The Excel worksheet is empty.");
    }
    $headers = [];
    foreach ($matrix[0] as $index => $header) {
        $key = strtolower(preg_replace("/[^a-z0-9]/i", "", $header));
        if ($key !== "") {
            if (isset($headers[$key])) {
                throw new DomainException("The Excel sheet has duplicate column headings.");
            }
            $headers[$key] = $index;
        }
    }
    foreach ($requiredHeaders as $header) {
        if (!isset($headers[$header])) {
            throw new DomainException(
                "Missing Excel column: " . $header . ". Download and use the supplied template.",
            );
        }
    }
    $rows = [];
    foreach (array_slice($matrix, 1) as $offset => $cells) {
        $line = $offset + 2;
        $row = [];
        foreach ($requiredHeaders as $header) {
            $row[$header] = trim((string) ($cells[$headers[$header]] ?? ""));
        }
        if (implode("", $row) === "") {
            continue;
        }
        $row["_line"] = $line;
        $rows[] = $row;
        if (count($rows) > $maximumRows) {
            throw new DomainException("Upload at most {$maximumRows} rows per workbook.");
        }
    }
    if (!$rows) {
        throw new DomainException("The Excel sheet contains no payment rows.");
    }
    return $rows;
}

function ecSpreadsheetColumnIndex(string $letters): int
{
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = $index * 26 + ord($letter) - 64;
    }
    return $index - 1;
}

function ecNormalizePhone(string $phone, int $line): string
{
    if (stripos($phone, "e+") !== false) {
        throw new DomainException(
            "Row {$line}: save Phone as text so Excel does not convert it to scientific notation.",
        );
    }
    $normalized = trim($phone);
    if (!preg_match('/^256[0-9]{9}$/D', $normalized)) {
        throw new DomainException(
            "Row {$line}: Phone must contain exactly 12 digits and begin with 256, for example 256788045372.",
        );
    }
    return $normalized;
}
