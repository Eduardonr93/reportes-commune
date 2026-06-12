<?php
// obtener_repuestos_usados.php - API para obtener repuestos usados por residencial
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = "localhost"; 
$user = "commune_reportes";
$pass = "ComuneReportes2026"; 
$db = "commune_reportes";

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$residencial = $_GET['residencial'] ?? '';
$limite = (int)($_GET['limite'] ?? 100);

$sql = "SELECT * FROM repuestos_usados WHERE 1=1";
if (!empty($residencial)) {
    $sql .= " AND residencial = '" . $conn->real_escape_string($residencial) . "'";
}
$sql .= " ORDER BY fecha DESC LIMIT $limite";

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
$conn->close();
?>