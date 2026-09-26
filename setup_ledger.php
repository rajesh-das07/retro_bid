<?php
require_once 'db_connect.php';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS transaction_ledger (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        auction_id INT DEFAULT NULL,
        type ENUM('deposit', 'withdrawal', 'escrow_hold', 'escrow_release', 'payment', 'fee') NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $conn->exec($sql);
    echo "Ledger table created successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
