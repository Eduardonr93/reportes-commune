<?php
$host="localhost"; $user="commune_reportes"; $pass="ComuneReportes2026"; $db="commune_reportes";
$conn=new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error) die("Error");

$remitente  = mb_convert_encoding($_POST['remitente'] ?? 'Desconocido','UTF-8','UTF-8');
$mensaje    = mb_convert_encoding($_POST['mensaje']   ?? '','UTF-8','UTF-8');
$motivo     = $conn->real_escape_string($_POST['motivo'] ?? '');
$media      = isset($_POST['tiene_media']) ? (int)$_POST['tiene_media'] : 0;
$fecha      = $conn->real_escape_string($_POST['fecha'] ?? date('Y-m-d H:i:s'));

if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$fecha))
    $fecha = date('Y-m-d H:i:s');

$stmt=$conn->prepare("INSERT INTO reportes_descartados (fecha,remitente,mensaje,motivo,tiene_media) VALUES (?,?,?,?,?)");
$stmt->bind_param("ssssi",$fecha,$remitente,$mensaje,$motivo,$media);
echo $stmt->execute() ? "OK" : "Error: ".$stmt->error;
$conn->close();
?>
