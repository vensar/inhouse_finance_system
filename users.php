<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/permission_service.php';

requireLogin();

$csrfToken = generateCSRFToken();
$districts = getAllDistricts();
$permissionGroups = ecPermissionGroups();
$message = $_SESSION['user_management_notice'] ?? '';
$messageType = $_SESSION['user_management_notice_type'] ?? '';
unset($_SESSION['user_management_notice'], $_SESSION['user_management_notice_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new DomainException('Session expired. Reload and try again.');
        }
        $database = getDBConnection();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_user') {
            $username = sanitizeInput((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');
            $fullName = sanitizeInput((string) ($_POST['full_name'] ?? ''));
            $email = sanitizeInput((string) ($_POST['email'] ?? ''));
            $allowedRoles = ['district_user', 'accountability_officer', 'accountability_admin', 'admin'];
            if (!in_array($role, $allowedRoles, true)) {
                throw new DomainException('Choose a valid role.');
            }
            $districtId = in_array($role, ['district_user', 'accountability_officer'], true)
                ? max(0, (int) ($_POST['district_id'] ?? 0))
                : null;
            if (in_array($role, ['district_user', 'accountability_officer'], true) && !$districtId) {
                throw new DomainException('Choose the district this user is responsible for.');
            }
            if ($username === '' || $fullName === '' || strlen($password) < 6) {
                throw new DomainException('Full name, username, and a password of at least six characters are required.');
            }
            $permissions = $_POST['permissions'] ?? ecDefaultPermissionKeys($role);
            $database->begin_transaction();
            try {
                $hashedPassword = hashPassword($password);
                $statement = $database->prepare(
                    'INSERT INTO users(username,password,role,district_id,full_name,email) VALUES(?,?,?,?,?,?)',
                );
                $statement->bind_param('sssiss', $username, $hashedPassword, $role, $districtId, $fullName, $email);
                $statement->execute();
                $userId = $database->insert_id;
                ecSaveUserPermissions($userId, (array) $permissions, (int) $_SESSION['user_id']);
                $database->commit();
            } catch (Throwable $exception) {
                $database->rollback();
                throw $exception;
            }
            $message = 'User created with the selected menu permissions.';
        } elseif ($action === 'save_permissions') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            ecSaveUserPermissions($userId, (array) ($_POST['permissions'] ?? []), (int) $_SESSION['user_id']);
            $message = 'User permissions updated.';
        } elseif ($action === 'toggle_status') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId === (int) $_SESSION['user_id']) {
                throw new DomainException('You cannot deactivate your own account.');
            }
            $statement = $database->prepare('UPDATE users SET is_active=IF(is_active=1,0,1) WHERE id=?');
            $statement->bind_param('i', $userId);
            $statement->execute();
            $message = 'User status updated.';
        } else {
            throw new DomainException('Choose a valid user-management action.');
        }
        $_SESSION['user_management_notice'] = $message;
        $_SESSION['user_management_notice_type'] = 'success';
        header('Location: users', true, 303);
        exit;
    } catch (mysqli_sql_exception $exception) {
        $message = (int) $exception->getCode() === 1062
            ? 'That username or email address is already registered.'
            : 'The user-management request could not be completed.';
        $messageType = 'danger';
    } catch (DomainException $exception) {
        $message = $exception->getMessage();
        $messageType = 'danger';
    } catch (Throwable $exception) {
        error_log('User management: ' . $exception->getMessage());
        $message = 'The user-management request could not be completed.';
        $messageType = 'danger';
    }
}

$users = getDBConnection()->query(
    'SELECT u.*,d.name district_name,COUNT(up.permission_id) permission_count
     FROM users u
     LEFT JOIN districts d ON d.id=u.district_id
     LEFT JOIN user_permissions up ON up.user_id=u.id
     GROUP BY u.id
     ORDER BY u.is_active DESC,u.full_name,u.username',
)->fetch_all(MYSQLI_ASSOC);
$permissionsByUser = [];
foreach ($users as $user) {
    $permissionsByUser[(int) $user['id']] = ecPermissionKeysForUser((int) $user['id']);
}

