<?php
/**
 * Database Connection Test
 */

echo "<!DOCTYPE html>";
echo "<html>";
echo "<head><title>Database Test</title></head>";
echo "<body>";
echo "<h2>Database Connection Test</h2>";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=university_management", "root", "");
    echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;'>";
    echo "Database connection successful!<br>";
    echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn();
    echo "</div>";
} catch (PDOException $e) {
    echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;'>";
    echo "Database connection failed: " . $e->getMessage() . "<br><br>";
    echo "<strong>Possible solutions:</strong><br>";
    echo "1. Make sure XAMPP is running<br>";
    echo "2. Start MySQL service in XAMPP Control Panel<br>";
    echo "3. Create database: <code>CREATE DATABASE university_management;</code><br>";
    echo "4. Check credentials in includes/config.php<br>";
    echo "</div>";
}

echo "</body>";
echo "</html>";
?>
