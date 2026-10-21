<?php
// login.php
require_once "config.php";
require_once "functions.php";

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: " . ecFirstPermittedRoute());
    exit();
}

$error = "";
$csrf_token = generateCSRFToken();

// Check for lockout
$ip = getUserIP();
$conn = getDBConnection();
$stmt = $conn->prepare(
    "SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ? AND last_attempt > DATE_SUB(NOW(), INTERVAL " .
        LOCKOUT_TIME .
        " SECOND)",
);
$stmt->bind_param("s", $ip);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row["attempts"] >= MAX_LOGIN_ATTEMPTS) {
        $remaining = ceil((strtotime($row["last_attempt"]) + LOCKOUT_TIME - time()) / 60);
        $error = "Too many failed attempts. Please try again in $remaining minutes.";
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verify CSRF token
    if (!isset($_POST["csrf_token"]) || !verifyCSRFToken($_POST["csrf_token"])) {
        $error = "Invalid security token. Please try again.";
    } else {
        $username = sanitizeInput($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        if (empty($username) || empty($password)) {
            $error = "Please enter both username and password.";
        } else {
            // Check rate limit
            checkRateLimit("login");

            $user = getUserByUsername($username);

            if ($user) {
                if (verifyPassword($password, $user["password"])) {
                    // Clear login attempts
                    $stmt = $conn->prepare(
                        "DELETE FROM login_attempts WHERE ip_address = ? OR username = ?",
                    );
                    $stmt->bind_param("ss", $ip, $username);
                    $stmt->execute();

                    // Regenerate session ID
                    session_regenerate_id(true);

                    // Set session variables
                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["username"] = $user["username"];
                    $_SESSION["role"] = $user["role"];
                    $_SESSION["district_id"] = $user["district_id"];
                    $_SESSION["full_name"] = $user["full_name"];
                    $_SESSION["logged_in"] = true;
                    $_SESSION["login_time"] = time();

                    // Update last login
                    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $user["id"]);
                    $stmt->execute();

                    // Log security event
                    logSecurityEvent("Successful login", $user["id"]);

                    header("Location: " . ecFirstPermittedRoute((int) $user["id"]));
                    exit();
                } else {
                    // Failed login attempt
                    $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, username, last_attempt) 
                        VALUES (?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
                    $stmt->bind_param("ss", $ip, $username);
                    $stmt->execute();

                    $error = "Invalid username or password.";
                    logSecurityEvent("Failed login attempt: " . $username, $user["id"]);
                }
            } else {
                $error = "Invalid username or password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="description" content="Electoral Commission In House Finance Management sign in" />
        <title>Login | In House Finance Management</title>
        <link rel="icon" type="image/jpeg" href="logo.jpg" />
        <link href="assets/bootstrap.min.css" rel="stylesheet" />
        <link href="login.css?v=20261007a" rel="stylesheet" />
    </head>
    <body class="ec-login">
        <main class="login-card" aria-labelledby="login-title">
            <div class="login-header">
                <img
                    src="logo.jpg"
                    class="login-logo"
                    alt="Electoral Commission logo"
                    width="86"
                    height="86"
                />
                <h3 id="login-title">In House Finance Management</h3>
                <p class="mb-0 small opacity-75">Electoral Commission</p>
            </div>
            <div class="login-body">
                <?php if ($error): ?>
                <div class="alert alert-danger py-2 small" role="alert"><?php echo htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8",
                ); ?></div>
                <?php endif; ?>
                <form method="POST" action="login">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(
                        $csrf_token,
                        ENT_QUOTES,
                        "UTF-8",
                    ); ?>" />
                    <div class="mb-3">
                        <label for="username" class="form-label small fw-bold">Username</label>
                        <input
                            type="text"
                            name="username"
                            id="username"
                            class="form-control"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            autofocus
                            value="<?php echo htmlspecialchars(
                                $_POST["username"] ?? "",
                                ENT_QUOTES,
                                "UTF-8",
                            ); ?>"
                        />
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label small fw-bold">Password</label>
                        <div class="password-field">
                            <input
                                type="password"
                                name="password"
                                id="password"
                                class="form-control"
                                autocomplete="current-password"
                                required
                            />
                            <button
                                type="button"
                                class="password-toggle"
                                aria-controls="password"
                                aria-label="Show password"
                                aria-pressed="false"
                                hidden
                            >
                                Show
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-ec">Sign In</button>
                </form>
                <div class="secure-note">Secure Electoral Commission finance access</div>
            </div>
        </main>
        <script>
            const toggle = document.querySelector(".password-toggle");
            const password = document.getElementById("password");
            toggle.hidden = false;
            toggle.addEventListener("click", () => {
                const visible = password.type === "password";
                password.type = visible ? "text" : "password";
                toggle.textContent = visible ? "Hide" : "Show";
                toggle.setAttribute("aria-label", visible ? "Hide password" : "Show password");
                toggle.setAttribute("aria-pressed", String(visible));
            });
        </script>
    </body>
</html>

