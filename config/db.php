<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: 'ridecare_db';
$port = (int) (getenv('DB_PORT') ?: 3306);

// Initialize mysqli and enforce SSL connection required by Aiven
$conn = mysqli_init();
$conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
$conn->ssl_set(NULL, NULL, NULL, NULL, NULL);

@$conn->real_connect($host, $user, $pass, $name, $port, NULL, MYSQLI_CLIENT_SSL);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
