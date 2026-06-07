<?php
// enviar_notificacion_grupo.php - Endpoint para que el bot lea notificaciones pendientes
header('Content-Type: application/json');

$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB error']);
    exit;
}

// Obtener notificaciones no enviadas
$result = $conn->query("SELECT id, mensaje FROM notificaciones_pendientes WHERE enviado = 0 ORDER BY id ASC LIMIT 50");
$notificaciones = [];
while ($row = $result->fetch_assoc()) {
    $notificaciones[] = $row;
}

echo json_encode($notificaciones);
$conn->close();
?>