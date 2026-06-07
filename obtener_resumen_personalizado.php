<?php
// obtener_resumen_personalizado.php - Resumen por fechas personalizadas
header('Content-Type: application/json');

$host = "localhost"; $user = "commune_reportes";
$pass = "ComuneReportes2026"; $db = "commune_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB error']);
    exit;
}

$inicio = $conn->real_escape_string($_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days')));
$fin = $conn->real_escape_string($_GET['fin'] ?? date('Y-m-d'));

// Totales por estado
$result = $conn->query("
    SELECT 
        SUM(CASE WHEN estatus = 'Pendiente' THEN 1 ELSE 0 END) as pendiente,
        SUM(CASE WHEN estatus = 'En Proceso' THEN 1 ELSE 0 END) as proceso,
        SUM(CASE WHEN estatus = 'Terminado' THEN 1 ELSE 0 END) as terminado,
        SUM(CASE WHEN prioridad = 'Urgente' THEN 1 ELSE 0 END) as urgente,
        COUNT(*) as total
    FROM reportes 
    WHERE DATE(fecha) BETWEEN '$inicio' AND '$fin'
");

$stats = $result->fetch_assoc();

// Reportes por residencial
$result = $conn->query("
    SELECT residencial, COUNT(*) as total 
    FROM reportes 
    WHERE DATE(fecha) BETWEEN '$inicio' AND '$fin' AND residencial != ''
    GROUP BY residencial
    ORDER BY total DESC
");

$por_residencial = [];
while ($row = $result->fetch_assoc()) {
    $por_residencial[] = $row;
}

// Reportes por técnico
$result = $conn->query("
    SELECT tecnico_asignado, COUNT(*) as total 
    FROM reportes 
    WHERE DATE(fecha) BETWEEN '$inicio' AND '$fin' AND tecnico_asignado != ''
    GROUP BY tecnico_asignado
    ORDER BY total DESC
");

$por_tecnico = [];
while ($row = $result->fetch_assoc()) {
    $por_tecnico[] = $row;
}

echo json_encode([
    'inicio' => date('d/m/Y', strtotime($inicio)),
    'fin' => date('d/m/Y', strtotime($fin)),
    'pendiente' => (int)$stats['pendiente'],
    'proceso' => (int)$stats['proceso'],
    'terminado' => (int)$stats['terminado'],
    'urgente' => (int)$stats['urgente'],
    'total' => (int)$stats['total'],
    'por_residencial' => $por_residencial,
    'por_tecnico' => $por_tecnico
]);

$conn->close();
?>