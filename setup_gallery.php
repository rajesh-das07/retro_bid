<?php
require_once 'db_connect.php';

try {
    $sql1 = "
    CREATE TABLE IF NOT EXISTS submission_images (
        image_id INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT NOT NULL,
        image_url VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $sql2 = "
    CREATE TABLE IF NOT EXISTS auction_images (
        image_id INT AUTO_INCREMENT PRIMARY KEY,
        auction_id INT NOT NULL,
        image_url VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $conn->exec($sql1);
    $conn->exec($sql2);
    
    echo "Gallery tables created successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
