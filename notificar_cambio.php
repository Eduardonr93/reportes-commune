<?php
// notificar_cambio.php - Notifica cambios sin enlaces
header('Content-Type: application/json');

$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$reporte_id = (int)($data['reporte_id'] ?? 0);
$tipo = $data['tipo'] ?? '';
$datos = $data['datos'] ?? [];
$usuario = $data['usuario'] ?? 'Sistema';

if (!$reporte_id || !$tipo) {
    echo json_encode(['ok' => false, 'error' => 'Faltan datos']);
    exit;
}

$reporte = $conn->query("SELECT * FROM reportes WHERE id = $reporte_id")->fetch_assoc();
if (!$reporte) {
    echo json_encode(['ok' => false, 'error' => 'Reporte no encontrado']);
    exit;
}

$mensaje = '';

switch ($tipo) {
    case 'asignacion':
        $tecnico = $datos['tecnico'] ?? '';
        $estado = $datos['estado'] ?? $reporte['estatus'];
        $mensaje = "👷 *REPORTE #{$reporte_id}*\n";
        $mensaje .= "🔧 Técnico asignado: {$tecnico}\n";
        $mensaje .= "📊 Estado: {$estado}\n";
        $mensaje .= "👤 Por: {$usuario}";
        break;
        
    case 'estado':
        $nuevo_estado = $datos['estado'] ?? '';
        $mensaje = "📊 *REPORTE #{$reporte_id}*\n";
        $mensaje .= "📌 Estado actualizado: {$nuevo_estado}\n";
        $mensaje .= "👤 Por: {$usuario}";
        break;
        
    case 'cierre':
        $tecnico = $datos['tecnico'] ?? '';
        $observaciones = substr($datos['observaciones'] ?? '', 0, 100);
        $mensaje = "✅ *REPORTE #{$reporte_id} TERMINADO*\n";
        $mensaje .= "👷 Técnico: {$tecnico}\n";
        $mensaje .= "📝 Observaciones: {$observaciones}";
        break;
        
    default:
        echo json_encode(['ok' => false, 'error' => 'Tipo no válido']);
        exit;
}

$stmt = $conn->prepare("INSERT INTO notificaciones_pendientes (mensaje, reporte_id, tipo) VALUES (?, ?, ?)");
$stmt->bind_param("sis", $mensaje, $reporte_id, $tipo);
$stmt->execute();

echo json_encode(['ok' => true, 'message' => 'Notificación encolada']);
$conn->close();
?>