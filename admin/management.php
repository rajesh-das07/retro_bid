<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['target_user'])) {
    $target_user = (int)$_POST['target_user'];
    
    try {
        if ($_POST['action'] === 'ban_24h') {
            $conn->prepare("UPDATE users SET banned_until = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE user_id = :id")->execute([':id' => $target_user]);
        } elseif ($_POST['action'] === 'ban_permanent') {
            $conn->prepare("UPDATE users SET banned_until = '9999-12-31 00:00:00' WHERE user_id = :id")->execute([':id' => $target_user]);
        } elseif ($_POST['action'] === 'unban') {
            $conn->prepare("UPDATE users SET banned_until = NULL WHERE user_id = :id")->execute([':id' => $target_user]);
        }
    } catch (PDOException $e) {
        // Will fail silently if banned_until doesn't exist
    }
    
    header("Location: management.php");
    exit();
}

// Safe queries that won't fail if the columns don't exist yet
$total_users = 0;
$online_users = 0;
$added_this_month = 0;
$active_participants = 0;

try {
    // 1. Total Users
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $total_users = $stmt->fetchColumn();

    // 2. Online Users (Active in last 5 minutes)
    // Wrap in try-catch in case they haven't added the columns yet
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE last_active >= NOW() - INTERVAL 5 MINUTE");
        $online_users = $stmt->fetchColumn();
        
        $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        $added_this_month = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $schema_error = "Please run the SQL commands in phpMyAdmin to see Online/Monthly stats.";
    }

    // 3. Offline Users
    $offline_users = $total_users - $online_users;

    // 4. Active Participants (Bidding or Selling in active auctions)
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) FROM (
            SELECT seller_id AS user_id FROM auctions WHERE end_time > NOW()
            UNION
            SELECT user_id FROM bids b JOIN auctions a ON b.auction_id = a.auction_id WHERE a.end_time > NOW()
        ) active_users
    ");
    $active_participants = $stmt->fetchColumn();

} catch (PDOException $e) {
    // General error fallback
}

// 5. Fetch All Users for Detailed List
$all_users = [];
try {
    $stmt_users = $conn->query("SELECT user_id, alias, email, available_funds, created_at, last_active, banned_until FROM users ORDER BY created_at DESC");
    $all_users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback if created_at/last_active/banned_until columns don't exist yet
    $stmt_users = $conn->query("SELECT user_id, alias, email, available_funds FROM users ORDER BY user_id DESC");
    $all_users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
}

?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}
.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 30px 20px;
    text-align: center;
    transition: all 0.35s ease;
}
.stat-card:hover {
    border-color: var(--accent-gold);
}
.stat-title {
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.1em;
    margin-bottom: 15px;
}
.stat-value {
    color: var(--accent-gold);
    font-size: 2.5rem;
    font-weight: bold;
}
.alert {
    background: #990000;
    color: white;
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid #ff4444;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.data-table th, .data-table td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid var(--border-subtle);
}
.data-table th {
    color: var(--accent-gold);
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.1em;
}
.data-table tr:hover {
    background: var(--accent-gold-subtle);
}
.status-online {
    color: #146c2e;
    font-weight: bold;
}
.status-offline {
    color: var(--text-secondary);
}
</style>

<div class="section-divider">Platform Telemetry (Management)</div>

<?php if (isset($schema_error)): ?>
    <div class="alert">
        <strong>Database Update Required:</strong> <?php echo $schema_error; ?>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-title">Total Users</div>
        <div class="stat-value"><?php echo number_format($total_users); ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Users Online Now</div>
        <div class="stat-value"><?php echo number_format($online_users); ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Users Offline</div>
        <div class="stat-value"><?php echo number_format($offline_users); ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">New Users (This Month)</div>
        <div class="stat-value"><?php echo number_format($added_this_month); ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-title">Active Participants (Live Auctions)</div>
        <div class="stat-value"><?php echo number_format($active_participants); ?></div>
    </div>
</div>

<div class="section-divider">User Directory</div>
<div style="overflow-x: auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Alias</th>
                <th>Email</th>
                <th>Available Funds</th>
                <th>Joined</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($all_users)): ?>
                <tr><td colspan="7" style="text-align: center; color: #555; padding: 30px;">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($all_users as $user): ?>
                    <?php
                    // Determine online status if last_active exists
                    $status = '<span class="status-offline">Offline</span>';
                    if (isset($user['last_active']) && !empty($user['last_active'])) {
                        $last_active_time = strtotime($user['last_active']);
                        if (time() - $last_active_time <= 300) { // 5 minutes
                            $status = '<span class="status-online">Online</span>';
                        } else {
                            // Show how long ago they were active
                            $status = '<span class="status-offline">Last seen: ' . date('M d, H:i', $last_active_time) . '</span>';
                        }
                    }
                    
                    $is_banned = isset($user['banned_until']) && !empty($user['banned_until']) && (strtotime($user['banned_until']) > time() || $user['banned_until'] == '9999-12-31 00:00:00');
                    if ($is_banned) {
                        if ($user['banned_until'] == '9999-12-31 00:00:00') {
                            $status .= '<br><span style="color:#990000;font-size:0.75rem;">(Permabanned)</span>';
                        } else {
                            $status .= '<br><span style="color:#990000;font-size:0.75rem;">(Banned till ' . date('M d', strtotime($user['banned_until'])) . ')</span>';
                        }
                    }
                    
                    $joined = isset($user['created_at']) && !empty($user['created_at']) 
                        ? date('M d, Y', strtotime($user['created_at'])) 
                        : 'Unknown';
                    ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($user['user_id']); ?></td>
                        <td><?php echo htmlspecialchars($user['alias']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td>$<?php echo number_format($user['available_funds'], 2); ?></td>
                        <td><?php echo $joined; ?></td>
                        <td><?php echo $status; ?></td>
                        <td>
                            <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                <?php if ($is_banned): ?>
                                    <form method="POST" style="margin:0;"><input type="hidden" name="target_user" value="<?php echo $user['user_id']; ?>"><button type="submit" name="action" value="unban" style="padding:5px 10px; font-size:0.7rem; background:#146c2e; border:none; color:#fff; cursor:pointer; font-weight:bold; text-transform:uppercase;">Unban</button></form>
                                <?php else: ?>
                                    <form method="POST" style="margin:0;"><input type="hidden" name="target_user" value="<?php echo $user['user_id']; ?>"><button type="submit" name="action" value="ban_24h" style="padding:5px 10px; font-size:0.7rem; background:#888; border:none; color:#fff; cursor:pointer; font-weight:bold; text-transform:uppercase;">Ban 24H</button></form>
                                    <form method="POST" style="margin:0;"><input type="hidden" name="target_user" value="<?php echo $user['user_id']; ?>"><button type="submit" name="action" value="ban_permanent" style="padding:5px 10px; font-size:0.7rem; background:#990000; border:none; color:#fff; cursor:pointer; font-weight:bold; text-transform:uppercase;">Permaban</button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once 'footer.php'; ?>
