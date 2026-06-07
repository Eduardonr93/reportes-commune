<?php
header('Content-Type: application/json');
$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) die(json_encode(['error' => 'DB error']));

$residencial = $conn->real_escape_string($_GET['residencial'] ?? '');
$where_residencial = $residencial ? " AND residencial = '$residencial'" : "";

$inicio = date('Y-m-d', strtotime('monday this week'));
$fin = date('Y-m-d', strtotime('sunday this week'));

$result = $conn->query("SELECT 
    SUM(CASE WHEN estatus = 'Pendiente' THEN 1 ELSE 0 END) as pendiente,
    SUM(CASE WHEN estatus = 'En Proceso' THEN 1 ELSE 0 END) as proceso,
    SUM(CASE WHEN estatus = 'Terminado' THEN 1 ELSE 0 END) as terminado,
    SUM(CASE WHEN prioridad = 'Urgente' THEN 1 ELSE 0 END) as urgente,
    COUNT(*) as total
FROM reportes WHERE DATE(fecha) BETWEEN '$inicio' AND '$fin' $where_residencial");
$stats = $result->fetch_assoc();

$result = $conn->query("SELECT categoria, COUNT(*) as total FROM reportes WHERE DATE(fecha) BETWEEN '$inicio' AND '$fin' $where_residencial AND categoria != 'General' GROUP BY categoria ORDER BY total DESC");
$por_categoria = [];
while ($row = $result->fetch_assoc()) $por_categoria[] = $row;

echo json_encode([
    'inicio' => date('d/m/Y', strtotime($inicio)),
    'fin' => date('d/m/Y', strtotime($fin)),
    'pendiente' => (int)$stats['pendiente'],
    'proceso' => (int)$stats['proceso'],
    'terminado' => (int)$stats['terminado'],
    'urgente' => (int)$stats['urgente'],
    'total' => (int)$stats['total'],
    'por_categoria' => $por_categoria,
    'residencial' => $residencial ?: ''
]);
$conn->close();
?>