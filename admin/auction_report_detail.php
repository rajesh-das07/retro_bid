<?php
require_once 'header.php';

if (!isset($_GET['id'])) {
    header("Location: reports.php");
    exit();
}

$auction_id = (int)$_GET['id'];

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
    header("Location: reports.php");
    exit();
}

// Fetch participants and their bids
// Handling missing bid_time by falling back gracefully, though it should exist in a standard bids table
$bids = [];
try {
    $bstmt = $conn->prepare("
        SELECT b.*, u.alias, u.email
        FROM bids b
        JOIN users u ON b.user_id = u.user_id
        WHERE b.auction_id = :aid
        ORDER BY b.bid_time DESC, b.bid_amount DESC
    ");
    $bstmt->execute([':aid' => $auction_id]);
    $bids = $bstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bstmt = $conn->prepare("
        SELECT b.*, u.alias, u.email, NULL as bid_time
        FROM bids b
        JOIN users u ON b.user_id = u.user_id
        WHERE b.auction_id = :aid
        ORDER BY b.bid_amount DESC
    ");
    $bstmt->execute([':aid' => $auction_id]);
    $bids = $bstmt->fetchAll(PDO::FETCH_ASSOC);
}


$unique_participants = [];
foreach ($bids as $bid) {
    if (!isset($unique_participants[$bid['user_id']])) {
        $unique_participants[$bid['user_id']] = $bid['alias'];
    }
}
$participant_count = count($unique_participants);

$now = time();
$end_time = strtotime($auction['end_time']);
$is_ended = $now >= $end_time;
$status = $is_ended ? (count($bids) > 0 ? 'Sold' : 'Unsold') : 'Live/Upcoming';

$raw_img = $auction['image_url'] ?? '';
$img_src = (strpos($raw_img, 'http://') === 0 || strpos($raw_img, 'https://') === 0 || strpos($raw_img, '/') === 0)
    ? $raw_img 
    : '../' . ltrim($raw_img, './');
?>
<style>
.detail-container {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    padding: 30px;
    margin-bottom: 40px;
}
.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-subtle);
    padding-bottom: 20px;
    margin-bottom: 30px;
}
.detail-header h2 {
    color: var(--accent-gold);
    text-transform: uppercase;
    font-size: 1.5rem;
    margin: 0;
}
.detail-meta {
    display: flex;
    gap: 30px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}
.meta-item {
    display: flex;
    flex-direction: column;
}
.meta-label {
    color: var(--text-secondary);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 5px;
}
.meta-value {
    color: var(--text-primary);
    font-size: 1.1rem;
    font-weight: bold;
}
.bids-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.bids-table th, .bids-table td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
}
.bids-table th {
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.1em;
    background: rgba(0,0,0,0.2);
}
.bids-table tr:hover {
    background: var(--accent-gold-subtle);
}
.highest-bidder td {
    color: var(--accent-gold);
    font-weight: bold;
}
.back-link {
    display: inline-block;
    color: var(--text-secondary);
    text-decoration: none;
    text-transform: uppercase;
    font-size: 0.8rem;
    margin-bottom: 20px;
    transition: color 0.3s;
}
.back-link:hover {
    color: var(--accent-gold);
}
</style>

<a href="reports.php" class="back-link">&#8592; Back to Reports</a>

<div class="detail-container">
    <div class="detail-header">
        <h2><?php echo htmlspecialchars($auction['title']); ?></h2>
        <span style="background: <?php echo $status === 'Sold' ? '#146c2e' : ($status === 'Unsold' ? '#990000' : '#C5A059'); ?>; color: #fff; padding: 6px 12px; font-size:0.85rem; border-radius:2px; text-transform:uppercase; font-weight:bold;">
            <?php echo $status; ?>
        </span>
    </div>
    
    <div style="display:flex; gap: 40px; margin-bottom: 40px; flex-wrap: wrap;">
        <div style="flex: 0 0 300px;">
            <img src="<?php echo htmlspecialchars($img_src); ?>" alt="Artifact" style="width: 100%; border: 1px solid var(--border-subtle);" onerror="this.src='../images/placeholder.jpg';">
        </div>
        <div style="flex: 1; min-width: 300px;">
            <div class="detail-meta">
                <div class="meta-item">
                    <span class="meta-label">Final/Current Price</span>
                    <span class="meta-value" style="color: var(--accent-gold); font-size: 1.5rem;">$<?php echo number_format($auction['true_high_bid'], 2); ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Participants</span>
                    <span class="meta-value"><?php echo $participant_count; ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Total Bids</span>
                    <span class="meta-value"><?php echo count($bids); ?></span>
                </div>
            </div>
            <div class="detail-meta">
                <div class="meta-item">
                    <span class="meta-label">Time Started</span>
                    <span class="meta-value"><?php echo date('M d, Y H:i:s', strtotime($auction['start_time'])); ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Time Ended</span>
                    <span class="meta-value"><?php echo date('M d, Y H:i:s', strtotime($auction['end_time'])); ?></span>
                </div>
            </div>
            
            <div class="meta-item" style="margin-top: 20px;">
                <span class="meta-label">Description</span>
                <span style="color: var(--text-secondary); line-height: 1.6;"><?php echo nl2br(htmlspecialchars($auction['description'] ?? '')); ?></span>
            </div>
        </div>
    </div>
    
    <div class="section-divider" style="margin-top: 40px;">Detailed Bid History</div>
    
    <?php if (empty($bids)): ?>
        <p style="color:#888; font-style:italic;">No bids were placed on this auction.</p>
    <?php else: ?>
        <table class="bids-table">
            <thead>
                <tr>
                    <th>Time Placed</th>
                    <th>Bid Amount</th>
                    <th>Participant Alias</th>
                    <th>Participant Email</th>
                </tr>
            </thead>
            <tbody>
                <?php $is_first = true; ?>
                <?php foreach ($bids as $bid): ?>
                    <tr class="<?php echo $is_first ? 'highest-bidder' : ''; ?>">
                        <td>
                            <?php 
                            $bid_time = isset($bid['bid_time']) && !empty($bid['bid_time']) 
                                ? date('M d, Y H:i:s', strtotime($bid['bid_time'])) 
                                : 'Unknown (Not Recorded)';
                            echo $bid_time;
                            ?>
                        </td>
                        <td>$<?php echo number_format($bid['bid_amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($bid['alias']); ?></td>
                        <td><a href="mailto:<?php echo htmlspecialchars($bid['email']); ?>" style="color: var(--text-secondary); text-decoration: none;"><?php echo htmlspecialchars($bid['email']); ?></a></td>
                    </tr>
                    <?php $is_first = false; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
