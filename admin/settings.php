<?php
require_once 'header.php';

$settings_file = __DIR__ . '/../settings.json';
$settings = [
    'maintenance_mode' => false,
    'min_bid_increment' => 200,
    'platform_fee' => 10,
    'admin_passcode' => 'admin'
];

if (file_exists($settings_file)) {
    $file_content = file_get_contents($settings_file);
    if ($file_content) {
        $settings = array_merge($settings, json_decode($file_content, true));
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $was_maintenance_mode = !empty($settings['maintenance_mode']);
    $settings['maintenance_mode'] = isset($_POST['maintenance_mode']) ? true : false;
    
    // Trigger Maintenance Void Logic
    if ($settings['maintenance_mode'] && !$was_maintenance_mode) {
        try {
            // Find all live auctions
            $live_auctions = $conn->query("SELECT auction_id, seller_id, title FROM auctions WHERE start_time <= NOW() AND end_time > NOW()")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($live_auctions as $auc) {
                $aid = (int)$auc['auction_id'];
                
                // Find highest bidder to refund escrow
                $top_bid = $conn->query("SELECT user_id, bid_amount FROM bids WHERE auction_id = $aid ORDER BY bid_amount DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($top_bid) {
                    $b_uid = (int)$top_bid['user_id'];
                    $b_amt = (float)$top_bid['bid_amount'];
                    
                    // Refund to available_funds
                    $conn->query("UPDATE users SET available_funds = available_funds + $b_amt WHERE user_id = $b_uid");
                    
                    // Log escrow release
                    $conn->query("INSERT INTO transaction_ledger (user_id, auction_id, type, amount) VALUES ($b_uid, $aid, 'escrow_release', $b_amt)");
                }

                // Delete all bids for this auction
                $conn->query("DELETE FROM bids WHERE auction_id = $aid");
                
                // Delete the auction
                $conn->query("DELETE FROM auctions WHERE auction_id = $aid");
            }
        } catch (PDOException $e) {}
    }
    
    if (isset($_POST['min_bid_increment']) && is_numeric($_POST['min_bid_increment'])) {
        $settings['min_bid_increment'] = (int)$_POST['min_bid_increment'];
    }
    
    if (isset($_POST['platform_fee']) && is_numeric($_POST['platform_fee'])) {
        $settings['platform_fee'] = (float)$_POST['platform_fee'];
    }
    
    if (isset($_POST['admin_passcode']) && !empty($_POST['admin_passcode'])) {
        $settings['admin_passcode'] = $_POST['admin_passcode'];
    }
    
    file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
    $success_msg = "Platform settings updated successfully.";
    
    // Also update the active master passcode in memory if changed
    $master_passcode = $settings['admin_passcode'];
}

?>
<style>
.settings-container {
    max-width: 800px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-card);
    padding: 40px;
    transition: background-color 0.35s ease, border-color 0.35s ease;
}
.setting-group {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-subtle);
}
.setting-group:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.setting-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.setting-title {
    color: var(--text-primary);
    font-size: 1.2rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}
.setting-desc {
    color: var(--text-secondary);
    font-size: 0.85rem;
    line-height: 1.5;
    margin-bottom: 15px;
}

/* Toggle Switch CSS */
.switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background-color: var(--border-color); transition: .4s; border-radius: 34px;
}
.slider:before {
    position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px;
    background-color: white; transition: .4s; border-radius: 50%;
}
input:checked + .slider { background-color: #990000; }
input:focus + .slider { box-shadow: 0 0 1px #990000; }
input:checked + .slider:before { transform: translateX(26px); }

.input-field {
    width: 100%;
    padding: 15px;
    background: var(--bg-primary);
    border: 1px solid var(--border-subtle);
    color: var(--accent-gold);
    font-family: monospace;
    font-size: 1.1rem;
    outline: none;
    transition: border-color 0.3s;
}
.input-field:focus {
    border-color: var(--accent-gold);
}
.btn-save {
    background: var(--accent-gold);
    color: var(--text-inverse);
    border: none;
    padding: 15px 30px;
    text-transform: uppercase;
    font-weight: bold;
    cursor: pointer;
    font-size: 1rem;
    margin-top: 20px;
    transition: opacity 0.3s, box-shadow 0.3s;
}
.btn-save:hover {
    opacity: 0.9;
    box-shadow: 0 0 15px var(--accent-gold-subtle);
}
</style>

<div class="section-divider">Platform Settings</div>

<div class="settings-container">
    <?php if (isset($success_msg)): ?>
        <div style="background: #146c2e; color:#fff; padding: 15px; margin-bottom: 30px; font-weight:bold; font-size:0.9rem; text-transform:uppercase;">
            <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="settings.php">
        <input type="hidden" name="update_settings" value="1">
        
        <div class="setting-group">
            <div class="setting-header">
                <div class="setting-title">Maintenance Mode</div>
                <label class="switch">
                    <input type="checkbox" name="maintenance_mode" <?php echo !empty($settings['maintenance_mode']) ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="setting-desc">
                When enabled, the public-facing website will be completely inaccessible. All users will see a "Vault is currently upgrading" message. Admin access will remain fully functional.
            </div>
        </div>

        <div class="setting-group">
            <div class="setting-header">
                <div class="setting-title">Minimum Bid Increment ($)</div>
            </div>
            <div class="setting-desc">
                The minimum dollar amount a user must bid above the current highest bid. 
            </div>
            <input type="number" name="min_bid_increment" class="input-field" value="<?php echo htmlspecialchars($settings['min_bid_increment']); ?>" min="1" required>
        </div>

        <div class="setting-group">
            <div class="setting-header">
                <div class="setting-title">Platform Fee (%)</div>
            </div>
            <div class="setting-desc">
                The percentage cut the Syndicate takes from all successful auction settlements.
            </div>
            <input type="number" name="platform_fee" class="input-field" value="<?php echo htmlspecialchars($settings['platform_fee'] ?? 10); ?>" min="0" max="100" step="0.1" required>
        </div>

        <div class="setting-group">
            <div class="setting-header">
                <div class="setting-title">Master Passcode</div>
            </div>
            <div class="setting-desc">
                Change the password required to access the Syndicate Dashboard.
            </div>
            <input type="text" name="admin_passcode" class="input-field" value="<?php echo htmlspecialchars($settings['admin_passcode']); ?>" required>
        </div>

        <button type="submit" class="btn-save">Save Configurations</button>
    </form>
</div>

<?php require_once 'footer.php'; ?>
