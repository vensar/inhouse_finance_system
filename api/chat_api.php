<?php

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
function reply($payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}
if (!isLoggedIn()) {
    reply(["success" => false, "error" => "Please sign in again."], 401);
}
$user = getUserData($_SESSION["user_id"]);
if (!$user || !$user["is_active"]) {
    reply(["success" => false, "error" => "Account is inactive."], 403);
}
$method = $_SERVER["REQUEST_METHOD"];
try {
    if ($method === "POST") {
        if (!is_string($_POST["csrf_token"] ?? null) || !verifyCSRFToken($_POST["csrf_token"])) {
            reply(
                [
                    "success" => false,
                    "error" => "Your session token expired. Refresh this page before sending.",
                ],
                403,
            );
        }
        if (($_POST["action"] ?? "") !== "send_message" || !is_string($_POST["message"] ?? null)) {
            reply(["success" => false, "error" => "Invalid request."], 400);
        }
        session_write_close();
        $id = ecChatSend($user, (int) ($_POST["receiver_id"] ?? 0), $_POST["message"]);
        reply(["success" => true, "id" => $id]);
    }
    if ($method === "GET") {
        session_write_close();
        if (($_GET["action"] ?? "") === "contacts") {
            reply(["success" => true, "contacts" => ecChatContacts($user)]);
        }
        $messages = ecChatRead(
            $user,
            (int) ($_GET["peer"] ?? 0),
            max(0, (int) ($_GET["after"] ?? 0)),
            max(0, (int) ($_GET["before"] ?? 0)),
        );
        reply([
            "success" => true,
            "messages" => $messages,
            "has_more" => count($messages) === 100,
        ]);
    }
    header("Allow: GET, POST");
    reply(["success" => false, "error" => "Method not supported."], 405);
} catch (DomainException $e) {
    reply(["success" => false, "error" => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log("Chat API: " . $e->getMessage());
    reply(
        [
            "success" => false,
            "error" => "Messages are temporarily unavailable. Please try again.",
        ],
        500,
    );
}
