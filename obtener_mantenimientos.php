<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost","thenetgu_reportes","thenetgu_reportes","thenetgu_reportes");
$conn->set_charset("utf8mb4");

$result = $conn->query("SELECT * FROM bitacora_tecnico ORDER BY fecha DESC LIMIT 100");
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data);
$conn->close();
?>