ecHeader('Users & permissions', 'users');
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Administration</div>
        <h1>Users and menu permissions</h1>
        <p class="muted">Roles define responsibility and data scope. Permissions determine the menus and pages each user can access.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#add-user-modal">
        <i class="bi bi-person-plus"></i> Add user
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= ecEscape($messageType ?: 'success') ?>"><?= ecEscape($message) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <div><h2>System users</h2><p class="muted"><?= number_format(count($users)) ?> registered accounts</p></div>
    </div>
    <div class="table-responsive">
        <table class="table mobile-records user-permission-table">
            <thead><tr><th>User</th><th>Role / district</th><th>Menu access</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php $userPermissionKeys = $permissionsByUser[(int) $user['id']]; ?>
                    <tr>
                        <td>
                            <strong><?= ecEscape($user['full_name']) ?></strong>
                            <div><?= ecEscape($user['username']) ?></div>
                            <small class="muted"><?= ecEscape($user['email'] ?: 'No email address') ?></small>
                        </td>
                        <td data-label="Role / district">
                            <span class="role-pill"><?= ecEscape(ucwords(str_replace('_', ' ', $user['role']))) ?></span>
                            <div class="muted mt-1"><?= ecEscape($user['district_name'] ?: 'System-wide') ?></div>
                        </td>
                        <td data-label="Menu access">
                            <strong><?= number_format($user['permission_count']) ?></strong> of <?= number_format(array_sum(array_map('count', $permissionGroups))) ?> features
                            <details class="permission-editor mt-2">
                                <summary>Manage permissions</summary>
                                <form method="post" class="permission-form">
                                    <input type="hidden" name="csrf_token" value="<?= ecEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="save_permissions">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <?php foreach ($permissionGroups as $group => $permissions): ?>
                                        <fieldset>
                                            <legend><?= ecEscape($group) ?></legend>
                                            <?php foreach ($permissions as $permission): ?>
                                                <label class="permission-option">
                                                    <input
                                                        type="checkbox"
                                                        name="permissions[]"
                                                        value="<?= ecEscape($permission['permission_key']) ?>"
                                                        <?= in_array($permission['permission_key'], $userPermissionKeys, true) ? 'checked' : '' ?>
                                                        <?= (int) $user['id'] === (int) $_SESSION['user_id'] && $permission['permission_key'] === 'users.manage' ? 'disabled' : '' ?>
                                                    >
                                                    <span><strong><?= ecEscape($permission['label']) ?></strong><small><?= ecEscape($permission['description']) ?></small></span>
                                                </label>
                                                <?php if ((int) $user['id'] === (int) $_SESSION['user_id'] && $permission['permission_key'] === 'users.manage'): ?>
                                                    <input type="hidden" name="permissions[]" value="users.manage">
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    <?php endforeach; ?>
                                    <button class="btn btn-sm btn-primary" type="submit">Save menu permissions</button>
                                </form>
                            </details>
                        </td>
                        <td data-label="Status"><span class="lot-status <?= $user['is_active'] ? 'active' : 'inactive' ?>"><?= $user['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span></td>
                        <td data-label="Actions">
                            <?php if ((int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= ecEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $user['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            <?php else: ?><span class="muted">Current user</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="modal fade user-access-modal" id="add-user-modal" tabindex="-1" aria-labelledby="add-user-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-xl modal-fullscreen-md-down">
        <div class="modal-content">
            <form method="post" id="add-user-form">
                <div class="modal-header"><h2 class="modal-title fs-5" id="add-user-title">Add user and grant access</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= ecEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_user">
                    <div class="user-details-grid">
                        <div><label class="form-label">Full name</label><input class="form-control" name="full_name" required></div>
                        <div><label class="form-label">Username</label><input class="form-control" name="username" required></div>
                        <div><label class="form-label">Email</label><input class="form-control" type="email" name="email"></div>
                        <div><label class="form-label">Password</label><input class="form-control" type="password" name="password" minlength="6" required></div>
                        <div><label class="form-label">Role</label><select class="form-select" name="role" id="role-select" required><option value="district_user">District User</option><option value="accountability_officer">Accountability Officer</option><option value="accountability_admin">Accountability Admin</option><option value="admin">Administrator</option></select></div>
                        <div id="district-group"><label class="form-label">Assigned district</label><select class="form-select" name="district_id"><option value="">Choose district</option><?php foreach ($districts as $district): ?><option value="<?= (int) $district['id'] ?>"><?= ecEscape($district['name']) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="permission-heading"><h3>Menu permissions</h3><button class="btn btn-sm btn-outline-secondary" type="button" id="apply-role-defaults">Apply role defaults</button></div>
                    <div class="permission-grid">
                        <?php foreach ($permissionGroups as $group => $permissions): ?>
                            <fieldset><legend><?= ecEscape($group) ?></legend><?php foreach ($permissions as $permission): ?><label class="permission-option"><input class="new-user-permission" type="checkbox" name="permissions[]" value="<?= ecEscape($permission['permission_key']) ?>"><span><strong><?= ecEscape($permission['label']) ?></strong><small><?= ecEscape($permission['description']) ?></small></span></label><?php endforeach; ?></fieldset>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create user</button></div>
            </form>
        </div>
    </div>
</div>

<script src="assets/bootstrap.bundle.min.js"></script>
<script>
    (() => {
        const defaults = <?= json_encode([
            'district_user' => ecDefaultPermissionKeys('district_user'),
            'accountability_officer' => ecDefaultPermissionKeys('accountability_officer'),
            'accountability_admin' => ecDefaultPermissionKeys('accountability_admin'),
            'admin' => ecDefaultPermissionKeys('admin'),
        ]) ?>;
        const role = document.getElementById('role-select');
        const districtGroup = document.getElementById('district-group');
        const district = districtGroup.querySelector('select');
        const applyDefaults = () => {
            const allowed = new Set(defaults[role.value] || []);
            document.querySelectorAll('.new-user-permission').forEach((box) => {
                box.checked = allowed.has(box.value);
            });
        };
        const updateDistrict = () => {
            const required = role.value === 'district_user' || role.value === 'accountability_officer';
            districtGroup.hidden = !required;
            district.disabled = !required;
            district.required = required;
        };
        role.addEventListener('change', () => {
            updateDistrict();
            applyDefaults();
        });
        document.getElementById('apply-role-defaults').addEventListener('click', applyDefaults);
        updateDistrict();
        applyDefaults();
    })();
</script>
<?php ecFooter(); ?>
