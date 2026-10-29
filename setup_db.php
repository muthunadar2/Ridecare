<?php
require_once 'config/db.php';

// Ensure error reporting is visible for setup
ini_set('display_errors', 1);
error_reporting(E_ALL);

$queries = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    "CREATE TABLE IF NOT EXISTS bikes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        model VARCHAR(100) NOT NULL DEFAULT '',
        reg_no VARCHAR(30) NOT NULL DEFAULT '',
        odometer INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bike_user (user_id),
        CONSTRAINT fk_bike_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB",

    "CREATE TABLE IF NOT EXISTS reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bike_id INT NOT NULL,
        task VARCHAR(100) NOT NULL,
        interval_km INT NULL,
        interval_days INT NULL,
        last_done_date DATE NOT NULL,
        last_done_km INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rem_bike (bike_id),
        CONSTRAINT fk_rem_bike FOREIGN KEY (bike_id) REFERENCES bikes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB",

    "CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bike_id INT NOT NULL,
        reminder_id INT NULL,
        task VARCHAR(100) NOT NULL,
        service_date DATE NOT NULL,
        odometer INT NOT NULL DEFAULT 0,
        cost DECIMAL(10,2) NULL,
        notes VARCHAR(500) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_srv_bike (bike_id, service_date),
        CONSTRAINT fk_srv_bike FOREIGN KEY (bike_id) REFERENCES bikes(id) ON DELETE CASCADE,
        CONSTRAINT fk_srv_rem FOREIGN KEY (reminder_id) REFERENCES reminders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB"
];

echo "<h2>Running RideCare Database Setup...</h2>";

foreach ($queries as $index => $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "<p style='color: green;'>Table " . ($index + 1) . " created successfully or already exists.</p>";
    } else {
        echo "<p style='color: red;'>Error creating table: " . $conn->error . "</p>";
    }
}

echo "<h3>Setup complete! You can now access your dashboard.</h3>";
echo "<p><a href='dashboard.php'>Go to Dashboard &rarr;</a></p>";
?>
