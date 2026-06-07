<?php
// obtener_comunicados.php - API para obtener comunicados internos
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

// Obtener parámetros
$limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 50;
$marcar_leidos = isset($_GET['marcar_leidos']) ? $_GET['marcar_leidos'] : false;

if ($marcar_leidos) {
    $conn->query("UPDATE comunicados_internos SET leido = 1 WHERE leido = 0");
}

$result = $conn->query("
    SELECT id, remitente, mensaje, fecha, tiene_media, foto_url, leido 
    FROM comunicados_internos 
    ORDER BY fecha DESC 
    LIMIT $limite
");

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'id' => $row['id'],
        'remitente' => $row['remitente'],
        'mensaje' => nl2br(htmlspecialchars($row['mensaje'] ?? '')),
        'fecha' => date('d/m/Y H:i', strtotime($row['fecha'])),
        'fecha_raw' => $row['fecha'],
        'tiene_media' => (bool)$row['tiene_media'],
        'foto_url' => $row['foto_url'],
        'leido' => (bool)$row['leido']
    ];
}

echo json_encode($data);
$conn->close();
?>