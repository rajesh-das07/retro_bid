<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dismiss_feedback') {
    if (isset($_POST['target_id'])) {
        $target_id = (int)$_POST['target_id'];
        $conn->prepare("DELETE FROM feedback WHERE id = :id")->execute([':id' => $target_id]);
    }
    header("Location: feedback.php");
    exit();
}

$stmt_fb = $conn->query("SELECT * FROM feedback ORDER BY created_at DESC");
$feedbacks = $stmt_fb->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="section-divider">Platform Feedback</div>
<?php if (empty($feedbacks)): ?>
    <div class="empty">No feedback records found.</div>
<?php else: ?>
    <?php foreach ($feedbacks as $fb): ?>
        <div class="message-card">
            <!-- Convert the integer rating into visual stars -->
            <div class="msg-header">
                <div class="msg-title" style="color: #C5A059; letter-spacing: 0.2em;">
                    <?php echo str_repeat('★', $fb['rating']) . str_repeat('☆', 5 - $fb['rating']); ?>
                </div>
                <div class="msg-meta">
                    By: <span><?php echo htmlspecialchars($fb['user_name']); ?></span> | 
                    Date: <?php echo date('M d, Y H:i', strtotime($fb['created_at'])); ?>
                </div>
            </div>
            <div class="msg-body">
                <?php echo nl2br(htmlspecialchars($fb['message'])); ?>
            </div>
            <form method="POST" action="feedback.php">
                <input type="hidden" name="target_id" value="<?php echo $fb['id']; ?>">
                <button type="submit" name="action" value="dismiss_feedback" class="btn-dismiss">Dismiss Record</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
