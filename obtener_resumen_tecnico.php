<?php
// obtener_resumen_tecnico.php - Estadísticas del técnico
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

$stats = [];

// Total de reportes por tipo
$result = $conn->query("SELECT tipo_trabajo, COUNT(*) as total FROM reportes_tecnico GROUP BY tipo_trabajo");
while ($row = $result->fetch_assoc()) {
    $stats['por_tipo'][$row['tipo_trabajo']] = (int)$row['total'];
}

// Total de repuestos usados
$result = $conn->query("SELECT COUNT(*) as total FROM repuestos_usados");
$stats['total_repuestos'] = (int)$result->fetch_assoc()['total'];

// Tiempo total trabajado (en horas)
$result = $conn->query("SELECT SUM(tiempo_minutos) as total_minutos FROM reportes_tecnico");
$stats['tiempo_total_horas'] = round(($result->fetch_assoc()['total_minutos'] ?? 0) / 60, 1);

// Reportes por residencial
$result = $conn->query("SELECT residencial, COUNT(*) as total FROM reportes_tecnico WHERE residencial != '' GROUP BY residencial ORDER BY total DESC LIMIT 10");
$stats['por_residencial'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['por_residencial'][] = $row;
}

// Últimos 7 días
$stats['ultimos_7_dias'] = [];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $result = $conn->query("SELECT COUNT(*) as total FROM reportes_tecnico WHERE DATE(fecha_reporte) = '$fecha'");
    $stats['ultimos_7_dias'][] = [
        'fecha' => date('D d/m', strtotime("-$i days")),
        'total' => (int)$result->fetch_assoc()['total']
    ];
}

echo json_encode($stats);
$conn->close();
?>