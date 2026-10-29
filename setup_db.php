<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/db.php';

echo "<h2>Connected successfully to MySQL!</h2>";

$sql = "CREATE TABLE IF NOT EXISTS bikes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL DEFAULT '',
    reg_no VARCHAR(30) NOT NULL DEFAULT '',
    odometer INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB";

if ($conn->query($sql) === TRUE) {
    echo "<p style='color: green; font-size: 20px;'>SUCCESS: 'bikes' table created or already exists!</p>";
} else {
    echo "<p style='color: red; font-size: 20px;'>MYSQL ERROR: " . $conn->error . "</p>";
}
?>
