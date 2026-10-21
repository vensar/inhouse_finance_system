<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/office_service.php';

ecRequireAccountability();

$error = '';
$notice = $_SESSION['office_notice'] ?? '';
unset($_SESSION['office_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new DomainException('Session expired. Reload and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            ecSaveOffice($_POST, (int) $_SESSION['user_id']);
            $notice = (int) ($_POST['office_id'] ?? 0) ? 'Office updated.' : 'Office created.';
        } elseif ($action === 'toggle') {
            ecToggleOffice((int) ($_POST['office_id'] ?? 0));
            $notice = 'Office status updated.';
        } else {
            throw new DomainException('Choose a valid office action.');
        }
        $_SESSION['office_notice'] = $notice;
        header('Location: offices', true, 303);
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Offices: ' . $exception->getMessage());
        $error = 'The office request could not be completed.';
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$type = in_array($_GET['type'] ?? '', ['DISTRICT_OFFICE', 'REGIONAL_OFFICE', 'HEAD_OFFICE', 'OTHER'], true)
    ? $_GET['type']
    : '';
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$where = [];
$types = '';
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(o.name LIKE ? OR o.office_code LIKE ? OR o.region LIKE ? OR d.name LIKE ?)';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($type !== '') {
    $where[] = 'o.office_type=?';
    $types .= 's';
    $params[] = $type;
}
if ($status !== '') {
    $where[] = 'o.is_active=?';
    $types .= 'i';
    $params[] = $status === 'active' ? 1 : 0;
}
[$scopeSql, $scopeTypes, $scopeParams] = ecOfficeScopeSql('o');
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : ' WHERE 1=1';
$whereSql .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$offices = ecQuery(
    'SELECT o.*,d.name district_name FROM offices o LEFT JOIN districts d ON d.id=o.district_id' .
        $whereSql .
        ' ORDER BY o.is_active DESC,o.region,o.name',
    $types,
    $params,
)->get_result()->fetch_all(MYSQLI_ASSOC);
$districts = ecQuery(
    "SELECT id,name,region FROM districts WHERE office_type='DISTRICT' ORDER BY region,name",
)->get_result()->fetch_all(MYSQLI_ASSOC);
$csrf = generateCSRFToken();
session_write_close();

ecHeader('Office registry', 'offices');
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Administration</div>
        <h1>Office registry</h1>
        <p class="muted">Maintain operational offices separately from districts. A district may have an office linked to it.</p>
    </div>
    <a class="btn btn-primary" href="quarterly_office_accountability">
        <i class="bi bi-calendar3"></i> Quarterly office Imprest
    </a>
</div>

