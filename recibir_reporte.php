<?php
// recibir_reporte.php - Recibe reportes del bot y guarda en BD
$host = "localhost";
$user = "thenetgu_reportes";
$pass = "thenetgu_reportes";
$db   = "thenetgu_reportes";

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

// Limpiar encoding UTF-8
$texto   = mb_convert_encoding($texto,   'UTF-8', 'UTF-8');
$usuario = mb_convert_encoding($usuario, 'UTF-8', 'UTF-8');

// Validar fecha
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d H:i:s');
}

if (empty($texto) && empty($imagen)) {
    echo "VACIO";
    $conn->close();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. PREVENIR DUPLICADOS
// ─────────────────────────────────────────────────────────────
$texto_busqueda = '%' . $conn->real_escape_string(substr($texto, 0, 100)) . '%';
$texto_hash = md5(substr(trim($texto), 0, 200));
$fecha_limite = date('Y-m-d H:i:s', strtotime('-15 minutes'));

$check_duplicate = $conn->query("
    SELECT id FROM reportes 
    WHERE remitente = '" . $conn->real_escape_string($usuario) . "'
    AND (descripcion LIKE '$texto_busqueda' OR MD5(LEFT(descripcion, 200)) = '$texto_hash')
    AND fecha > '$fecha_limite'
    LIMIT 1
");

if ($check_duplicate && $check_duplicate->num_rows > 0) {
    echo "DUPLICADO";
    $conn->close();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. FUNCIONES DE CLASIFICACIÓN MEJORADAS
// ─────────────────────────────────────────────────────────────

// Detectar categoría por palabras clave
function detectarCategoria($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/c[aá]mara|cctv|dvr|nvr|hikvision|dahua|grabador|video|seguridad perimetral|t[eé]rmica|visi[oó]n|no se ve|pixelada|borrosa/', $t)) {
        return 'CCTV';
    } elseif (preg_match('/cerco|concertina|per[ií]metro|hilo|malla|el[eé]ctrico|energizado|electrificado|alambre|pica|poste|tensi[oó]n/', $t)) {
        return 'Perímetro';
    } elseif (preg_match('/alarma|sensor|panel|sirena|bocina|detector|movimiento|intrusi[oó]n|disparo|activ[oó]|suena|pitido/', $t)) {
        return 'Alarma';
    } elseif (preg_match('/barrera|acceso|biom[eé]tric|zkteco|tarjeta|tag|lector|pluma|torniquete|gira|bloquea|apertura|cierre|barras/', $t)) {
        return 'Accesos';
    } elseif (preg_match('/wifi|red|router|switch|starlink|fibra|internet|conexi[oó]n|ca[ i]da|se[ñn]al|ethernet|malla/', $t)) {
        return 'Redes';
    }
    return 'General';
}

// Detectar residencial (CORREGIDO - evita confundir "Via Cumbres" con "RIO")
function detectarResidencial($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    
    // Lista de residenciales ordenados por longitud (priorizar los más largos)
    $residenciales = [
        'Via Cumbres' => ['via cumbres', 'vía cumbres'],
        'Monte Athos' => ['monte athos', 'monteathos'],
        'Arbolada'    => ['arbolada'],
        'Palmaris'    => ['palmaris'],
        'Cumbres'     => ['cumbres'],
        'Aqua'        => ['aqua'],
        'Altai'       => ['altai'],
        'Kyra'        => ['kyra'],
        'RIO'         => ['rio', 'río']
    ];
    
    foreach ($residenciales as $nombre => $patrones) {
        foreach ($patrones as $patron) {
            if (strpos($t, $patron) !== false) {
                // Evitar falsos positivos: "via cumbres" no debe detectar "rio"
                if ($nombre === 'RIO' && (strpos($t, 'via cumbres') !== false || strpos($t, 'vía cumbres') !== false)) {
                    continue;
                }
                return $nombre;
            }
        }
    }
    
    return '';
}

// Detectar tipo de reporte
function detectarTipoReporte($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/preventivo|revisi[oó]n\s*programada|mantenimiento\s*preventivo|inspecci[oó]n/', $t)) {
        return 'Preventivo';
    }
    if (preg_match('/mantenimiento|reparaci[oó]n|ajuste|calibraci[oó]n|servicio\s*t[eé]cnico/', $t)) {
        return 'Mantenimiento';
    }
    return 'Incidencia';
}

// Detectar nivel de urgencia (Nivel 1: Crítico, Nivel 2: Medio, Nivel 3: Bajo)
function detectarNivelUrgencia($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    
    // Nivel 1: Crítico - afecta o imposibilita brindar seguridad
    if (preg_match('/nivel\s*1|nivel\s*uno|cr[ií]tico|emergencia|inmediato|urgente\s*maximo|sin\s*seguridad|imposibilita|ca[ií]da\s*total|totalmente\s*ca[ií]do|no\s*funciona\s*nada|completamente\s*muerto|inhabilitado|fuera\s*de\s*servicio|no\s*hay\s*seguridad/i', $t)) {
        return 'Nivel 1';
    }
    
    // Nivel 2: Medio - aun se puede brindar seguridad
    if (preg_match('/nivel\s*2|nivel\s*dos|medio|parcial|intermitente|a\s*veces|falla\s*parcial|se\s*demora|tarda|retraso|lento|calidad\s*baja|borroso|pixelado/i', $t)) {
        return 'Nivel 2';
    }
    
    // Nivel 3: Bajo - afectación mínima, hay otras opciones
    if (preg_match('/nivel\s*3|nivel\s*tres|bajo|menor|leve|est[eé]tico|cosm[eé]tico|no\s*urgente|programable|puede\s*esperar|sin\s*afectaci[oó]n|menor\s*importancia/i', $t)) {
        return 'Nivel 3';
    }
    
    // Detección automática por palabras clave
    $palabras_nivel1 = ['urge', 'urgente', 'crítico', 'critico', 'emergencia', 'inmediato', 'ya', 'rápido'];
    $palabras_nivel2 = ['tarda', 'demora', 'lento', 'intermitente', 'parcial'];
    $palabras_nivel3 = ['leve', 'menor', 'estético', 'cosmético', 'programable'];
    
    foreach ($palabras_nivel1 as $palabra) {
        if (strpos($t, $palabra) !== false) return 'Nivel 1';
    }
    foreach ($palabras_nivel2 as $palabra) {
        if (strpos($t, $palabra) !== false) return 'Nivel 2';
    }
    foreach ($palabras_nivel3 as $palabra) {
        if (strpos($t, $palabra) !== false) return 'Nivel 3';
    }
    
    return null;
}

// Detectar prioridad (legado)
function detectarPrioridad($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    $palabras_urgentes = ['urge', 'urgente', 'inmediato', 'ya', 'pronto', 'rápido', 'emergencia', 'critico', 'crítico', 'grave'];
    foreach ($palabras_urgentes as $palabra) {
        if (strpos($t, $palabra) !== false) return 'Urgente';
    }
    return 'Normal';
}

// ─────────────────────────────────────────────────────────────
// 4. APLICAR CLASIFICACIÓN
// ─────────────────────────────────────────────────────────────
$categoria = detectarCategoria($texto);
$residencial = detectarResidencial($texto);
$tipo_reporte = detectarTipoReporte($texto);
$nivel_urgencia = detectarNivelUrgencia($texto);
$prioridad = detectarPrioridad($texto);

// Si el nivel de urgencia es Nivel 1, la prioridad debe ser Urgente
if ($nivel_urgencia === 'Nivel 1') {
    $prioridad = 'Urgente';
}

// ─────────────────────────────────────────────────────────────
// 5. GUARDAR IMAGEN
// ─────────────────────────────────────────────────────────────
$ruta_imagen = "";
if (!empty($imagen)) {
    $datos = base64_decode($imagen);
    if ($datos !== false) {
        $nombre = "in_" . time() . "_" . mt_rand(1000, 9999) . ".jpg";
        $ruta = __DIR__ . "/uploads/" . $nombre;
        if (!is_dir(__DIR__ . "/uploads")) {
            mkdir(__DIR__ . "/uploads", 0755, true);
        }
        file_put_contents($ruta, $datos);
        $ruta_imagen = "uploads/" . $nombre;
    }
}

// ─────────────────────────────────────────────────────────────
// 6. INSERTAR EN BD
// ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO reportes 
    (descripcion, remitente, fecha, foto_url, estatus, categoria, prioridad, residencial, tipo_reporte, nivel_urgencia) 
    VALUES (?, ?, ?, ?, 'Pendiente', ?, ?, ?, ?, ?)
");
$stmt->bind_param("sssssssss", $texto, $usuario, $fecha, $ruta_imagen, $categoria, $prioridad, $residencial, $tipo_reporte, $nivel_urgencia);

$exito = $stmt->execute();
$id_insertado = $conn->insert_id;
$error = $stmt->error;
$stmt->close();

// ─────────────────────────────────────────────────────────────
// 7. NOTIFICAR NUEVO REPORTE AL GRUPO
// ─────────────────────────────────────────────────────────────
if ($exito && $id_insertado) {
    $mensaje = "📋 *NUEVO REPORTE #{$id_insertado}*\n";
    $mensaje .= "📂 Categoría: {$categoria}\n";
    $mensaje .= "📍 Residencial: " . ($residencial ?: 'No especificado') . "\n";
    $mensaje .= "🏷️ Tipo: {$tipo_reporte}\n";
    if ($nivel_urgencia) $mensaje .= "⚠️ Nivel: {$nivel_urgencia}\n";
    $mensaje .= "👤 Reportado por: {$usuario}";
    
    $stmt_notif = $conn->prepare("INSERT INTO notificaciones_pendientes (mensaje, reporte_id, tipo) VALUES (?, ?, 'nuevo')");
    $stmt_notif->bind_param("si", $mensaje, $id_insertado);
    $stmt_notif->execute();
    $stmt_notif->close();
    
    echo "OK";
} else {
    http_response_code(500);
    echo "Error: " . $error;
}

$conn->close();
?>