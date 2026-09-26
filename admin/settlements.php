<?php
require_once 'header.php';

// Export CSV Logic
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    ob_end_clean(); // Clear header output
    
    $filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $where_clauses = ["tl.type NOT IN ('escrow_hold', 'escrow_release')"];
    
    if ($filter_type === 'deposits') {
        $where_clauses[] = "tl.type = 'deposit'";
    } elseif ($filter_type === 'settlements') {
        $where_clauses[] = "tl.type IN ('payment', 'fee', 'buyer_fee', 'seller_fee', '')";
    }
    
    $where_sql = "WHERE " . implode(' AND ', $where_clauses);

    $stmt = $conn->query("
        SELECT tl.created_at, a.title as auction_title, a.seller_id, tl.user_id, u.alias as user_name, tl.type, tl.amount 
        FROM transaction_ledger tl
        LEFT JOIN auctions a ON tl.auction_id = a.auction_id
        LEFT JOIN users u ON tl.user_id = u.user_id
        $where_sql
        ORDER BY tl.created_at DESC
    ");
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="settlement_ledger.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Auction', 'Recipient', 'Type', 'Amount']);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $type_label = $row['type'];
        if ($type_label === 'fee' || $type_label === '' || $type_label === 'buyer_fee' || $type_label === 'seller_fee') {
            if (!empty($row['seller_id']) && $row['user_id'] == $row['seller_id']) {
                $type_label = 'SELLER COMMISSION';
            } else {
                $type_label = 'BUYER PREMIUM';
            }
        } else {
            $type_label = str_replace('_', ' ', strtoupper($type_label));
        }
        
        fputcsv($output, [
            $row['created_at'],
            $row['auction_title'] ?? 'N/A',
            $row['user_name'] ?? 'Syndicate',
            $type_label,
            number_format($row['amount'], 2, '.', '')
        ]);
    }
    
    fclose($output);
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_auction'])) {
    $auction_id = (int)$_POST['auction_id'];
    
    try {
        $conn->beginTransaction();
        
        // 1. Verify auction exists, is closed, and hasn't been settled yet
        $stmt = $conn->prepare("
            SELECT a.*, u.user_id as seller_id 
            FROM auctions a 
            JOIN users u ON a.seller_id = u.user_id
            WHERE a.auction_id = :aid AND a.end_time < NOW()
            FOR UPDATE
        ");
        $stmt->execute([':aid' => $auction_id]);
        $auction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$auction) {
            throw new Exception("Auction not found or still active.");
        }
        
        // Check if already settled
        $check = $conn->prepare("SELECT 1 FROM transaction_ledger WHERE auction_id = :aid AND type = 'payment'");
        $check->execute([':aid' => $auction_id]);
        if ($check->fetch()) {
            throw new Exception("This auction has already been settled.");
        }
        
        // 2. Find the winning bid
        $bstmt = $conn->prepare("SELECT user_id, bid_amount FROM bids WHERE auction_id = :aid ORDER BY bid_amount DESC LIMIT 1");
        $bstmt->execute([':aid' => $auction_id]);
        $winner = $bstmt->fetch(PDO::FETCH_ASSOC);
        if ($winner) {
            $hammer_price = (float)$winner['bid_amount'];
            $winner_id = (int)$winner['user_id'];
            $seller_id = (int)$auction['seller_id'];
            
            // Fetch platform fee from settings if available (default 10%)
            $fee_percent = 10.0;
            $settings_path = __DIR__ . '/../settings.json';
            if (file_exists($settings_path)) {
                $settings_data = json_decode(file_get_contents($settings_path), true);
                if (isset($settings_data['platform_fee'])) {
                    $fee_percent = (float)$settings_data['platform_fee'];
                }
            }
            
            // 1. Buyer's Premium (e.g. 10% on top of hammer price)
            $buyer_premium = $hammer_price * ($fee_percent / 100);
            $total_buyer_charged = $hammer_price + $buyer_premium;
            
            // 2. Seller's Commission (e.g. 10% deducted from hammer price)
            $seller_commission = $hammer_price * ($fee_percent / 100);
            $seller_payout = $hammer_price - $seller_commission;
            
            // Deduct the Buyer's Premium from the winner's account
            $conn->query("UPDATE users SET available_funds = available_funds - $buyer_premium WHERE user_id = $winner_id");
            
            // Transfer net payout funds to seller
            $conn->query("UPDATE users SET available_funds = available_funds + $seller_payout WHERE user_id = $seller_id");
            
            // Write to ledger for Winner: Hammer Price (Acquisition)
            $log_win = $conn->prepare("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES (:uid, :aid, 'payment', :amt)");
            $log_win->execute([':uid' => $winner_id, ':aid' => $auction_id, ':amt' => $hammer_price]);
            
            // Write to ledger for Winner: Buyer's Premium
            $log_win_fee = $conn->prepare("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES (:uid, :aid, 'fee', :amt)");
            $log_win_fee->execute([':uid' => $winner_id, ':aid' => $auction_id, ':amt' => $buyer_premium]);
            
            // Write to ledger for Seller: Consignment Net Proceeds
            $log_sell = $conn->prepare("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES (:uid, :aid, 'payment', :amt)");
            $log_sell->execute([':uid' => $seller_id, ':aid' => $auction_id, ':amt' => $seller_payout]);
            
            // Write to ledger for Seller: Platform Consignment Fee
            $log_fee = $conn->prepare("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES (:uid, :aid, 'fee', :amt)");
            $log_fee->execute([':uid' => $seller_id, ':aid' => $auction_id, ':amt' => $seller_commission]);
            
            $syndicate_profit = $buyer_premium + $seller_commission;
            $message = "Settlement Executed: Winner charged $" . number_format($total_buyer_charged, 2) . " (Hammer $" . number_format($hammer_price, 2) . " + $" . number_format($buyer_premium, 2) . " Buyer Premium). Seller paid $" . number_format($seller_payout, 2) . " (after $" . number_format($seller_commission, 2) . " commission). Syndicate Profit: $" . number_format($syndicate_profit, 2) . ".";
        } else {
            // No bids. Just mark as settled with a 0 payment so it doesn't show up again.
            $log = $conn->prepare("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES (:uid, :aid, 'payment', 0)");
            $log->execute([':uid' => $auction['seller_id'], ':aid' => $auction_id]);
            
            $message = "Auction closed with no bids. Marked as settled.";
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Settlement Failed: " . $e->getMessage();
    }
}

