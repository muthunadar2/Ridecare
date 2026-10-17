<?php
/*
 * EXAMPLE ONLY. Your real config/db.php stays as it is.
 * The app just needs this file to create a mysqli object named $conn.
 */
$conn = new mysqli(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    getenv('DB_NAME') ?: 'ridecare_db',
    (int) (getenv('DB_PORT') ?: 3306)
);
