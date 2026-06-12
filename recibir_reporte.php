<?php
// recibir_reporte.php - Recibe reportes del bot y guarda en BD
$host = "localhost"; 
$user = "commune_reportes";
$pass = "ComuneReportes2026"; 
$db = "commune_reportes";
$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
    http_response_code(500);
    die("Error: " . $conn->connect_error);
}

// ─────────────────────────────────────────────────────────────
// 1. RECIBIR Y LIMPIAR DATOS
// ─────────────────────────────────────────────────────────────
$texto   = isset($_POST['texto'])        ? $_POST['texto']        : '';
$usuario = isset($_POST['usuario'])      ? $_POST['usuario']      : 'Desconocido';
$fecha   = isset($_POST['fecha'])        ? $_POST['fecha']        : date('Y-m-d H:i:s');
$imagen  = isset($_POST['imagen_base64'])? $_POST['imagen_base64']: '';

$texto   = mb_convert_encoding($texto,   'UTF-8', 'UTF-8');
$usuario = mb_convert_encoding($usuario, 'UTF-8', 'UTF-8');

if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d H:i:s');
}

if (empty($texto) && empty($imagen)) {
    echo "VACIO";
    $conn->close();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. FUNCIONES DE DETECCIÓN AUTOMÁTICA
// ─────────────────────────────────────────────────────────────
function detectarCategoriaPorTexto($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/cámara|cctv|dvr|nvr|grabador|visión|no se ve|térmica/', $t)) return "CCTV";
    if (preg_match('/barrera|acceso|biométrico|tag|lector|pluma|torniquete/', $t)) return "Accesos";
    if (preg_match('/cerco|concertina|perímetro|eléctrico|alambre|pica|poste/', $t)) return "Perímetro";
    if (preg_match('/alarma|sensor|sirena|detector|movimiento|intrusión/', $t)) return "Alarma";
    if (preg_match('/wifi|red|router|switch|fibra|internet|conexión/', $t)) return "Redes";
    return '';
}

function detectarUrgenciaPorTexto($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/urgente|urge|inmediato|emergencia|crítico|critico|ya|rápido/', $t)) return "Urgente";
    return "Normal";
}

function detectarEquipoPorTexto($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/cámara|camara|ptz|domo|bullet/', $t)) return "cámara";
    if (preg_match('/barrera|pluma/', $t)) return "barrera";
    if (preg_match('/cerco|concertina|eléctrico/', $t)) return "cerco eléctrico";
    if (preg_match('/lector|tag/', $t)) return "lectora de tags";
    if (preg_match('/botón|botonera|pulsador/', $t)) return "botonera";
    return '';
}

// ─────────────────────────────────────────────────────────────
// 3. EXTRAER CAMPOS DE LA PLANTILLA
// ─────────────────────────────────────────────────────────────
$residencial = '';
$categoria   = '';
$equipo      = '';
$ubicacion   = '';
$tipo        = '';
$urgencia    = '';
$descripcion = $texto;
$reporta     = $usuario;

// Extraer Residencial — soporta formato oficial (📍) y formato libre (RESIDENCIAL:)
if (preg_match('/📍\s*Residencial:\s*(.+)/iu', $texto, $match)) {
    $residencial = trim($match[1]);
} elseif (preg_match('/(?:^|\n)\s*RESIDENCIAL\s*:\s*(.+)/iu', $texto, $match)) {
    $residencial = trim($match[1]);
    $formato_incorrecto = true;
}

// Extraer Categoría — soporta formato oficial y libre
// También detecta cuando el encargado pone el nivel de urgencia en categoría
if (preg_match('/📂\s*Categor[ií]a:\s*(.+)/iu', $texto, $match)) {
    $categoria = trim($match[1]);
} elseif (preg_match('/(?:^|\n)\s*CATEGOR[IÍ]A\s*:\s*(.+)/iu', $texto, $match)) {
    $categoria = trim($match[1]);
    $formato_incorrecto = true;
}

// Detectar si pusieron urgencia en categoría y corregir
$cat_lower = mb_strtolower($categoria ?? '', 'UTF-8');
$es_urgencia_en_cat = preg_match('/\b(1|2|3|uno|dos|tres|urgente|prioridad|critico|crítico|moderado|leve)\b/i', $categoria ?? '');
if ($es_urgencia_en_cat) {
    // Extraer nivel de urgencia del campo categoría
    if (preg_match('/\b(1|uno|urgente|critico|crítico)\b/i', $categoria ?? '')) {
        if (empty($urgencia)) $urgencia = 'Nivel 1 - Crítico';
    } elseif (preg_match('/\b(2|dos|moderado|prioridad)\b/i', $categoria ?? '')) {
        if (empty($urgencia)) $urgencia = 'Nivel 2 - Moderado';
    } elseif (preg_match('/\b(3|tres|leve)\b/i', $categoria ?? '')) {
        if (empty($urgencia)) $urgencia = 'Nivel 3 - Leve';
    }
    $categoria = ''; // Limpiar para que se detecte automáticamente
    $formato_incorrecto = true;
}

// Si categoría es GENERAL, limpiar para detectar automáticamente
if (mb_strtolower($categoria ?? '', 'UTF-8') === 'general') {
    $categoria = '';
    $formato_incorrecto = true;
}

// Extraer Equipo — soporta formato oficial y libre
if (preg_match('/🔩\s*Equipo:\s*(.+)/iu', $texto, $match)) {
    $equipo = trim($match[1]);
} elseif (preg_match('/(?:^|\n)\s*EQUIPO\s*:\s*(.+)/iu', $texto, $match)) {
    $equipo = trim($match[1]);
    $formato_incorrecto = true;
}

// Extraer Ubicación
if (preg_match('/🗺️\s*Ubicación:\s*(.+)/i', $texto, $match)) {
    $ubicacion = trim($match[1]);
}

// Extraer Tipo
if (preg_match('/📋\s*Tipo:\s*(.+)/i', $texto, $match)) {
    $tipo = trim($match[1]);
}

// Extraer Urgencia — soporta formato oficial y libre
if (preg_match('/🚨\s*Urgencia:\s*(.+)/iu', $texto, $match)) {
    $urgencia = trim($match[1]);
} elseif (preg_match('/(?:^|\n)\s*(?:NIVEL|URGENCIA)\s*[:\-]?\s*(.+)/iu', $texto, $match)) {
    $urgencia = trim($match[1]);
    $formato_incorrecto = true;
}

// Extraer Reporta
if (preg_match('/👤\s*Reporta:\s*(.+)/i', $texto, $match)) {
    $reporta = trim($match[1]);
}

// Extraer Descripción
if (preg_match('/📝\s*Descripción:\s*(.+)/is', $texto, $match)) {
    $descripcion = trim($match[1]);
}

// ─────────────────────────────────────────────────────────────
// 4. VALIDACIÓN Y CORRECCIÓN DE CAMPOS
// ─────────────────────────────────────────────────────────────
$errores = [];
$formato_incorrecto = false;

// Verificar si se usó formato antiguo (sin emojis)
if (!preg_match('/🔧\s*REPORTE|📍\s*Residencial|📂\s*Categoría|🔩\s*Equipo/', $texto)) {
    $formato_incorrecto = true;
}

// Validar y corregir equipo
if (empty($equipo)) {
    $equipo_detectado = detectarEquipoPorTexto($texto);
    if ($equipo_detectado) {
        $equipo = $equipo_detectado;
        $formato_incorrecto = true;
    } else {
        $errores[] = 'equipo';
    }
}

// Validar y corregir categoría
if (empty($categoria)) {
    $cat_detectada = detectarCategoriaPorTexto($texto);
    if ($cat_detectada) {
        $categoria = $cat_detectada;
        $formato_incorrecto = true;
    } else {
        $errores[] = 'categoria';
    }
}

// Validar urgencia (si no viene, detectar)
if (empty($urgencia)) {
    $urgencia = detectarUrgenciaPorTexto($texto);
    if ($urgencia === "Urgente") $formato_incorrecto = true;
}

// Si faltan campos críticos, rechazar
if (in_array('equipo', $errores)) {
    echo "SIN_EQUIPO";
    $conn->close();
    exit;
}

if (in_array('categoria', $errores)) {
    echo "SIN_CATEGORIA";
    $conn->close();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 5. CLASIFICACIÓN POR PALABRAS CLAVE (si aún no hay categoría)
// ─────────────────────────────────────────────────────────────
if (empty($categoria)) {
    $categoria = detectarCategoriaPorTexto($texto);
}
if (empty($categoria)) {
    $categoria = "General";
}

// Prioridad final
if (empty($prioridad)) {
    $prioridad = detectarUrgenciaPorTexto($texto);
}

// ─────────────────────────────────────────────────────────────
// 6. GUARDAR IMAGEN
// ─────────────────────────────────────────────────────────────
$ruta_imagen = "";
if (!empty($imagen)) {
    $datos = base64_decode($imagen);
    if ($datos !== false) {
        $nombre = "in_" . time() . "_" . mt_rand(1000, 9999) . ".jpg";
        $ruta = __DIR__ . "/uploads/" . $nombre;
        if (!is_dir(__DIR__ . "/uploads")) mkdir(__DIR__ . "/uploads", 0755, true);
        file_put_contents($ruta, $datos);
        $ruta_imagen = "uploads/" . $nombre;
    }
}

// ─────────────────────────────────────────────────────────────
// 7. PREVENIR DUPLICADOS
// ─────────────────────────────────────────────────────────────
$texto_hash = md5(substr(trim($texto), 0, 200));
$fecha_limite = date('Y-m-d H:i:s', strtotime('-15 minutes'));

$check_duplicate = $conn->query("
    SELECT id FROM reportes 
    WHERE remitente = '" . $conn->real_escape_string($usuario) . "'
    AND MD5(LEFT(descripcion, 200)) = '$texto_hash'
    AND fecha > '$fecha_limite'
    LIMIT 1
");

if ($check_duplicate && $check_duplicate->num_rows > 0) {
    echo "DUPLICADO";
    $conn->close();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 8. INSERTAR EN BD
// ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO reportes 
    (descripcion, remitente, fecha, foto_url, estatus, categoria, prioridad, 
     residencial, equipo, ubicacion_especifica, nombre_tecnico) 
    VALUES (?, ?, ?, ?, 'Pendiente', ?, ?, ?, ?, ?, '')
");
$stmt->bind_param("ssssssssss", $descripcion, $reporta, $fecha, $ruta_imagen, 
                   $categoria, $prioridad, $residencial, $equipo, $ubicacion);

$exito = $stmt->execute();
$id_insertado = $conn->insert_id;
$error = $stmt->error;
$stmt->close();

// ─────────────────────────────────────────────────────────────
// 9. NOTIFICAR Y RESPONDER
// ─────────────────────────────────────────────────────────────
if ($exito && $id_insertado) {
    $mensaje = "📋 *NUEVO REPORTE #{$id_insertado}*\n";
    $mensaje .= "📂 Categoría: {$categoria}\n";
    $mensaje .= "🔩 Equipo: {$equipo}\n";
    $mensaje .= "📍 Residencial: " . ($residencial ?: 'No especificado') . "\n";
    $mensaje .= "🚨 Prioridad: {$prioridad}\n";
    $mensaje .= "👤 Reportado por: {$reporta}";
    
    $stmt_notif = $conn->prepare("INSERT INTO notificaciones_pendientes (mensaje, reporte_id, tipo) VALUES (?, ?, 'nuevo')");
    $stmt_notif->bind_param("si", $mensaje, $id_insertado);
    $stmt_notif->execute();
    $stmt_notif->close();
    
    if ($formato_incorrecto) {
        echo "OK_CORREGIDO:{$id_insertado}";
    } else {
        echo "OK:{$id_insertado}";
    }
} else {
    http_response_code(500);
    echo "Error: " . $error;
}

$conn->close();
?>
