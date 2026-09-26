<?php
require_once 'db_connect.php';

// Tell the browser to expect pure data (JSON), not HTML
header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Missing Artifact ID']);
    exit();
}

$auction_id = (int)$_GET['id'];

try {
    // 1. Fetch the absolute highest bid AND the end time
    $stmt = $conn->prepare("
        SELECT a.end_time, COALESCE(MAX(b.bid_amount), a.current_high_bid) as true_high_bid 
        FROM auctions a 
        LEFT JOIN bids b ON a.auction_id = b.auction_id 
        WHERE a.auction_id = :id
        GROUP BY a.auction_id
    ");
    $stmt->bindParam(':id', $auction_id);
    $stmt->execute();
    $auction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auction) {
        echo json_encode(['error' => 'Artifact not found in vault']);
        exit();
    }

    // 2. Fetch the top 10 most recent bids for the ledger
    $stmt_bids = $conn->prepare("
        SELECT b.user_id, b.bid_amount, u.alias 
        FROM bids b 
        JOIN users u ON b.user_id = u.user_id 
        WHERE b.auction_id = :id 
        ORDER BY b.bid_amount DESC 
        LIMIT 10
    ");
    $stmt_bids->bindParam(':id', $auction_id);
    $stmt_bids->execute();
    $bid_history = $stmt_bids->fetchAll(PDO::FETCH_ASSOC);

    // 3. Security Check: Sanitize the names before sending them to the browser
    $clean_history = [];
    foreach ($bid_history as $bid) {
        $clean_history[] = [
            'user_id' => $bid['user_id'],
            'alias' => htmlspecialchars($bid['alias']),
            'bid_amount' => $bid['bid_amount']
        ];
    }

    // Calculate server-side time remaining
    $now = time();
    $end_time = strtotime($auction['end_time']);
    $seconds_remaining = $end_time - $now;
    if ($seconds_remaining < 0) $seconds_remaining = 0;

    // 4. Send the package back to the browser
    echo json_encode([
        'true_high_bid' => $auction['true_high_bid'],
        'history' => $clean_history,
        'seconds_remaining' => $seconds_remaining
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Vault connection failed']);
}
?>