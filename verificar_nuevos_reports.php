<?php
// verificar_nuevos_reports.php - API para notificaciones sin recargar
header('Content-Type: application/json');

$host = "localhost"; $user = "commune_reportes";
$pass = "ComuneReportes2026"; $db = "commune_reportes";
$conn = new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");

if($conn->connect_error){
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$result = $conn->query("SELECT COUNT(*) as total FROM reportes");
$total = $result->fetch_assoc()['total'];

echo json_encode(['total' => (int)$total]);
$conn->close();
?>