// Fetch all closed but unsettled auctions
$unsettled = [];
try {
    $stmt = $conn->query("
        SELECT a.*, 
               u.alias as seller_name,
               (SELECT bid_amount FROM bids WHERE auction_id = a.auction_id ORDER BY bid_amount DESC LIMIT 1) as winning_bid,
               (SELECT alias FROM users WHERE user_id = (SELECT user_id FROM bids WHERE auction_id = a.auction_id ORDER BY bid_amount DESC LIMIT 1)) as winner_name
        FROM auctions a
        JOIN users u ON a.seller_id = u.user_id
        WHERE a.end_time < NOW()
        AND NOT EXISTS (
            SELECT 1 FROM transaction_ledger WHERE auction_id = a.auction_id AND type = 'payment'
        )
        ORDER BY a.end_time DESC
    ");
    $unsettled = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If ledger table doesn't exist yet, we will notify admin
    $error = "Database Table missing: Ensure transaction_ledger table is installed.";
}

// Fetch recent settlements for the audit log
$recent_settlements = [];
try {
    $filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $where_clauses = ["tl.type NOT IN ('escrow_hold', 'escrow_release')"];
    
    if ($filter_type === 'deposits') {
        $where_clauses[] = "tl.type = 'deposit'";
    } elseif ($filter_type === 'settlements') {
        $where_clauses[] = "tl.type IN ('payment', 'fee', 'buyer_fee', 'seller_fee', '')";
    }
    
    $where_sql = "WHERE " . implode(' AND ', $where_clauses);

    $stmt_recent = $conn->query("
        SELECT tl.*, a.title as auction_title, a.seller_id, u.alias as user_name 
        FROM transaction_ledger tl
        LEFT JOIN auctions a ON tl.auction_id = a.auction_id
        LEFT JOIN users u ON tl.user_id = u.user_id
        $where_sql
        ORDER BY tl.created_at DESC
        LIMIT 500
    ");
    $recent_settlements = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

?>

<style>
.settlement-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.settlement-title {
    color: var(--text-primary);
    font-size: 1.1rem;
    margin-bottom: 5px;
    text-transform: uppercase;
}
.settlement-meta {
    color: var(--text-secondary);
    font-size: 0.85rem;
}
.settlement-meta span {
    color: var(--accent-gold);
}
.btn-settle {
    background: var(--accent-gold);
    color: var(--text-inverse);
    padding: 10px 20px;
    border: none;
    cursor: pointer;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 0.8rem;
    transition: opacity 0.3s, box-shadow 0.3s;
}
.btn-settle:hover {
    opacity: 0.9;
    box-shadow: 0 0 15px var(--accent-gold-subtle);
}
.alert-box {
    padding: 15px;
    margin-bottom: 20px;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 0.85rem;
}
.alert-success { background: #146c2e; color: #fff; }
.alert-danger { background: #990000; color: #fff; }

.audit-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.audit-table th {
    padding: 12px;
    color: var(--text-secondary);
    font-size: 0.75rem;
    text-transform: uppercase;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
    transition: color 0.35s ease, border-color 0.35s ease;
}
.audit-table tr {
    border-bottom: 1px solid var(--border-subtle);
    transition: border-color 0.35s ease;
}
.audit-table td {
    padding: 12px;
    font-size: 0.85rem;
    transition: color 0.35s ease;
}
.audit-table .td-time, .audit-table .td-type { color: var(--text-secondary); }
.audit-table .td-auction { color: var(--text-primary); }
.audit-table .td-recipient { color: var(--accent-gold); }
.audit-table .td-amount { color: var(--text-primary); font-weight: bold; }
</style>

<div class="section-divider">Closed Auctions Awaiting Escrow Settlement</div>

<?php if (!empty($message)): ?>
    <div class="alert-box alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert-box alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<?php if (empty($unsettled)): ?>
    <div class="empty">All closed auctions have been settled. No pending escrows.</div>
<?php else: ?>
    <?php foreach ($unsettled as $item): 
        $hammer = (float)($item['winning_bid'] ?? 0);
        $b_premium = $hammer * 0.10;
        $b_total = $hammer + $b_premium;
        $s_commission = $hammer * 0.10;
        $s_payout = $hammer - $s_commission;
        $syndicate_profit = $b_premium + $s_commission;
    ?>
        <div class="settlement-card">
            <div>
                <div class="settlement-title"><?php echo htmlspecialchars($item['title']); ?></div>
                <div class="settlement-meta" style="line-height: 1.6;">
                    Seller: <span><?php echo htmlspecialchars($item['seller_name']); ?></span> | 
                    Winner: <span><?php echo $item['winner_name'] ? htmlspecialchars($item['winner_name']) : 'None (Passed)'; ?></span><br>
                    Hammer: <span>$<?php echo number_format($hammer, 2); ?></span> | 
                    Buyer Total (+10%): <span style="color: #ffc857;">$<?php echo number_format($b_total, 2); ?></span> | 
                    Seller Net (-10%): <span style="color: #4cd964;">$<?php echo number_format($s_payout, 2); ?></span> | 
                    Syndicate Cut: <span style="color: #C5A059; font-weight: bold;">$<?php echo number_format($syndicate_profit, 2); ?></span>
                </div>
            </div>
            <div>
                <form method="POST" action="settlements.php">
                    <input type="hidden" name="auction_id" value="<?php echo $item['auction_id']; ?>">
                    <button type="submit" name="settle_auction" class="btn-settle" onclick="return confirm('Execute dual settlement (Charge Winner +10% Premium, Pay Seller -10% Commission)?');">
                        Execute Settlement
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 50px; margin-bottom: 20px;">
    <div class="section-divider" style="margin-top: 0; margin-bottom: 0;">Recent Settlement Ledger (Audit Log)</div>
    <div style="display: flex; gap: 15px; align-items: center;">
        <div style="display: flex; gap: 5px; font-size: 0.75rem; text-transform: uppercase; font-weight: bold; border: 1px solid var(--border-color); padding: 3px; background: var(--bg-card);">
            <?php 
                $curr_filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
                
                $filters = [
                    'all' => 'All',
                    'settlements' => 'Settlements',
                    'deposits' => 'Deposits'
                ];
                
                foreach ($filters as $val => $label) {
                    $is_active = ($curr_filter === $val);
                    $bg = $is_active ? 'var(--accent-gold)' : 'transparent';
                    $color = $is_active ? 'var(--bg-primary)' : 'var(--text-secondary)';
                    
                    echo "<a href=\"settlements.php?filter={$val}\" style=\"text-decoration: none; padding: 6px 12px; color: {$color}; background: {$bg}; transition: 0.3s; display: inline-block;\">{$label}</a>";
                }
            ?>
        </div>
        <a href="settlements.php?export=csv&filter=<?php echo urlencode($curr_filter ?? 'all'); ?>" class="btn-settle" style="text-decoration: none; display: flex; align-items: center; justify-content: center; height: 32px; box-sizing: border-box; padding: 0 15px; font-size: 0.75rem;">Export CSV</a>
    </div>
</div>

<?php if (empty($recent_settlements)): ?>
    <div class="empty">No recent settlement activity logged.</div>
<?php else: ?>
    <table class="audit-table">
        <thead>
            <tr>
                <th>Time</th>
                <th>Auction</th>
                <th>Recipient</th>
                <th>Type</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_settlements as $log): ?>
                <tr>
                    <td class="td-time"><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></td>
                    <td class="td-auction"><?php echo htmlspecialchars($log['auction_title'] ?? 'N/A'); ?></td>
                    <td class="td-recipient"><?php echo htmlspecialchars($log['user_name'] ?? 'Syndicate'); ?></td>
                    <td class="td-type" style="text-transform: uppercase;">
                        <?php 
                        if ($log['type'] === 'fee' || $log['type'] === '' || $log['type'] === 'buyer_fee' || $log['type'] === 'seller_fee') {
                            if (!empty($log['seller_id']) && $log['user_id'] == $log['seller_id']) {
                                echo 'SELLER COMMISSION';
                            } else {
                                echo 'BUYER PREMIUM';
                            }
                        } else {
                            echo htmlspecialchars(str_replace('_', ' ', strtoupper($log['type']))); 
                        }
                        ?>
                    </td>
                    <td class="td-amount">$<?php echo number_format($log['amount'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
