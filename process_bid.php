<?php
// MANDATORY: Start the session engine BEFORE anything else. 
// This allows the script to check if the browser has a valid login memory.
session_start();

require_once 'db_connect.php';

header('Content-Type: application/json');

// 1. THE SECURITY GATE: Is this person actually logged in?
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Transaction Denied: You must be securely logged in to execute a contract.']);
    exit();
}

// --- NEW: THE COOLDOWN ENGINE (Anti-Spam) ---
if (isset($_SESSION['last_bid_time']) && (time() - $_SESSION['last_bid_time']) < 2) {
    echo json_encode(['success' => false, 'message' => 'Rate Limit Exceeded: Network traffic detected. Please wait 2 seconds between bids.']);
    exit();
}
// Lock in the timestamp of this attempt
$_SESSION['last_bid_time'] = time();

// 2. Assign the verified user ID from the secure session memory
$user_id = (int)$_SESSION['user_id']; 

// Catch the data sent by the JavaScript Fetch API
$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['auction_id']) || !isset($data['bid_amount'])) {
    echo json_encode(['success' => false, 'message' => 'Critical Error: Invalid data payload received.']);
    exit();
}

$auction_id = (int)$data['auction_id'];
$bid_amount = (float)$data['bid_amount'];

try {
    // Lock the database to prevent race conditions
    $conn->beginTransaction();

    // Verify the Auction exists and is still active
    $stmt = $conn->prepare("SELECT current_high_bid, start_time, end_time FROM auctions WHERE auction_id = :id FOR UPDATE");
    $stmt->bindParam(':id', $auction_id);
    $stmt->execute();
    $auction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auction) {
        throw new Exception("Security Alert: Auction not found.");
    }
    
    // --- TIMELINE VERIFICATION ---
    if (strtotime($auction['start_time']) > time()) {
        throw new Exception("Transaction Denied: This arena has not opened yet. Bidding is locked.");
    }
    if (strtotime($auction['end_time']) < time()) {
        throw new Exception("Transaction Denied: This auction's window has already closed.");
    }
    
    // --- NEW: THE PENNY PINCHER RULE (Dynamic Minimum Increment) ---
    $min_increment = 200; // Default fallback
    if (file_exists('settings.json')) {
        $settings_data = json_decode(file_get_contents('settings.json'), true);
        if (isset($settings_data['min_bid_increment'])) {
            $min_increment = (float)$settings_data['min_bid_increment'];
        }
    }

    if ($bid_amount < ($auction['current_high_bid'] + $min_increment)) {
        throw new Exception("Transaction Denied: Bids must exceed the current price by a minimum increment of $" . number_format($min_increment, 2) . ".");
    }

    // --- NEW: THE ANTI-SHILL RULE (No Self-Bidding) & ESCROW REFUND SETUP ---
    $stmt_top = $conn->prepare("SELECT user_id, bid_amount FROM bids WHERE auction_id = :aid ORDER BY bid_amount DESC LIMIT 1");
    $stmt_top->execute([':aid' => $auction_id]);
    $top_bidder = $stmt_top->fetch(PDO::FETCH_ASSOC);

    if ($top_bidder && (int)$top_bidder['user_id'] === $user_id) {
        throw new Exception("Transaction Denied: You already hold the highest bid in this arena.");
    }

    // Verify the User actually exists and has the money
    $stmt = $conn->prepare("SELECT available_funds FROM users WHERE user_id = :uid");
    $stmt->bindParam(':uid', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // SECURITY CHECK: Did a ghost user somehow get past the session check?
    if (!$user) {
        throw new Exception("Critical Security Alert: Ghost user detected. The account placing this bid does not exist in the database.");
    }

    if ($user['available_funds'] < $bid_amount) {
        throw new Exception("Transaction Denied: Insufficient funds. Your balance is $" . number_format($user['available_funds'], 2));
    }

    // --- EXECUTE ESCROW LOGIC ---

    // 1. Release previous bidder's escrow hold (if there is a previous bidder)
    if ($top_bidder) {
        $prev_user = (int)$top_bidder['user_id'];
        $prev_amount = (float)$top_bidder['bid_amount'];
        
        // Refund their money
        $conn->query("UPDATE users SET available_funds = available_funds + $prev_amount WHERE user_id = $prev_user");
        
        // Log it immutably
        $log_stmt = $conn->prepare("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES (:uid, :aid, 'escrow_release', :amt)");
        $log_stmt->execute([':uid' => $prev_user, ':aid' => $auction_id, ':amt' => $prev_amount]);
    }

    // 2. Take the new bidder's money (Escrow Hold)
    $conn->query("UPDATE users SET available_funds = available_funds - $bid_amount WHERE user_id = $user_id");
    
    $log_stmt = $conn->prepare("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES (:uid, :aid, 'escrow_hold', :amt)");
    $log_stmt->execute([':uid' => $user_id, ':aid' => $auction_id, ':amt' => $bid_amount]);

    // Everything is valid. Record the bid in the ledger.
    $stmt = $conn->prepare("INSERT INTO bids (auction_id, user_id, bid_amount) VALUES (:aid, :uid, :amount)");
    $stmt->execute([':aid' => $auction_id, ':uid' => $user_id, ':amount' => $bid_amount]);

    // --- NEW: THE SOFT ENDING (Anti-Snipe) LOGIC ---
    $current_time = time();
    $end_timestamp = strtotime($auction['end_time']);
    $time_remaining = $end_timestamp - $current_time;
    
    // Default update query (no time extension)
    $update_sql = "UPDATE auctions SET current_high_bid = :amount WHERE auction_id = :aid";
    $update_params = [':amount' => $bid_amount, ':aid' => $auction_id];

    // If less than 2 minutes remain, extend the clock by 2 minutes from NOW
    if ($time_remaining > 0 && $time_remaining < 120) {
        $new_end_time = date('Y-m-d H:i:s', $current_time + 120);
        $update_sql = "UPDATE auctions SET current_high_bid = :amount, end_time = :new_end WHERE auction_id = :aid";
        $update_params[':new_end'] = $new_end_time;
    }

    // Execute the final update
    $stmt = $conn->prepare($update_sql);
    $stmt->execute($update_params);

    // Commit the transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Bid locked securely into the ledger.']);

} catch (Exception $e) {
    // Rollback if anything fails
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>