<?php
// recibir_tecnico.php - Recibe JSON del bot técnico
header('Content-Type: application/json');

$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error) die(json_encode(['error' => 'DB error']));

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['clasificacion'])) {
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$clasificacion   = $conn->real_escape_string($data['clasificacion']);
$residencial     = $conn->real_escape_string($data['residencial'] ?? '');
$material_equipo = $conn->real_escape_string($data['material_equipo'] ?? '');
$cantidad        = max(1, (int)($data['cantidad'] ?? 1));
$ubicacion       = $conn->real_escape_string($data['ubicacion_exacta'] ?? '');
$accion          = $conn->real_escape_string($data['accion_realizada'] ?? '');
$msg_original    = $conn->real_escape_string($data['mensaje_original'] ?? '');
$tecnico         = $conn->real_escape_string($data['usuario_remitente'] ?? 'Técnico');
$foto_url        = $conn->real_escape_string($data['foto_url'] ?? '');

if ($clasificacion === 'mantenimiento') {
    $stmt = $conn->prepare("INSERT INTO bitacora_tecnico (residencial, equipo_afectado, accion_realizada, mensaje_original, tecnico, foto_url) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $residencial, $material_equipo, $accion, $msg_original, $tecnico, $foto_url);
    echo json_encode(['ok' => $stmt->execute(), 'tipo' => 'mantenimiento']);
    $stmt->close();

} elseif ($clasificacion === 'requerimiento_compra') {
    $stmt = $conn->prepare("INSERT INTO control_materiales (residencial, material_equipo, cantidad, estatus, mensaje_original, solicitado_por) VALUES (?, ?, ?, 'pendiente_compra', ?, ?)");
    $stmt->bind_param("ssiss", $residencial, $material_equipo, $cantidad, $msg_original, $tecnico);
    echo json_encode(['ok' => $stmt->execute(), 'tipo' => 'requerimiento']);
    $stmt->close();

} elseif ($clasificacion === 'instalacion_equipo') {
    // Buscar si había un requerimiento pendiente
    $busqueda = "%" . trim($material_equipo) . "%";
    $check = $conn->query("SELECT id FROM control_materiales WHERE residencial='$residencial' AND estatus='pendiente_compra' AND material_equipo LIKE '$busqueda' LIMIT 1");
    if ($check && $check->num_rows > 0) {
        $id = $check->fetch_assoc()['id'];
        $conn->query("UPDATE control_materiales SET estatus='instalado', fecha_instalacion=NOW(), ubicacion_exacta='$ubicacion', instalado_por='$tecnico' WHERE id=$id");
        echo json_encode(['ok' => true, 'tipo' => 'instalacion', 'id' => $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO control_materiales (residencial, material_equipo, cantidad, estatus, fecha_instalacion, ubicacion_exacta, mensaje_original, instalado_por) VALUES (?, ?, ?, 'instalado', NOW(), ?, ?, ?)");
        $stmt->bind_param("ssisss", $residencial, $material_equipo, $cantidad, $ubicacion, $msg_original, $tecnico);
        echo json_encode(['ok' => $stmt->execute(), 'tipo' => 'instalacion_directa']);
        $stmt->close();
    }
} else {
    echo json_encode(['ok' => false, 'tipo' => 'ignorado']);
}

$conn->close();
?>