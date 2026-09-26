<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once 'db_connect.php';

$theme = $_POST['theme'] ?? $_GET['theme'] ?? '';
$theme = strtolower(trim($theme));

if (!in_array($theme, ['dark', 'light'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid theme specification']);
    exit();
}

// 1. Update Session
$_SESSION['theme'] = $theme;

// 2. Set Cookie (1 Year)
setcookie('retro_theme', $theme, [
    'expires' => time() + (86400 * 365),
    'path' => '/',
    'samesite' => 'Lax'
]);

// 3. Attempt DB Update if logged in (safely handles if column not yet added)
$db_updated = false;
if (isset($_SESSION['user_id']) && isset($conn)) {
    try {
        $stmt = $conn->prepare("UPDATE users SET theme = :theme WHERE user_id = :uid");
        $stmt->execute([
            ':theme' => $theme,
            ':uid' => (int)$_SESSION['user_id']
        ]);
        $db_updated = true;
    } catch (PDOException $e) {
        // Table column may not have been created yet, session and cookie handle persistence
        $db_updated = false;
    }
}

echo json_encode([
    'success' => true,
    'theme' => $theme,
    'db_updated' => $db_updated
]);
