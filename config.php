<?php
// config.php - Configuración centralizada
define('DB_HOST', 'localhost');
define('DB_USER', 'commune_reportes');
define('DB_PASS', 'ComuneReportes2026');
define('DB_NAME', 'commune_reportes');

function getDB() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            throw new Exception("Error de conexión: " . $conn->connect_error);
        }
        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (Exception $e) {
        die("Error en la base de datos: " . $e->getMessage());
    }
}
?>
