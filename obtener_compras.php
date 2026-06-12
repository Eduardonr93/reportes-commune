<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost","commune_reportes","ComuneReportes2026","commune_reportes");
$conn->set_charset("utf8mb4");

$estatus = $_GET['estatus'] ?? 'todos';
$sql = "SELECT * FROM control_materiales";
if ($estatus !== 'todos') {
    $sql .= " WHERE estatus = '" . $conn->real_escape_string($estatus) . "'";
}
$sql .= " ORDER BY fecha_solicitud DESC LIMIT 100";

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data);
$conn->close();
?>