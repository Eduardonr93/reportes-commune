<?php
// obtener_reporte_estadistico.php - Reporte estadístico con agrupación
header('Content-Type: application/json');

$host = "localhost"; $user = "commune_reportes";
$pass = "ComuneReportes2026"; $db = "commune_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB error']);
    exit;
}

$inicio = $conn->real_escape_string($_GET['inicio'] ?? date('Y-m-d', strtotime('-30 days')));
$fin = $conn->real_escape_string($_GET['fin'] ?? date('Y-m-d'));
$residencial = $conn->real_escape_string($_GET['residencial'] ?? '');
$tipo_reporte = $conn->real_escape_string($_GET['tipo_reporte'] ?? '');
$agrupar = $conn->real_escape_string($_GET['agrupar'] ?? 'mes');

$where = "WHERE DATE(fecha) BETWEEN '$inicio' AND '$fin'";
if ($residencial) $where .= " AND residencial = '$residencial'";
if ($tipo_reporte) $where .= " AND tipo_reporte = '$tipo_reporte'";

// Estadísticas generales
$result = $conn->query("SELECT 
    SUM(CASE WHEN estatus = 'Pendiente' THEN 1 ELSE 0 END) as pendiente,
    SUM(CASE WHEN estatus = 'En Proceso' THEN 1 ELSE 0 END) as proceso,
    SUM(CASE WHEN estatus = 'Terminado' THEN 1 ELSE 0 END) as terminado,
    SUM(CASE WHEN prioridad = 'Urgente' THEN 1 ELSE 0 END) as urgente,
    COUNT(*) as total
FROM reportes $where");
$stats = $result->fetch_assoc();

$response = [
    'inicio' => date('d/m/Y', strtotime($inicio)),
    'fin' => date('d/m/Y', strtotime($fin)),
    'pendiente' => (int)$stats['pendiente'],
    'proceso' => (int)$stats['proceso'],
    'terminado' => (int)$stats['terminado'],
    'urgente' => (int)$stats['urgente'],
    'total' => (int)$stats['total'],
    'residencial' => $residencial ?: ''
];

// Agrupación por mes
if ($agrupar === 'mes') {
    $result = $conn->query("SELECT 
        DATE_FORMAT(fecha, '%b %Y') as mes,
        COUNT(*) as total 
    FROM reportes $where 
    GROUP BY YEAR(fecha), MONTH(fecha) 
    ORDER BY YEAR(fecha) DESC, MONTH(fecha) DESC");
    $response['por_mes'] = [];
    while ($row = $result->fetch_assoc()) {
        $response['por_mes'][] = $row;
    }
}

// Agrupación por tipo de reporte
if ($agrupar === 'tipo') {
    $result = $conn->query("SELECT 
        tipo_reporte,
        COUNT(*) as total 
    FROM reportes $where 
    GROUP BY tipo_reporte 
    ORDER BY total DESC");
    $response['por_tipo_reporte'] = [];
    while ($row = $result->fetch_assoc()) {
        $response['por_tipo_reporte'][] = $row;
    }
}

// Agrupación por nivel de urgencia
if ($agrupar === 'nivel') {
    $result = $conn->query("SELECT 
        nivel_urgencia as nivel,
        COUNT(*) as total 
    FROM reportes $where 
    GROUP BY nivel_urgencia 
    ORDER BY FIELD(nivel_urgencia, 'Nivel 1', 'Nivel 2', 'Nivel 3')");
    $response['por_nivel'] = [];
    while ($row = $result->fetch_assoc()) {
        if ($row['nivel']) $response['por_nivel'][] = $row;
    }
}

// Agrupación por residencial
if ($agrupar === 'residencial') {
    $result = $conn->query("SELECT 
        residencial,
        COUNT(*) as total 
    FROM reportes $where AND residencial != ''
    GROUP BY residencial 
    ORDER BY total DESC
    LIMIT 10");
    $response['por_residencial'] = [];
    while ($row = $result->fetch_assoc()) {
        $response['por_residencial'][] = $row;
    }
}

echo json_encode($response);
$conn->close();
?>