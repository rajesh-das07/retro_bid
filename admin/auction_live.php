<?php
require_once 'header.php';

// Must be accessed with an ID
if (!isset($_GET['id'])) {
    header("Location: auctions.php");
    exit();
}

$auction_id = (int)$_GET['id'];

// --- OVERWATCH ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['overwatch_action'])) {
    $action = $_POST['overwatch_action'];
    
    try {
        if ($action === 'force_end') {
            // Drop the hammer immediately
            $stmt = $conn->prepare("UPDATE auctions SET end_time = NOW() WHERE auction_id = :id");
            $stmt->execute([':id' => $auction_id]);
            $overwatch_msg = "Auction Force Ended. The highest bidder has won.";
            
        } elseif ($action === 'terminate') {
            // Void the auction. Delete all bids, then the auction itself.
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM bids WHERE auction_id = :id")->execute([':id' => $auction_id]);
            $conn->prepare("DELETE FROM auctions WHERE auction_id = :id")->execute([':id' => $auction_id]);
            $conn->commit();
            header("Location: auctions.php");
            exit();
            
        } elseif ($action === 'ban_user' && isset($_POST['target_user'])) {
            $target = (int)$_POST['target_user'];
            $conn->prepare("UPDATE users SET banned_until = '9999-12-31 00:00:00' WHERE user_id = :uid")->execute([':uid' => $target]);
            $overwatch_msg = "User has been permanently banned.";
        }
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $overwatch_msg = "Error executing Overwatch command.";
    }
}

// Fetch the auction details
$stmt = $conn->prepare("
    SELECT a.*, COALESCE(MAX(b.bid_amount), a.current_high_bid) as true_high_bid 
    FROM auctions a 
    LEFT JOIN bids b ON a.auction_id = b.auction_id 
    WHERE a.auction_id = :id
    GROUP BY a.auction_id
");
$stmt->bindParam(':id', $auction_id);
$stmt->execute();
$auction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$auction) {
    header("Location: auctions.php");
    exit();
}

// Calculate Time Remaining
$now = time();
$end_time = strtotime($auction['end_time']);
$is_ended = $now >= $end_time;

$seconds_left = $end_time - $now;
if ($seconds_left < 0) $seconds_left = 0;

$h = str_pad(floor($seconds_left / 3600), 2, "0", STR_PAD_LEFT);
$m = str_pad(floor(($seconds_left % 3600) / 60), 2, "0", STR_PAD_LEFT);
$s = str_pad($seconds_left % 60, 2, "0", STR_PAD_LEFT);

$raw_img = $auction['image_url'] ?? '';
$img_src = (strpos($raw_img, 'http://') === 0 || strpos($raw_img, 'https://') === 0 || strpos($raw_img, '/') === 0)
    ? $raw_img 
    : '../' . ltrim($raw_img, './');
