<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss_inquiry') {
    if (isset($_POST['target_id'])) {
        $target_id = (int)$_POST['target_id'];
        $conn->prepare("DELETE FROM inquiries WHERE id = :id")->execute([':id' => $target_id]);
    }
    header("Location: inquiries.php");
    exit();
}

$stmt_inq = $conn->query("SELECT * FROM inquiries ORDER BY created_at ASC");
$inquiries = $stmt_inq->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="section-divider">Client Inquiries</div>
<?php if (empty($inquiries)): ?>
    <div class="empty">No active concierge inquiries.</div>
<?php else: ?>
    <?php foreach ($inquiries as $inq): ?>
        <div class="message-card">
            <div class="msg-header">
                <div class="msg-title"><?php echo htmlspecialchars($inq['subject']); ?></div>
                <div class="msg-meta">
                    From: <span><?php echo htmlspecialchars($inq['name']); ?></span> (<?php echo htmlspecialchars($inq['email']); ?>) | 
                    Type: <span><?php echo htmlspecialchars($inq['inquiry_type']); ?></span> | 
                    Date: <?php echo date('M d, Y H:i', strtotime($inq['created_at'])); ?>
                </div>
            </div>
            <div class="msg-body">
                <?php echo nl2br(htmlspecialchars($inq['message'])); ?>
            </div>
            <form method="POST" action="inquiries.php">
                <input type="hidden" name="target_id" value="<?php echo $inq['id']; ?>">
                <button type="submit" name="action" value="dismiss_inquiry" class="btn-dismiss">Resolve & Archive</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