<?php if ($notice): ?>
    <div class="alert alert-success"><?= ecEscape($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= ecEscape($error) ?></div>
<?php endif; ?>

<?php if (ecIsAccountabilityAdmin()): ?>
    <section class="panel mb-4">
        <div class="panel-heading">
            <div>
                <h2>Add or update an office</h2>
                <p class="muted">District offices can be linked to an existing district; other office types remain independent.</p>
            </div>
        </div>
        <form method="post" class="panel-body office-form-grid" id="office-form">
            <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="office_id" id="office-id" value="">
            <div>
                <label class="form-label" for="office-name">Office name</label>
                <input class="form-control" id="office-name" name="name" maxlength="150" required>
            </div>
            <div>
                <label class="form-label" for="office-code">Office code</label>
                <input class="form-control" id="office-code" name="office_code" maxlength="30">
            </div>
            <div>
                <label class="form-label" for="office-type">Office type</label>
                <select class="form-select" id="office-type" name="office_type" required>
                    <option value="DISTRICT_OFFICE">District office</option>
                    <option value="REGIONAL_OFFICE">Regional office</option>
                    <option value="HEAD_OFFICE">Head office</option>
                    <option value="OTHER">Other office</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="office-district">Linked district</label>
                <select class="form-select" id="office-district" name="district_id">
                    <option value="">No linked district</option>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?= (int) $district['id'] ?>">
                            <?= ecEscape($district['name'] . ' · ' . $district['region']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="office-region">Region</label>
                <input class="form-control" id="office-region" name="region" maxlength="80">
            </div>
            <div>
                <label class="form-label" for="office-address">Address or location</label>
                <input class="form-control" id="office-address" name="address" maxlength="255">
            </div>
            <div class="d-flex gap-2 align-items-end">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-building-add"></i> Save office
                </button>
                <button class="btn btn-outline-secondary" type="button" id="clear-office-form">Clear</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>All offices</h2>
            <p class="muted"><?= number_format(count($offices)) ?> offices match the current filters.</p>
        </div>
    </div>
    <form method="get" class="panel-body filters office-filter-grid">
        <div>
            <label class="form-label" for="office-search">Search</label>
            <input class="form-control" id="office-search" name="q" value="<?= ecEscape($search) ?>" placeholder="Name, code, district or region">
        </div>
        <div>
            <label class="form-label" for="filter-type">Office type</label>
            <select class="form-select" id="filter-type" name="type">
                <option value="">All types</option>
                <?php foreach (['DISTRICT_OFFICE' => 'District office', 'REGIONAL_OFFICE' => 'Regional office', 'HEAD_OFFICE' => 'Head office', 'OTHER' => 'Other office'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $type === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="filter-status">Status</label>
            <select class="form-select" id="filter-status" name="status">
                <option value="">All statuses</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Filter</button>
    </form>
    <div class="table-responsive">
        <table class="table mobile-records">
            <thead>
                <tr>
                    <th>Office</th>
                    <th>Type</th>
                    <th>Linked district</th>
                    <th>Region</th>
                    <th>Status</th>
                    <?php if (ecIsAccountabilityAdmin()): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offices as $office): ?>
                    <tr>
                        <td>
                            <strong><?= ecEscape($office['name']) ?></strong>
                            <div class="muted"><?= ecEscape($office['office_code'] ?: 'No office code') ?></div>
                        </td>
                        <td data-label="Type"><?= ecEscape(ucwords(strtolower(str_replace('_', ' ', $office['office_type'])))) ?></td>
                        <td data-label="Linked district"><?= ecEscape($office['district_name'] ?: 'Independent office') ?></td>
                        <td data-label="Region"><?= ecEscape($office['region'] ?: '—') ?></td>
                        <td data-label="Status">
                            <span class="lot-status <?= $office['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $office['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                            </span>
                        </td>
                        <?php if (ecIsAccountabilityAdmin()): ?>
                            <td data-label="Actions">
                                <div class="d-flex gap-2 flex-wrap">
                                    <button
                                        class="btn btn-sm btn-outline-secondary edit-office"
                                        type="button"
                                        data-office='<?= ecEscape(json_encode($office, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                                    >Edit</button>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="office_id" value="<?= (int) $office['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <?= $office['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$offices): ?>
                    <tr><td class="empty" colspan="6">No offices match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
    (() => {
        const form = document.getElementById('office-form');
        if (!form) return;
        const fields = {
            id: document.getElementById('office-id'),
            name: document.getElementById('office-name'),
            code: document.getElementById('office-code'),
            type: document.getElementById('office-type'),
            district: document.getElementById('office-district'),
            region: document.getElementById('office-region'),
            address: document.getElementById('office-address'),
        };
        const clear = () => {
            form.reset();
            fields.id.value = '';
            fields.name.focus();
        };
        document.querySelectorAll('.edit-office').forEach((button) => {
            button.addEventListener('click', () => {
                const office = JSON.parse(button.dataset.office);
                fields.id.value = office.id;
                fields.name.value = office.name || '';
                fields.code.value = office.office_code || '';
                fields.type.value = office.office_type;
                fields.district.value = office.district_id || '';
                fields.region.value = office.region || '';
                fields.address.value = office.address || '';
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        document.getElementById('clear-office-form').addEventListener('click', clear);
    })();
</script>
<?php ecFooter(); ?>
