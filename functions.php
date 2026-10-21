<?php

// functions.php - Common functions

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/app.php";

// Get user data
function getUserData($userId)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT u.*, d.name as district_name 
                            FROM users u 
                            LEFT JOIN districts d ON u.district_id = d.id 
                            WHERE u.id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Get user by username
function getUserByUsername($username)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Get district by name (kept for backward compatibility)
function getDistrictByName($name)
{
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM districts WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Dynamic District Extraction from Narration (Optimized with static cache)
function getDistrictFromNarration($narration)
{
    static $districts = null;

    // Cache districts in memory to prevent querying the DB on every single row during bulk upload
    if ($districts === null) {
        $conn = getDBConnection();
        $result = $conn->query("SELECT id, name, region FROM districts");
        $districts = $result->fetch_all(MYSQLI_ASSOC);
    }

    foreach ($districts as $district) {
        // Case-insensitive search
        if (stripos($narration, $district["name"]) !== false) {
            return $district;
        }
    }

    return null;
}

// Get all districts
function getAllDistricts()
{
    $conn = getDBConnection();
    return $conn->query("SELECT * FROM districts ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

// Search payments
function searchPayments($phone = null, $custName = null, $districtId = null)
{
    $conn = getDBConnection();

    $sql = "SELECT p.*, d.name as district_name, u.full_name as uploaded_by_name 
            FROM payments p 
            LEFT JOIN districts d ON p.district_id = d.id 
            LEFT JOIN users u ON p.uploaded_by = u.id 
            WHERE 1=1";

    $params = [];
    $types = "";

    if ($phone) {
        $sql .= " AND p.phone LIKE ?";
        $params[] = "%" . $phone . "%";
        $types .= "s";
    }

    if ($custName) {
        $sql .= " AND p.cust_name LIKE ?";
        $params[] = "%" . $custName . "%";
        $types .= "s";
    }

    if ($districtId) {
        $sql .= " AND p.district_id = ?";
        $params[] = $districtId;
        $types .= "i";
    }

    $sql .= " ORDER BY p.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get dashboard statistics
function getDashboardStats($userId, $role, $districtId = null)
{
    return ecDashboard($userId, $role, $districtId);
}

// Insert bulk payments
function insertBulkPayments($payments, $userId, $districtId)
{
    $conn = getDBConnection();
    $inserted = 0;
    $errors = [];

    $stmt = $conn->prepare("INSERT INTO payments (phone, cust_name, tran_amount, status, narration, district_id, uploaded_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($payments as $payment) {
        try {
            // Use ?? to provide default empty values if keys are missing
            $phone = sanitizeInput($payment["phone"] ?? "");
            $custName = sanitizeInput($payment["cust_name"] ?? "");
            $amount = floatval($payment["tran_amount"] ?? 0);
            $status = sanitizeInput($payment["status"] ?? "");
            $narration = sanitizeInput($payment["narration"] ?? "");

            $paymentDistrictId = $payment["district_id"] ?? $districtId;
            $stmt->bind_param(
                "ssdssii",
                $phone,
                $custName,
                $amount,
                $status,
                $narration,
                $paymentDistrictId,
                $userId,
            );

            if ($stmt->execute()) {
                $inserted++;
            } else {
                $errors[] = "Failed to insert payment for: " . $custName;
            }
        } catch (Exception $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }

    return ["inserted" => $inserted, "errors" => $errors];
}

// Send message
function sendMessage($senderId, $receiverId, $message)
{
    $conn = getDBConnection();
    $message = sanitizeInput($message);

    $stmt = $conn->prepare(
        "INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)",
    );
    $stmt->bind_param("iis", $senderId, $receiverId, $message);

    return $stmt->execute();
}

// Get messages
function getMessages($userId1, $userId2)
{
    return ecChatRead(getUserData($userId1), $userId2);
}

// Get all admins
function getAllAdmins()
{
    $conn = getDBConnection();
    return $conn
        ->query("SELECT id, username, full_name FROM users WHERE role = 'admin' AND is_active = 1")
        ->fetch_all(MYSQLI_ASSOC);
}

// Format currency
function formatCurrency($amount)
{
    return "UGX " . number_format($amount, 0);
}

// Format date
function formatDate($date)
{
    return date("M d, Y H:i", strtotime($date));
}

// Extract district from narration
function extractDistrictFromNarration($narration)
{
    $districts = ["Bukwo", "Mitooma", "Luwero"]; // Add more as needed

    foreach ($districts as $district) {
        if (stripos($narration, $district) !== false) {
            return $district;
        }
    }

    return null;
}
