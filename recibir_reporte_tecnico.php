<?php
// recibir_reporte_tecnico.php - Recibe los reportes de Martín (técnico de campo)
header('Content-Type: application/json');

$host = "localhost"; 
$user = "commune_reportes";
$pass = "ComuneReportes2026"; 
$db = "commune_reportes";

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

// Sanitizar datos
$tipo_trabajo = $conn->real_escape_string($data['tipo_trabajo'] ?? 'mantenimiento');
$residencial = $conn->real_escape_string($data['residencial'] ?? '');
$equipo_afectado = $conn->real_escape_string($data['equipo_afectado'] ?? '');
$descripcion_falla = $conn->real_escape_string($data['descripcion_falla'] ?? '');
$solucion_aplicada = $conn->real_escape_string($data['solucion_aplicada'] ?? '');
$repuestos_usados = $conn->real_escape_string($data['repuestos_usados'] ?? '');
$tiempo_minutos = (int)($data['tiempo_minutos'] ?? 0);
$tecnico = $conn->real_escape_string($data['tecnico'] ?? 'Martín');
$mensaje_original = $conn->real_escape_string($data['mensaje_original'] ?? '');
$fecha = $conn->real_escape_string($data['fecha'] ?? date('Y-m-d H:i:s'));
$imagen_base64 = $data['imagen_base64'] ?? '';

// Guardar imagen si viene
$foto_url = '';
if (!empty($imagen_base64)) {
    $datos = base64_decode($imagen_base64);
    if ($datos !== false) {
        $nombre = "martin_" . time() . "_" . mt_rand(1000, 9999) . ".jpg";
        $ruta = __DIR__ . "/uploads/" . $nombre;
        if (!is_dir(__DIR__ . "/uploads")) mkdir(__DIR__ . "/uploads", 0755, true);
        file_put_contents($ruta, $datos);
        $foto_url = "uploads/" . $nombre;
    }
}

// Insertar en reportes_tecnico
$stmt = $conn->prepare("
    INSERT INTO reportes_tecnico 
    (fecha_reporte, residencial, tipo_trabajo, equipo_afectado, descripcion_falla, solucion_aplicada, repuestos_usados, tiempo_minutos, tecnico, foto_url, mensaje_original) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("sssssssisss", 
    $fecha, $residencial, $tipo_trabajo, $equipo_afectado, 
    $descripcion_falla, $solucion_aplicada, $repuestos_usados, 
    $tiempo_minutos, $tecnico, $foto_url, $mensaje_original
);
$exito = $stmt->execute();
$id_insertado = $conn->insert_id;
$stmt->close();

// Si hay repuestos, registrarlos individualmente en repuestos_usados
if (!empty($repuestos_usados) && $repuestos_usados !== 'ninguno' && $repuestos_usados !== '') {
    // Detectar múltiples repuestos (separados por comas, "y", o puntos)
    $repuestos_array = preg_split('/[,yY]+/', $repuestos_usados);
    foreach ($repuestos_array as $repuesto) {
        $repuesto = trim($repuesto);
        if (!empty($repuesto) && strlen($repuesto) > 2) {
            $stmt2 = $conn->prepare("INSERT INTO repuestos_usados (reporte_id, residencial, repuesto, cantidad, tecnico, fecha) VALUES (?, ?, ?, 1, ?, NOW())");
            $stmt2->bind_param("isss", $id_insertado, $residencial, $repuesto, $tecnico);
            $stmt2->execute();
            $stmt2->close();
        }
    }
}

echo json_encode(['status' => $exito ? 'ok' : 'error', 'id' => $id_insertado]);
$conn->close();
?>