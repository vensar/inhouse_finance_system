<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
requireAdmin();
$message = $_SESSION["upload_notice"] ?? "";
unset($_SESSION["upload_notice"]);
$error = "";
$selected = (int) ($_POST["district_id"] ?? 0);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!is_string($_POST["csrf_token"] ?? null) || !verifyCSRFToken($_POST["csrf_token"])) {
            throw new DomainException(
                empty($_POST) && !empty($_SERVER["CONTENT_LENGTH"])
                    ? "The upload exceeds the server limit. Use a smaller file."
                    : "Session expired. Refresh this page and try again.",
            );
        }
        $f = $_FILES["csv_file"] ?? null;
        if (!$f || $f["error"] !== UPLOAD_ERR_OK) {
            throw new DomainException(
                "The file could not be uploaded. Choose a CSV within the displayed size limit.",
            );
        }
        if (
            strtolower(pathinfo($f["name"], PATHINFO_EXTENSION)) !== "csv" ||
            $f["size"] > 2 * 1024 * 1024
        ) {
            throw new DomainException("Choose a .csv file no larger than 2 MB.");
        }
        $count = ecImportCsv($f["tmp_name"], $selected, (int) $_SESSION["user_id"]);
        $_SESSION["upload_notice"] =
            number_format($count) . " payment records imported successfully.";
        header("Location: upload", true, 303);
        exit();
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log("Payment import: " . $e->getMessage());
        $error =
            "Nothing was imported. The database could not save this file. Please contact the administrator.";
    }
}
$csrf = generateCSRFToken();
$districts = getAllDistricts();
ecHeader("Bulk upload", "upload");
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Data import</div>
        <h1>Bulk upload payments</h1>
        <p class="muted">Validate and import a CSV file in one step.</p>
    </div>
</div>
<?php
if ($message): ?>
<div class="alert alert-success" role="status"><?= ecEscape($message) ?></div>
<?php endif;
if ($error): ?>
<div class="alert alert-danger error-lines" role="alert"><?= ecEscape($error) ?></div>
<?php endif;
?>
<div class="upload-grid">
    <section class="panel">
        <div class="panel-heading"><h2>Upload a payment file</h2></div>
        <form method="post" enctype="multipart/form-data" action="upload" class="panel-body">
            <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>" />
            <div class="mb-4">
                <label for="district_id" class="form-label">District assignment</label>
                <select id="district_id" name="district_id" class="form-select">
                    <option value="0">Use the District column or detect from Narration</option>
                    <?php foreach ($districts as $d): ?>
                    <option value="<?= (int) $d["id"] ?>" <?= $selected == $d["id"]
    ? "selected"
    : "" ?>>
                        <?= ecEscape($d["name"]) ?> — all rows
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted mt-2">
                    For a file from one district, select its name to assign every row explicitly.
                </p>
            </div>
            <div class="mb-4">
                <label for="csv_file" class="form-label">Payment CSV</label>
                <input
                    id="csv_file"
                    type="file"
                    name="csv_file"
                    class="form-control"
                    accept=".csv,text/csv"
                    required
                />
                <p class="muted mt-2">
                    CSV UTF-8 · Up to 10,000 records · Maximum 2 MB (server limit:
                    <?= ecEscape(ini_get("upload_max_filesize")) ?>)
                </p>
            </div>
            <button class="btn btn-primary" type="submit">Validate and import</button>
            <p class="muted mt-3">
                A file with invalid rows is rejected in full. Correct the listed rows and retry.
            </p>
        </form>
    </section>
    <aside class="panel">
        <div class="panel-heading"><h2>Use the right template</h2></div>
        <div class="panel-body">
            <a class="btn btn-outline-secondary mb-2 w-100" href="download_template">
                Download standard CSV
            </a>
            <a
                class="btn btn-outline-secondary mb-4 w-100"
                href="download_template?district_template=1"
            >
                Download CSV with District column
            </a>
            <ul class="upload-help ps-3">
                <li>
                    Keep
                    <strong>Phone, CustName, TranAmount, Status, Narration</strong>
                    . The
                    <strong>District</strong>
                    column is optional.
                </li>
                <li>
                    Use SUCCESS, FAILED, or PENDING. Amounts must be numeric; phone numbers should
                    be saved as text.
                </li>
                <li>
                    Unknown or ambiguous districts must be selected explicitly. Blank customer names
                    are retained for later review.
                </li>
                <li>
                    Save as
                    <strong>CSV UTF-8 (comma delimited)</strong>
                    , not an Excel workbook.
                </li>
                <li>
                    The same file and district selection cannot be imported twice. Different files
                    may still contain repeated payments.
                </li>
            </ul>
        </div>
    </aside>
</div>
<script>
    document.querySelector("form").addEventListener("submit", function () {
        const b = this.querySelector("button");
        b.disabled = true;
        b.textContent = "Validating and importing…";
    });
</script>
<?php ecFooter(); ?>
