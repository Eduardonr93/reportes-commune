<?php
// marcar_notificacion_enviada.php - Marca notificación como enviada
header('Content-Type: application/json');

$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['ok' => false]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);

if ($id) {
    $conn->query("UPDATE notificaciones_pendientes SET enviado = 1 WHERE id = $id");
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false]);
}
$conn->close();
?>