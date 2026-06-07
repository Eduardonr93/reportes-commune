<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost","thenetgu_reportes","thenetgu_reportes","thenetgu_reportes");
$conn->set_charset("utf8mb4");

$id = (int)$_POST['id'];
$nuevo_estatus = $conn->real_escape_string($_POST['estatus']);

$conn->query("UPDATE control_materiales SET estatus = '$nuevo_estatus' WHERE id = $id");
echo json_encode(['ok' => true]);
$conn->close();
?>