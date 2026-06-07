<?php
// cerrar_reporte_auto.php - Cierra un reporte automáticamente (llamado por el bot)
header('Content-Type: text/plain');

$host = "localhost"; $user = "commune_reportes";
$pass = "ComuneReportes2026"; $db = "commune_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo "ERROR: Conexión fallida";
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$tecnico = $conn->real_escape_string($_POST['tecnico'] ?? 'Yanet');
$observaciones = $conn->real_escape_string($_POST['observaciones'] ?? 'Cerrado automáticamente por Yanet');
$mensaje_original = $conn->real_escape_string($_POST['mensaje_original'] ?? '');
$fecha = $conn->real_escape_string($_POST['fecha'] ?? date('Y-m-d H:i:s'));

if (!$id) {
    echo "ERROR: ID no proporcionado";
    $conn->close();
    exit;
}

// Verificar que el reporte existe y no está terminado
$check = $conn->query("SELECT id, estatus FROM reportes WHERE id = $id");
if (!$check || $check->num_rows === 0) {
    echo "ERROR: Reporte #$id no encontrado";
    $conn->close();
    exit;
}

$reporte = $check->fetch_assoc();
if ($reporte['estatus'] === 'Terminado') {
    echo "OK: Reporte ya estaba terminado";
    $conn->close();
    exit;
}

// Calcular tiempo trabajado (si tenía hora de inicio)
$hora_inicio = $conn->query("SELECT hora_inicio_trabajo FROM reportes WHERE id = $id")->fetch_assoc();
$tiempo_trabajado = 0;
if ($hora_inicio && $hora_inicio['hora_inicio_trabajo']) {
    $start = new DateTime($hora_inicio['hora_inicio_trabajo']);
    $end = new DateTime($fecha);
    $tiempo_trabajado = ($end->getTimestamp() - $start->getTimestamp()) / 60;
}

// Actualizar el reporte
$stmt = $conn->prepare("
    UPDATE reportes 
    SET estatus = 'Terminado', 
        nombre_tecnico = ?, 
        observaciones_cierre = ?, 
        fecha_terminado = ?, 
        tiempo_trabajado = ? 
    WHERE id = ?
");
$stmt->bind_param("sssii", $tecnico, $observaciones, $fecha, $tiempo_trabajado, $id);
$exito = $stmt->execute();
$stmt->close();

if ($exito) {
    // Guardar notificación para el grupo
    $mensaje_notificacion = "✅ *REPORTE #{$id} TERMINADO*\n";
    $mensaje_notificacion .= "👷 Técnico: {$tecnico}\n";
    $mensaje_notificacion .= "📝 Cerrado automáticamente por mensaje de Yanet";
    
    $stmt_notif = $conn->prepare("INSERT INTO notificaciones_pendientes (mensaje, reporte_id, tipo) VALUES (?, ?, 'cierre')");
    $stmt_notif->bind_param("si", $mensaje_notificacion, $id);
    $stmt_notif->execute();
    $stmt_notif->close();
    
    echo "OK: Reporte #$id cerrado correctamente";
} else {
    echo "ERROR: No se pudo actualizar el reporte";
}

$conn->close();
?>