<?php
require_once 'header.php';

// Handle Action Requests (Ban/Unban)
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
        // Fail silently if banned_until doesn't exist
    }
    
    // Refresh to show updated statuses
    header("Location: auctions.php");
    exit();
}

// Fetch Live Auctions
$live_stmt = $conn->query("
    SELECT a.*, u.alias as seller_alias 
    FROM auctions a
    JOIN users u ON a.seller_id = u.user_id
    WHERE a.start_time <= NOW() AND a.end_time > NOW()
    ORDER BY a.end_time ASC
");
$live_auctions = $live_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Upcoming Auctions
$upcoming_stmt = $conn->query("
    SELECT a.*, u.alias as seller_alias 
    FROM auctions a
    JOIN users u ON a.seller_id = u.user_id
    WHERE a.start_time > NOW()
    ORDER BY a.start_time ASC
");
$upcoming_auctions = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<style>
.auction-block {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 20px;
    margin-bottom: 30px;
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.auction-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-subtle);
    padding-bottom: 15px;
    margin-bottom: 15px;
}
.auction-title {
    color: var(--text-primary);
    font-size: 1.2rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}
.auction-meta {
    color: var(--text-secondary);
    font-size: 0.85rem;
}
.auction-meta span {
    color: var(--accent-gold);
}
.bids-table {
    width: 100%;
    border-collapse: collapse;
}
.bids-table th, .bids-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid var(--border-subtle);
}
.bids-table th {
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.1em;
}
.bids-table tr:hover {
    background: var(--accent-gold-subtle);
}
.highest-bidder td {
    color: var(--accent-gold);
    font-weight: bold;
}
.badge {
    padding: 4px 8px;
    border-radius: 2px;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: bold;
}
.badge-live { background: #146c2e; color: #fff; }
.badge-upcoming { background: var(--border-color); color: var(--text-secondary); }
.btn-action {
    padding: 4px 10px;
    border: none;
    font-size: 0.7rem;
    cursor: pointer;
    text-transform: uppercase;
    font-weight: bold;
    transition: opacity 0.3s;
}
.btn-action:hover { opacity: 0.9; }
.btn-ban-24 { background: #888; color: #fff; }
.btn-ban-perm { background: #990000; color: #fff; }
.btn-unban { background: #146c2e; color: #fff; }
.banned-text { color: #990000; font-size: 0.75rem; margin-left: 10px; }
</style>

<div class="section-divider">Live Auctions Tracker</div>

<?php if (empty($live_auctions)): ?>
    <div class="empty">No live auctions currently running.</div>
<?php else: ?>
    <?php foreach ($live_auctions as $auction): ?>
        <div class="auction-block">
            <div class="auction-header">
                <div>
                    <span class="badge badge-live">LIVE</span>
                    <h3 class="auction-title" style="display:inline-block; margin-left:15px;">
                        <a href="auction_live.php?id=<?php echo $auction['auction_id']; ?>" style="color: #C5A059; text-decoration: none;">
                            <?php echo htmlspecialchars($auction['title']); ?> &#8594;
                        </a>
                    </h3>
                </div>
                <div class="auction-meta">
                    Ends: <span><?php echo date('M d, H:i', strtotime($auction['end_time'])); ?></span> | 
                    Seller: <span><?php echo htmlspecialchars($auction['seller_alias']); ?></span>
                </div>
            </div>
            
            <?php
            // Fetch bids for this auction, avoiding exception if banned_until doesn't exist yet
            $bids = [];
            try {
                $bstmt = $conn->prepare("
                    SELECT b.*, u.alias, u.banned_until
                    FROM bids b
                    JOIN users u ON b.user_id = u.user_id
                    WHERE b.auction_id = :aid
                    ORDER BY b.bid_amount DESC, b.bid_time ASC
                ");
                $bstmt->execute([':aid' => $auction['auction_id']]);
                $bids = $bstmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Fallback (if banned_until or bid_time don't exist yet)
                $bstmt = $conn->prepare("
                    SELECT b.user_id, b.bid_amount, u.alias
                    FROM bids b
                    JOIN users u ON b.user_id = u.user_id
                    WHERE b.auction_id = :aid
                    ORDER BY b.bid_amount DESC
                ");
                $bstmt->execute([':aid' => $auction['auction_id']]);
                $bids = $bstmt->fetchAll(PDO::FETCH_ASSOC);
            }
            ?>
            
            <?php if (empty($bids)): ?>
                <p style="color:#888; font-style:italic; font-size:0.85rem;">No bids placed yet.</p>
            <?php else: ?>
                <table class="bids-table">
                    <thead>
                        <tr>
                            <th>Bid Amount</th>
                            <th>Bidder</th>
                            <th>Time</th>
                            <th>Moderation Controls</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $is_highest = true; ?>
                        <?php foreach ($bids as $bid): ?>
                            <?php
                            $is_banned = isset($bid['banned_until']) && !empty($bid['banned_until']) && (strtotime($bid['banned_until']) > time() || $bid['banned_until'] == '9999-12-31 00:00:00');
                            ?>
                            <tr class="<?php echo $is_highest ? 'highest-bidder' : ''; ?>">
                                <td>$<?php echo number_format($bid['bid_amount'], 2); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($bid['alias']); ?>
                                    <?php if ($is_banned): ?>
                                        <span class="banned-text">(BANNED)</span>
                                    <?php endif; ?>
                                </td>
                                <?php
                                $bid_time = isset($bid['bid_time']) && !empty($bid['bid_time']) 
                                    ? date('H:i:s - M d', strtotime($bid['bid_time'])) 
                                    : 'Unknown';
                                ?>
                                <td><?php echo $bid_time; ?></td>
                                <td>
                                    <div style="display:flex; gap:5px;">
                                        <?php if ($is_banned): ?>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="target_user" value="<?php echo $bid['user_id']; ?>">
                                                <button type="submit" name="action" value="unban" class="btn-action btn-unban">Unban</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="target_user" value="<?php echo $bid['user_id']; ?>">
                                                <button type="submit" name="action" value="ban_24h" class="btn-action btn-ban-24">Ban 24H</button>
                                            </form>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="target_user" value="<?php echo $bid['user_id']; ?>">
                                                <button type="submit" name="action" value="ban_permanent" class="btn-action btn-ban-perm">Permaban</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php $is_highest = false; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="section-divider">Upcoming Previews</div>

<?php if (empty($upcoming_auctions)): ?>
    <div class="empty">No upcoming auctions scheduled.</div>
<?php else: ?>
    <?php foreach ($upcoming_auctions as $auction): ?>
        <div class="auction-block" style="opacity: 0.7;">
            <div class="auction-header" style="border-bottom:none; margin-bottom:0; padding-bottom:0;">
                <div>
                    <span class="badge badge-upcoming">UPCOMING</span>
                    <h3 class="auction-title" style="display:inline-block; margin-left:15px; color:#888;">
                        <?php echo htmlspecialchars($auction['title']); ?>
                    </h3>
                </div>
                <div class="auction-meta">
                    Starts: <span><?php echo date('M d, H:i', strtotime($auction['start_time'])); ?></span> | 
                    Seller: <span><?php echo htmlspecialchars($auction['seller_alias']); ?></span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
