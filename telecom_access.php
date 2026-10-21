<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
require_once __DIR__ . "/telecom_service.php";
requireAdmin();
$error = "";
$notice = $_SESSION["telecom_notice"] ?? "";
unset($_SESSION["telecom_notice"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!verifyCSRFToken((string) ($_POST["csrf_token"] ?? ""))) {
            throw new DomainException("Session expired. Reload the page and try again.");
        }
        $enabled = ($_POST["action"] ?? "") === "grant";
        if (!$enabled && ($_POST["action"] ?? "") !== "revoke") {
            throw new DomainException("Choose a valid permission action.");
        }
        ecSetTelecomAccess((int) ($_POST["user_id"] ?? 0), $enabled, (int) $_SESSION["user_id"]);
        $_SESSION["telecom_notice"] = $enabled
            ? "Telecom verification access granted."
            : "Telecom verification access revoked.";
        header("Location: telecom_access", true, 303);
        exit();
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log("Telecom access: " . $e->getMessage());
        $error = "The permission could not be updated.";
    }
}
$users = ecQuery(
    "SELECT u.id,u.full_name,u.username,d.name district_name,u.is_active,COALESCE(p.is_enabled,0) has_access,p.granted_at,p.revoked_at FROM users u LEFT JOIN districts d ON d.id=u.district_id LEFT JOIN telecom_api_permissions p ON p.user_id=u.id WHERE u.role='district_user' ORDER BY u.is_active DESC,d.name,u.full_name",
)
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);
$csrf = generateCSRFToken();
session_write_close();
ecHeader("Telecom API access", "telecom_access");
?>
<div class="page-heading"><div><div class="eyebrow">Integration control</div><h1>Telecom API access</h1><p class="muted">Grant or recall permission for district users to submit verification batches.</p></div><a class="btn btn-outline-secondary" href="telecom">Verification batches</a></div>
<?php
if ($notice): ?><div class="alert alert-success" role="status"><?= ecEscape($notice) ?></div><?php endif;
if ($error): ?><div class="alert alert-danger" role="alert"><?= ecEscape($error) ?></div><?php endif;
?>
<section class="panel"><div class="table-responsive"><table class="table mobile-records"><thead><tr><th>District user</th><th>District</th><th>Account</th><th>API access</th><th>Action</th></tr></thead><tbody><?php foreach (
    $users as $user
): ?><tr><td><strong><?= ecEscape($user["full_name"]) ?></strong><div class="muted"><?= ecEscape(
    $user["username"],
) ?></div></td><td data-label="District"><?= ecEscape(
    $user["district_name"],
) ?></td><td data-label="Account"><?= $user["is_active"]
    ? "Active"
    : "Inactive" ?></td><td data-label="API access"><span class="lot-status <?= $user["has_access"]
    ? "active"
    : "inactive" ?>"><?= $user["has_access"] ? "GRANTED" : "NOT GRANTED" ?></span></td><td><?php if (
        $user["is_active"]
    ): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= ecEscape(
        $csrf,
    ) ?>"><input type="hidden" name="user_id" value="<?= (int) $user[
        "id"
    ] ?>"><button class="btn btn-sm <?= $user["has_access"]
        ? "btn-outline-danger"
        : "btn-primary" ?>" name="action" value="<?= $user["has_access"] ? "revoke" : "grant" ?>"><?= $user[
        "has_access"
    ]
        ? "Recall access"
        : "Grant access" ?></button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php ecFooter(); ?>