?>
<style>
/* Mimic the public auction room, but adaptable for Admin Overwatch */
.overwatch-container {
    display: flex;
    gap: 40px;
    margin-top: 20px;
}
.display-side {
    flex: 1;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 20px;
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.display-side img {
    width: 100%;
    max-height: 400px;
    object-fit: cover;
    margin-bottom: 20px;
    border: 1px solid var(--border-subtle);
}
.terminal-side {
    flex: 1;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 30px;
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.data-strip {
    display: flex;
    justify-content: space-between;
    border-top: 1px solid var(--border-subtle);
    border-bottom: 1px solid var(--border-subtle);
    padding: 20px 0;
    margin-bottom: 30px;
}
.data-block h4 { color: var(--text-secondary); font-size: 0.75rem; text-transform: uppercase; margin-bottom: 10px; }
.current-price { font-size: 2.5rem; color: var(--text-primary); font-weight: bold; }
.time-left { font-size: 2.5rem; color: var(--accent-gold); font-family: monospace; }
.closed-text { color: var(--text-secondary); }

.controls-box {
    background: var(--bg-card);
    padding: 20px;
    border: 1px solid #990000;
    margin-bottom: 40px;
}
.controls-box h3 { color: #990000; text-transform: uppercase; margin-bottom: 15px; font-size: 1rem; }
.controls-box p { color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 20px; line-height: 1.5; }
.btn-force {
    background: #990000; color: #fff; border: none; padding: 12px 20px; text-transform: uppercase;
    font-weight: bold; cursor: pointer; margin-right: 10px; font-size: 0.8rem; transition: opacity 0.3s;
}
.btn-force:hover { opacity: 0.9; }
.btn-void {
    background: transparent; color: var(--text-secondary); border: 1px solid var(--border-color); padding: 12px 20px;
    text-transform: uppercase; font-weight: bold; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;
}
.btn-void:hover { background: var(--bg-primary); color: var(--text-primary); border-color: var(--text-primary); }

.ledger-feed { list-style: none; margin-top: 20px; }
.ledger-entry { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border-subtle); }
.ledger-entry span { color: var(--text-primary); }
.btn-ban-sm { background: #990000; color: #fff; border: none; padding: 4px 8px; font-size: 0.7rem; cursor: pointer; font-weight: bold; text-transform: uppercase; transition: opacity 0.3s;}
.btn-ban-sm:hover { opacity: 0.9; }
</style>

<a href="auctions.php" style="color: var(--text-secondary); text-decoration: none; text-transform: uppercase; font-size: 0.8rem;">&#8592; Back to Active Auctions</a>

<div class="overwatch-container">
    <div class="display-side">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
            <h2 style="color:#C5A059; text-transform:uppercase; font-size: 1.2rem;"><?php echo htmlspecialchars($auction['title']); ?></h2>
            <span style="background: rgba(197, 160, 89, 0.2); color: #C5A059; padding: 4px 10px; font-size:0.75rem; border-radius:2px; text-transform:uppercase;">Overwatch Active</span>
        </div>
        <img src="<?php echo htmlspecialchars($img_src); ?>" alt="Artifact" onerror="this.src='../images/placeholder.jpg';">
        <p style="color:#888; font-size:0.9rem; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($auction['description'] ?? '')); ?></p>
    </div>

    <div class="terminal-side">
        <?php if (isset($overwatch_msg)): ?>
            <div style="background: #146c2e; color:#fff; padding: 10px; margin-bottom: 20px; font-weight:bold; font-size:0.85rem; text-transform:uppercase;">
                <?php echo $overwatch_msg; ?>
            </div>
        <?php endif; ?>

        <div class="data-strip">
            <div class="data-block">
                <h4><?php echo $is_ended ? "Final Hammer Price" : "Current High Bid"; ?></h4>
                <div class="current-price" id="ow-price">$<?php echo number_format($auction['true_high_bid'], 2); ?></div>
            </div>
            <div class="data-block">
                <h4>Status</h4>
                <div class="time-left <?php echo $is_ended ? 'closed-text' : ''; ?>" id="ow-timer">
                    <?php echo $is_ended ? "CLOSED" : "$h:$m:$s"; ?>
                </div>
            </div>
        </div>

        <?php if (!$is_ended): ?>
        <div class="controls-box">
            <h3>God Mode Controls</h3>
            <p><strong>Force End:</strong> Closes the auction immediately and awards the artifact to the highest bidder.<br>
            <strong>Terminate:</strong> Voids the auction entirely, deletes all bids, and removes it from the directory.</p>
            
            <form method="POST" style="display: inline-block;">
                <input type="hidden" name="overwatch_action" value="force_end">
                <button class="btn-force" onclick="return confirm('Drop the hammer? The highest bidder will win immediately.');">Force End Auction</button>
            </form>
            <form method="POST" style="display: inline-block;">
                <input type="hidden" name="overwatch_action" value="terminate">
                <button class="btn-void" onclick="return confirm('Are you sure? This will VOID the auction and delete all bids permanently.');">Terminate & Void</button>
            </form>
        </div>
        <?php else: ?>
            <div class="controls-box" style="border-color: #333;">
                <h3 style="color: #888;">Auction Concluded</h3>
                <p>This arena is closed. No further god-mode actions can be taken.</p>
            </div>
        <?php endif; ?>

        <h3 style="color: #888; text-transform: uppercase; font-size: 1rem; border-bottom: 1px solid #333; padding-bottom: 10px;">Live Ledger Moderation</h3>
        <ul class="ledger-feed" id="ow-ledger">
            <!-- Populated by JS -->
            <li style="color:#555; font-style:italic;">Syncing with live ledger...</li>
        </ul>
    </div>
</div>

<script>
    let secondsRemaining = <?php echo $seconds_left; ?>;
    const timeDisplay = document.getElementById('ow-timer');

    // Timer countdown
    setInterval(() => {
        if (secondsRemaining <= 0) return;
        secondsRemaining--;
        
        let h = Math.floor(secondsRemaining / 3600).toString().padStart(2, '0');
        let m = Math.floor((secondsRemaining % 3600) / 60).toString().padStart(2, '0');
        let s = (secondsRemaining % 60).toString().padStart(2, '0');
        
        timeDisplay.innerText = `${h}:${m}:${s}`;

        if (secondsRemaining === 0) {
            timeDisplay.innerText = "CLOSED";
            timeDisplay.classList.add('closed-text');
            location.reload(); // Reload to remove controls
        }
    }, 1000);

    // Live Ledger Sync
    setInterval(() => {
        fetch('../fetch_live_data.php?id=<?php echo $auction_id; ?>')
        .then(response => response.json())
        .then(data => {
            if (data.error) return;
            
            const priceDisplay = document.getElementById('ow-price');
            const formattedPrice = parseFloat(data.true_high_bid).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            priceDisplay.innerText = '$' + formattedPrice;
            
            const ledgerFeed = document.getElementById('ow-ledger');
            if (data.history && data.history.length > 0) {
                let newHtml = '';
                data.history.forEach((bid, index) => {
                    const bidAmount = parseFloat(bid.bid_amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    const color = index === 0 ? '#C5A059' : '#F7F4EB';
                    
                    newHtml += `
                    <li class="ledger-entry">
                        <div>
                            <span style="color: ${color}; font-weight: ${index===0 ? 'bold' : 'normal'}; margin-right: 15px;">${bid.alias} ${index===0 ? '(Winning)' : ''}</span>
                            <form method="POST" style="display:inline-block; margin:0;">
                                <input type="hidden" name="overwatch_action" value="ban_user">
                                <input type="hidden" name="target_user" value="${bid.user_id}">
                                <button type="submit" class="btn-ban-sm" onclick="return confirm('Permanently ban this user?');">Ban</button>
                            </form>
                        </div>
                        <div>
                            <span style="margin-right: 15px;">$${bidAmount}</span>
                        </div>
                    </li>`;
                });
                ledgerFeed.innerHTML = newHtml;
            } else {
                ledgerFeed.innerHTML = '<li style="color:#555; font-style:italic;">No bids recorded yet.</li>';
            }
            
            if (data.seconds_remaining !== undefined && data.seconds_remaining <= 0) {
                secondsRemaining = 0;
            }
        });
    }, 2000);
</script>

<?php require_once 'footer.php'; ?>
