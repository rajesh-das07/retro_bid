<?php
require_once 'header.php';

// Handle Verification Actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (isset($_POST['submission_id'])) {
        $submission_id = (int)$_POST['submission_id'];
        
        if ($_POST['action'] === 'approve') {
            $stmt = $conn->prepare("SELECT * FROM submissions WHERE submission_id = :id");
            $stmt->execute([':id' => $submission_id]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sub) {
                $insert = $conn->prepare("INSERT INTO auctions (seller_id, title, description, image_url, current_high_bid, start_time, end_time) VALUES (:sid, :t, :d, :i, :bid, :st, :et)");
                $insert->execute([
                    ':sid' => $sub['user_id'],
                    ':t' => $sub['title'],
                    ':d' => $sub['description'],
                    ':i' => $sub['image_url'],
                    ':bid' => $sub['starting_price'],
                    ':st' => $sub['start_time'],
                    ':et' => $sub['end_time']
                ]);
                $auction_id = $conn->lastInsertId();
                
                try {
                    $cat_stmt = $conn->prepare("SELECT category_id FROM submission_categories WHERE submission_id = :sid");
                    $cat_stmt->execute([':sid' => $submission_id]);
                    $cats = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($cats)) {
                        $ins_cat = $conn->prepare("INSERT INTO auction_categories (auction_id, category_id) VALUES (:aid, :cid)");
                        foreach($cats as $cid) {
                            $ins_cat->execute([':aid' => $auction_id, ':cid' => $cid]);
                        }
                    }
                    $conn->prepare("DELETE FROM submission_categories WHERE submission_id = :sid")->execute([':sid' => $submission_id]);
                } catch (PDOException $e) {
                    // Ignore if tables don't exist
                }
                
                // Migrate Gallery Images
                try {
                    $img_stmt = $conn->prepare("SELECT image_url FROM submission_images WHERE submission_id = :sid");
                    $img_stmt->execute([':sid' => $submission_id]);
                    $imgs = $img_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($imgs)) {
                        $ins_img = $conn->prepare("INSERT INTO auction_images (auction_id, image_url) VALUES (:aid, :img)");
                        foreach($imgs as $img) {
                            $ins_img->execute([':aid' => $auction_id, ':img' => $img]);
                        }
                    }
                    $conn->prepare("DELETE FROM submission_images WHERE submission_id = :sid")->execute([':sid' => $submission_id]);
                } catch (PDOException $e) {
                    // Ignore if gallery tables don't exist
                }
                
                $conn->prepare("DELETE FROM submissions WHERE submission_id = :id")->execute([':id' => $submission_id]);
            }
        } elseif ($_POST['action'] === 'reject') {
            try {
                $conn->prepare("DELETE FROM submission_categories WHERE submission_id = :id")->execute([':id' => $submission_id]);
            } catch (PDOException $e) {}
            try {
                $conn->prepare("DELETE FROM submission_images WHERE submission_id = :id")->execute([':id' => $submission_id]);
            } catch (PDOException $e) {}
            $conn->prepare("DELETE FROM submissions WHERE submission_id = :id")->execute([':id' => $submission_id]);
        }
    }
    header("Location: approvals.php");
    exit();
}

$stmt = $conn->query("SELECT s.*, u.alias FROM submissions s JOIN users u ON s.user_id = u.user_id ORDER BY s.created_at ASC");
$pending_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sub_cats = [];
try {
    $sc_stmt = $conn->query("SELECT sc.submission_id, c.name FROM submission_categories sc JOIN categories c ON sc.category_id = c.category_id");
    while($row = $sc_stmt->fetch(PDO::FETCH_ASSOC)) {
        $sub_cats[$row['submission_id']][] = $row['name'];
    }
} catch (PDOException $e) {}
?>

<?php if (empty($pending_items)): ?>
    <div class="empty">No artifacts awaiting verification.</div>
<?php else: ?>
    <?php foreach ($pending_items as $item): ?>
        <?php
            $raw_img = $item['image_url'] ?? '';
            $img_src = (strpos($raw_img, 'http://') === 0 || strpos($raw_img, 'https://') === 0 || strpos($raw_img, '/') === 0)
                ? $raw_img 
                : '../' . ltrim($raw_img, './');
        ?>
        <div class="item-card">
            <img src="<?php echo htmlspecialchars($img_src); ?>" class="item-img" alt="Preview" onerror="this.src='../images/placeholder.jpg';">
            <div class="item-details">
                <div class="item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                <div class="item-meta">
                    Submitted by: <span><?php echo htmlspecialchars($item['alias']); ?></span> | 
                    Reserve: <span>$<?php echo number_format($item['starting_price'], 2); ?></span>
                </div>
                <div class="item-meta">
                    Window: <?php echo $item['start_time']; ?> TO <?php echo $item['end_time']; ?>
                </div>
                <?php if (isset($sub_cats[$item['submission_id']])): ?>
                <div class="item-meta">
                    Categories: <span style="color:#C5A059;"><?php echo htmlspecialchars(implode(', ', $sub_cats[$item['submission_id']])); ?></span>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px; padding: 15px; background: var(--bg-primary); border: 1px solid var(--border-subtle); border-left: 3px solid var(--accent-gold); color: var(--text-secondary); font-size: 0.9rem; line-height: 1.5; font-family: 'Courier New', Courier, monospace;">
                    <?php echo nl2br(htmlspecialchars($item['description'])); ?>
                </div>
            </div>
            <div class="actions">
                <form method="POST" action="approvals.php">
                    <input type="hidden" name="submission_id" value="<?php echo $item['submission_id']; ?>">
                    <button type="submit" name="action" value="approve" class="btn-approve">Approve</button>
                </form>
                <form method="POST" action="approvals.php">
                    <input type="hidden" name="submission_id" value="<?php echo $item['submission_id']; ?>">
                    <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
