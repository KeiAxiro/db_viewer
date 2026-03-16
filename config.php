<?php
// config.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$pdo = null;
$dbname = '';
$driver = '';
$conn_error = null;

if (isset($_SESSION['db_connection'])) {
    $conn = $_SESSION['db_connection'];
    $driver = $conn['driver'];
    $dbname = $conn['dbname'];

    try {
        if ($driver === 'mysql') {
            $pdo = new PDO("mysql:host={$conn['host']};dbname={$conn['dbname']};charset=utf8mb4", $conn['user'], $conn['pass']);
        } else if ($driver === 'sqlite') {
            $pdo = new PDO("sqlite:" . $conn['file']);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        // Jika koneksi gagal (misal server MySQL mati), hapus sesi
        session_destroy();
        $pdo = null;
        $conn_error = $e->getMessage();
    }
}
?>