<?php
// obtener_tecnicos.php - Devuelve lista de técnicos
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = "localhost"; $user = "commune_reportes";
$pass = "ComuneReportes2026"; $db = "commune_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$result = $conn->query("SELECT id, nombre, numero_whatsapp, especialidad FROM tecnicos WHERE activo = 1 ORDER BY nombre");
$tecnicos = [];
while ($row = $result->fetch_assoc()) {
    $tecnicos[] = $row;
}

echo json_encode($tecnicos);
$conn->close();
?>