<?php
header('Content-Type: application/json');
$host = "localhost"; $user = "commune_reportes";
$pass = "ComuneReportes2026"; $db = "commune_reportes";
$conn = new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error){ echo json_encode(['error'=>'DB error']); exit; }

$desde = isset($_GET['desde']) ? $_GET['desde'] : null;

if($desde && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)){
    $d = $conn->real_escape_string($desde);
    $r = $conn->query("SELECT COUNT(*) as total FROM reportes WHERE fecha > '$d'");
} else {
    $r = $conn->query("SELECT COUNT(*) as total FROM reportes WHERE fecha > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
}

echo json_encode(['total' => (int)$r->fetch_assoc()['total']]);
$conn->close();
?>
