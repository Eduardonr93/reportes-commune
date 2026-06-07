<?php
// obtener_reportes_tecnico.php - API para obtener reportes de Martín
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = "localhost"; 
$user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; 
$db = "thenetgu_reportes";

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$tipo = $_GET['tipo'] ?? 'todos';
$residencial = $_GET['residencial'] ?? '';
$limite = (int)($_GET['limite'] ?? 100);

$sql = "SELECT * FROM reportes_tecnico WHERE 1=1";
if ($tipo !== 'todos') {
    $sql .= " AND tipo_trabajo = '" . $conn->real_escape_string($tipo) . "'";
}
if (!empty($residencial)) {
    $sql .= " AND residencial = '" . $conn->real_escape_string($residencial) . "'";
}
$sql .= " ORDER BY fecha_reporte DESC LIMIT $limite";

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
$conn->close();
?>