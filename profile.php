<?php
// profile.php - User Profile and Password Management
require_once "config.php";
require_once "functions.php";
require_once __DIR__ . "/layout.php";

requireLogin();

$userId = $_SESSION["user_id"];
$userData = getUserData($userId);
$csrf_token = generateCSRFToken();
$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST["csrf_token"]) || !verifyCSRFToken($_POST["csrf_token"])) {
        $message = "Invalid security token.";
        $messageType = "danger";
    } else {
        $conn = getDBConnection();
        $action = $_POST["action"] ?? "";

        if ($action === "update_profile") {
            $full_name = sanitizeInput($_POST["full_name"]);
            $email = sanitizeInput($_POST["email"]);

            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
            $stmt->bind_param("ssi", $full_name, $email, $userId);

            if ($stmt->execute()) {
                $_SESSION["full_name"] = $full_name; // Update session
                $message = "Profile updated successfully!";
                $messageType = "success";
                $userData = getUserData($userId); // Refresh data
            } else {
                $message = "Error updating profile.";
                $messageType = "danger";
            }
        } elseif ($action === "change_password") {
            $old_password = $_POST["old_password"];
            $new_password = $_POST["new_password"];
            $confirm_password = $_POST["confirm_password"];

            if ($new_password !== $confirm_password) {
                $message = "New passwords do not match.";
                $messageType = "danger";
            } elseif (strlen($new_password) < 6) {
                $message = "Password must be at least 6 characters.";
                $messageType = "danger";
            } else {
                // Verify old password
                if (verifyPassword($old_password, $userData["password"])) {
                    $hashed = hashPassword($new_password);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashed, $userId);

                    if ($stmt->execute()) {
                        $message = "Password changed successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Error changing password.";
                        $messageType = "danger";
                    }
                } else {
                    $message = "Current password is incorrect.";
                    $messageType = "danger";
                }
            }
        }
    }
}
?> <?php ecHeader("Profile", "profile"); ?>

<div class="container-fluid px-0">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <h2 class="mb-4">My Profile</h2>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Update Profile Info -->
            <div class="content-card">
                <h4 class="mb-3">
                    <i class="bi bi-person-circle me-2"></i>
                    Profile Information
                </h4>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                    <input type="hidden" name="action" value="update_profile" />

                    <div class="mb-3">
                        <label class="form-label" for="profile-field-1">Username</label>
                        <input
                            id="profile-field-1"
                            type="text"
                            class="form-control"
                            value="<?php echo htmlspecialchars($userData["username"]); ?>"
                            disabled
                        />
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="profile-field-2">Full Name</label>
                        <input
                            id="profile-field-2"
                            type="text"
                            class="form-control"
                            name="full_name"
                            value="<?php echo htmlspecialchars($userData["full_name"]); ?>"
                            required
                        />
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="profile-field-3">Email</label>
                        <input
                            id="profile-field-3"
                            type="email"
                            class="form-control"
                            name="email"
                            value="<?php echo htmlspecialchars($userData["email"]); ?>"
                            required
                        />
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="profile-field-4">Role</label>
                        <input
                            id="profile-field-4"
                            type="text"
                            class="form-control"
                            value="<?php echo ucfirst(str_replace("_", " ", $userData["role"])); ?>"
                            disabled
                        />
                    </div>
                    <?php if ($userData["district_name"]): ?>
                    <div class="mb-3">
                        <label class="form-label" for="profile-field-5">Assigned District</label>
                        <input
                            id="profile-field-5"
                            type="text"
                            class="form-control"
                            value="<?php echo htmlspecialchars($userData["district_name"]); ?>"
                            disabled
                        />
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>
                        Save Changes
                    </button>
                </form>
            </div>

            <!-- Change Password -->
            <div class="content-card">
                <h4 class="mb-3">
                    <i class="bi bi-key me-2"></i>
                    Change Password
                </h4>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                    <input type="hidden" name="action" value="change_password" />

                    <div class="mb-3">
                        <label class="form-label" for="profile-field-6">Current Password</label>
                        <input
                            id="profile-field-6"
                            type="password"
                            class="form-control"
                            name="old_password"
                            required
                        />
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="profile-field-7">New Password</label>
                        <input
                            id="profile-field-7"
                            type="password"
                            class="form-control"
                            name="new_password"
                            required
                            minlength="6"
                        />
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="profile-field-8">Confirm New Password</label>
                        <input
                            id="profile-field-8"
                            type="password"
                            class="form-control"
                            name="confirm_password"
                            required
                            minlength="6"
                        />
                    </div>

                    <button type="submit" class="btn btn-warning text-white">
                        <i class="bi bi-shield-lock me-1"></i>
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="assets/bootstrap.bundle.min.js"></script>
<?php ecFooter(); ?>
