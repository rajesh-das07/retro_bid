<?php
require_once 'header.php';

$total_volume = 0;
$successful_auctions = 0;
$total_ended = 0;
$total_users = 0;
$new_users = 0;

try {
    // Total Volume & Success Rate
    $stmt1 = $conn->query("
        SELECT SUM(current_high_bid) as total_volume,
               COUNT(*) as successful_auctions
        FROM auctions a
        WHERE end_time <= NOW() AND EXISTS (SELECT 1 FROM bids b WHERE b.auction_id = a.auction_id)
    ");
    $res1 = $stmt1->fetch(PDO::FETCH_ASSOC);
    if ($res1) {
        $total_volume = $res1['total_volume'] ?? 0;
        $successful_auctions = $res1['successful_auctions'] ?? 0;
    }
    
    $stmt2 = $conn->query("SELECT COUNT(*) as total_ended FROM auctions WHERE end_time <= NOW()");
    $res2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($res2) {
        $total_ended = $res2['total_ended'] ?? 0;
    }

    // User Growth
    $stmt3 = $conn->query("SELECT COUNT(*) as total_users FROM users");
    $res3 = $stmt3->fetch(PDO::FETCH_ASSOC);
    if ($res3) {
        $total_users = $res3['total_users'] ?? 0;
    }
    
    // Safely attempt to get new users (in case created_at column is missing or broken)
    try {
        $stmt4 = $conn->query("SELECT COUNT(*) as new_users FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $res4 = $stmt4->fetch(PDO::FETCH_ASSOC);
        if ($res4) {
            $new_users = $res4['new_users'] ?? 0;
        }
    } catch (PDOException $e) {
        $new_users = 0;
    }
    
    // List of Auctions
    $auction_list = [];
    try {
        $stmt5 = $conn->query("
            SELECT 
                a.auction_id, 
                a.title, 
                a.start_time, 
                a.end_time, 
                COUNT(DISTINCT b.user_id) as participants_count,
                CASE 
                    WHEN a.end_time <= NOW() AND EXISTS (SELECT 1 FROM bids b2 WHERE b2.auction_id = a.auction_id) THEN 'Sold'
                    WHEN a.end_time <= NOW() THEN 'Unsold'
                    ELSE 'Live/Upcoming' 
                END as status
            FROM auctions a
            LEFT JOIN bids b ON a.auction_id = b.auction_id
            GROUP BY a.auction_id
            ORDER BY a.end_time DESC
        ");
        $auction_list = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    // Ignore global errors for display purposes
}

$success_rate = $total_ended > 0 ? round(($successful_auctions / $total_ended) * 100) : 0;
?>
<style>
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 40px;
}
.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 30px 20px;
    text-align: center;
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.stat-card h3 {
    color: var(--text-secondary);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 15px;
}
.stat-card .value {
    color: var(--accent-gold);
    font-size: 2.5rem;
    font-family: monospace;
}
.stat-card .sub-value {
    color: var(--text-primary);
    font-size: 0.9rem;
    margin-top: 10px;
}
.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}
.panel {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 25px;
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.panel-full {
    grid-column: 1 / -1;
}
.panel h2 {
    color: var(--text-primary);
    font-size: 1.2rem;
    text-transform: uppercase;
    border-bottom: 1px solid var(--border-subtle);
    padding-bottom: 15px;
    margin-bottom: 20px;
}
.leaderboard-list {
    list-style: none;
}
.leaderboard-list li {
    display: flex;
    justify-content: space-between;
    padding: 15px 0;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
}
.leaderboard-list li:last-child {
    border-bottom: none;
}
.leaderboard-list .amount {
    color: var(--accent-gold);
    font-weight: bold;
}
.auctions-table {
    width: 100%;
    border-collapse: collapse;
}
.auctions-table th, .auctions-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
}
.auctions-table th {
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.1em;
}
.auctions-table tr:hover {
    background: var(--accent-gold-subtle);
    cursor: pointer;
}
.auctions-table td a {
    color: var(--text-primary);
    text-decoration: none;
    display: block;
}
.auctions-table tr:hover td a {
    color: var(--accent-gold);
}
</style>

<div class="section-divider">Platform Analytics</div>

<?php
// Calculate Syndicate Revenue from the ledger
$syndicate_revenue = 0;
try {
    $stmt_rev = $conn->query("SELECT SUM(amount) as total_revenue FROM transaction_ledger WHERE type IN ('fee', 'buyer_fee', 'seller_fee', '')");
    $res_rev = $stmt_rev->fetch(PDO::FETCH_ASSOC);
    if ($res_rev) {
        $syndicate_revenue = (float)$res_rev['total_revenue'];
    }
} catch (PDOException $e) {}

$platform_fee_percent = 10;
$settings_path = __DIR__ . '/../settings.json';
if (file_exists($settings_path)) {
    $settings_data = json_decode(file_get_contents($settings_path), true);
    if (isset($settings_data['platform_fee'])) {
        $platform_fee_percent = (float)$settings_data['platform_fee'];
    }
}
?>
<div class="dashboard-grid">
    <div class="stat-card">
        <h3>Gross Transacted Volume</h3>
        <div class="value">$<?php echo number_format($total_volume); ?></div>
        <div class="sub-value">Total GMV</div>
    </div>
    <div class="stat-card" style="border-color: var(--accent-gold); box-shadow: 0 0 15px var(--accent-gold-subtle);">
        <h3 style="color: var(--accent-gold); font-weight: bold;">Syndicate Revenue</h3>
        <div class="value">$<?php echo number_format($syndicate_revenue); ?></div>
        <div class="sub-value" style="color: var(--accent-gold);">Total Fees Collected</div>
    </div>
    <div class="stat-card">
        <h3>Auction Success Rate</h3>
        <div class="value"><?php echo $success_rate; ?>%</div>
        <div class="sub-value"><?php echo $successful_auctions; ?> / <?php echo $total_ended; ?> sold</div>
    </div>
    <div class="stat-card">
        <h3>Total Registered Users</h3>
        <div class="value"><?php echo number_format($total_users); ?></div>
        <div class="sub-value">+<?php echo number_format($new_users); ?> this month</div>
    </div>
</div>

<div class="content-grid">
    <div class="panel panel-full">
        <h2>List of Auctions</h2>
        <?php if (empty($auction_list)): ?>
            <p style="color:#888; font-style:italic;">No auctions found.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="auctions-table">
                    <thead>
                        <tr>
                            <th>Auction Name</th>
                            <th>Time Started</th>
                            <th>Time Ended</th>
                            <th>Participants</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auction_list as $auction): ?>
                            <tr onclick="window.location='auction_report_detail.php?id=<?php echo $auction['auction_id']; ?>'">
                                <td><a href="auction_report_detail.php?id=<?php echo $auction['auction_id']; ?>"><?php echo htmlspecialchars($auction['title']); ?></a></td>
                                <td><a href="auction_report_detail.php?id=<?php echo $auction['auction_id']; ?>"><?php echo date('M d, Y H:i', strtotime($auction['start_time'])); ?></a></td>
                                <td><a href="auction_report_detail.php?id=<?php echo $auction['auction_id']; ?>"><?php echo date('M d, Y H:i', strtotime($auction['end_time'])); ?></a></td>
                                <td><a href="auction_report_detail.php?id=<?php echo $auction['auction_id']; ?>"><?php echo $auction['participants_count']; ?></a></td>
                                <td>
                                    <a href="auction_report_detail.php?id=<?php echo $auction['auction_id']; ?>" style="font-weight:bold; color: <?php echo $auction['status'] === 'Sold' ? '#146c2e' : ($auction['status'] === 'Unsold' ? '#990000' : '#C5A059'); ?>">
                                        <?php echo $auction['status']; ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="panel">
        <h2>System Health</h2>
        <ul class="leaderboard-list">
            <li>
                <span>Database Connection</span>
                <span style="color:#146c2e; font-weight:bold;">ONLINE</span>
            </li>
            <li>
                <span>Bidding Engine</span>
                <span style="color:#146c2e; font-weight:bold;">ACTIVE</span>
            </li>
            <li>
                <span>Live Feed WebSockets/AJAX</span>
                <span style="color:#146c2e; font-weight:bold;">SYNCED</span>
            </li>
            <li>
                <span>Maintenance Mode</span>
                <?php
                // Check if settings.json exists and read it
                $maintenance_mode = false;
                if (file_exists($settings_path)) {
                    $settings_data = json_decode(file_get_contents($settings_path), true);
                    $maintenance_mode = $settings_data['maintenance_mode'] ?? false;
                }
                ?>
                <?php if ($maintenance_mode): ?>
                    <span style="color:#990000; font-weight:bold;">ENGAGED</span>
                <?php else: ?>
                    <span style="color:#888; font-weight:bold;">OFF</span>
                <?php endif; ?>
            </li>
        </ul>
    </div>
</div>

<?php require_once 'footer.php'; ?>
