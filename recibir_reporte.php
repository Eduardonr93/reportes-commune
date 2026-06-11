<?php
// recibir_reporte.php - Recibe reportes del bot y guarda en BD

$host = "localhost";
$user = "commune_reportes";
$pass = "ComuneReportes2026";
$db   = "commune_reportes";

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    http_response_code(500);
    die("Error: " . $conn->connect_error);
}

// ─────────────────────────────────────────────────────────────
// 1. RECIBIR Y LIMPIAR DATOS
// ─────────────────────────────────────────────────────────────

$texto   = isset($_POST['texto'])         ? trim($_POST['texto'])         : '';
$usuario = isset($_POST['usuario'])       ? trim($_POST['usuario'])       : 'Desconocido';
$fecha   = isset($_POST['fecha'])         ? trim($_POST['fecha'])         : date('Y-m-d H:i:s');
$imagen  = isset($_POST['imagen_base64']) ? $_POST['imagen_base64']       : '';

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
// 2. PREVENIR DUPLICADOS
// ─────────────────────────────────────────────────────────────

$texto_hash   = md5(substr(trim($texto), 0, 200));
$fecha_limite = date('Y-m-d H:i:s', strtotime('-15 minutes'));
$usuario_esc  = $conn->real_escape_string($usuario);

$check = $conn->query("
    SELECT id FROM reportes
    WHERE remitente = '$usuario_esc'
      AND MD5(LEFT(descripcion, 200)) = '$texto_hash'
      AND fecha > '$fecha_limite'
    LIMIT 1
");

if ($check && $check->num_rows > 0) {
    echo "DUPLICADO";
    $conn->close();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. FUNCIONES DE CLASIFICACIÓN
// ─────────────────────────────────────────────────────────────

function detectarCategoria($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/c[aá]mara|cctv|dvr|nvr|hikvision|dahua|grabador|video|t[eé]rmica|visi[oó]n|no se ve|pixelada|borrosa/', $t))
        return 'CCTV';
    if (preg_match('/cerco|concertina|per[ií]metro|hilo|malla|el[eé]ctrico|energizado|electrificado|alambre|pica|poste|tensi[oó]n/', $t))
        return 'Perímetro';
    if (preg_match('/alarma|sensor|panel|sirena|bocina|detector|movimiento|intrusi[oó]n|disparo|activ[oó]|suena|pitido/', $t))
        return 'Alarma';
    if (preg_match('/barrera|acceso|biom[eé]tric|zkteco|tarjeta|tag|lector|pluma|torniquete|gira|bloquea|apertura|cierre|barras|came|nedap/', $t))
        return 'Accesos';
    if (preg_match('/wifi|red|router|switch|starlink|fibra|internet|conexi[oó]n|ca[ií]da|se[ñn]al|ethernet|malla|ruijie/', $t))
        return 'Redes';
    return 'General';
}

function detectarResidencial($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    $residenciales = [
        'Via Cumbres' => ['via cumbres', 'vía cumbres'],
        'Monte Athos' => ['monte athos', 'monteathos'],
        'Arbolada'    => ['arbolada'],
        'Palmaris'    => ['palmaris'],
        'Cumbres'     => ['cumbres'],
        'Aqua'        => ['aqua'],
        'Altai'       => ['altai'],
        'Kyra'        => ['kyra'],
        'RIO'         => ['rio', 'río'],
    ];
    foreach ($residenciales as $nombre => $patrones) {
        foreach ($patrones as $patron) {
            if (strpos($t, $patron) !== false) {
                if ($nombre === 'RIO' && (strpos($t, 'via cumbres') !== false || strpos($t, 'vía cumbres') !== false))
                    continue;
                return $nombre;
            }
        }
    }
    return '';
}

function detectarTipoReporte($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/preventivo|revisi[oó]n\s*programada|mantenimiento\s*preventivo|inspecci[oó]n/', $t))
        return 'Preventivo';
    if (preg_match('/mantenimiento|reparaci[oó]n|ajuste|calibraci[oó]n|servicio\s*t[eé]cnico/', $t))
        return 'Mantenimiento';
    return 'Incidencia';
}

function detectarNivelUrgencia($texto) {
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/nivel\s*1|nivel\s*uno|cr[ií]tico|emergencia|inmediato|sin\s*seguridad|imposibilita|ca[ií]da\s*total|totalmente\s*ca[ií]do|completamente\s*muerto|inhabilitado|fuera\s*de\s*servicio/i', $t))
        return 'Nivel 1';
    if (preg_match('/nivel\s*2|nivel\s*dos|parcial|intermitente|a\s*veces|falla\s*parcial|se\s*demora|tarda|retraso|lento|calidad\s*baja|borroso|pixelado/i', $t))
        return 'Nivel 2';
    if (preg_match('/nivel\s*3|nivel\s*tres|leve|est[eé]tico|cosm[eé]tico|no\s*urgente|programable|puede\s*esperar|sin\s*afectaci[oó]n/i', $t))
        return 'Nivel 3';
    // Auto-detección por palabras sueltas
    if (preg_match('/\burge\b|\burgente\b|cr[ií]tico|emergencia|\binmediato\b/', $t)) return 'Nivel 1';
    if (preg_match('/\btarda\b|\bdemora\b|\blento\b|\bintermitente\b|\bparcial\b/', $t))  return 'Nivel 2';
    if (preg_match('/\blive\b|\bmenor\b|\bestético\b|\bprogramable\b/', $t))              return 'Nivel 3';
    return null;
}

function detectarPrioridad($texto, $nivel) {
    if ($nivel === 'Nivel 1') return 'Urgente';
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/\burge\b|\burgente\b|inmediato|\bya\b|pronto|r[aá]pido|emergencia|critico|cr[ií]tico|grave/', $t))
        return 'Urgente';
    return 'Normal';
}

// ─────────────────────────────────────────────────────────────
// 4. EXTRAER CAMPO EQUIPO
// ─────────────────────────────────────────────────────────────
// Busca el campo en plantilla formal primero, luego intenta detectar
// equipos conocidos en texto libre.

function extraerEquipo($texto) {
    // Plantilla formal: "🔩 Equipo: ..." o "Equipo: ..."
    if (preg_match('/(?:🔩\s*)?[Ee]quipo\s*:\s*(.+?)(?:\n|$)/u', $texto, $m)) {
        $eq = trim($m[1]);
        if (!empty($eq) && strtolower($eq) !== 'n/a' && $eq !== '-') return $eq;
    }

    // Detección de equipos conocidos en texto libre
    $equipos = [
        // Barreras y acceso vehicular
        '/\bcame\b.*?(?:gt\w+|g\d+)?/i'           => 'Barrera CAME',
        '/\bpluma\b/i'                              => 'Pluma vehicular',
        '/\bbarrera\b/i'                            => 'Barrera vehicular',
        '/\btorniquete\b/i'                         => 'Torniquete',
        '/\bnedap\b/i'                              => 'Lector Nedap',
        '/\bzkteco\b/i'                             => 'Control ZKTeco',
        '/\blector\b/i'                             => 'Lector de acceso',
        // CCTV
        '/\bhikvision\b/i'                          => 'Cámara Hikvision',
        '/\bdahua\b/i'                              => 'Cámara Dahua',
        '/\bdvr\b/i'                                => 'DVR',
        '/\bnvr\b/i'                                => 'NVR',
        '/\bc[aá]mara\b/i'                          => 'Cámara',
        // Redes
        '/\bruijie\b/i'                             => 'Router Ruijie',
        '/\bstarlink\b/i'                           => 'Starlink',
        '/\brouter\b/i'                             => 'Router',
        '/\bswitch\b/i'                             => 'Switch',
        // Alarma/perímetro
        '/\bcerco\s*el[eé]ctrico\b/i'              => 'Cerco eléctrico',
        '/\balarma\b/i'                             => 'Panel de alarma',
        '/\bsensor\b/i'                             => 'Sensor',
    ];

    foreach ($equipos as $patron => $nombre) {
        if (preg_match($patron, $texto)) return $nombre;
    }

    return ''; // No detectado
}

// ─────────────────────────────────────────────────────────────
// 5. APLICAR CLASIFICACIÓN
// ─────────────────────────────────────────────────────────────

$categoria     = detectarCategoria($texto);
$residencial   = detectarResidencial($texto);
$tipo_reporte  = detectarTipoReporte($texto);
$nivel_urgencia = detectarNivelUrgencia($texto);
$prioridad     = detectarPrioridad($texto, $nivel_urgencia);
$equipo        = extraerEquipo($texto);

// ─────────────────────────────────────────────────────────────
// 6. VALIDAR CAMPO EQUIPO OBLIGATORIO
// ─────────────────────────────────────────────────────────────
// Solo exigir equipo si el mensaje parece plantilla formal
// (tiene al menos 2 campos de plantilla). Mensajes libres pasan igual.

$es_plantilla_formal = preg_match('/(?:📍|📂|📋|🚨|👤|🔩)\s*\w+\s*:/u', $texto)
                    && substr_count($texto, ':') >= 3;

if ($es_plantilla_formal && empty($equipo)) {
    echo "SIN_EQUIPO";
    $conn->close();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 7. GUARDAR IMAGEN
// ─────────────────────────────────────────────────────────────

$ruta_imagen = "";
if (!empty($imagen)) {
    $datos = base64_decode($imagen);
    if ($datos !== false) {
        $nombre = "in_" . time() . "_" . mt_rand(1000, 9999) . ".jpg";
        $dir    = __DIR__ . "/uploads/";
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir . $nombre, $datos);
        $ruta_imagen = "uploads/" . $nombre;
    }
}

// ─────────────────────────────────────────────────────────────
// 8. INSERTAR EN BD
// ─────────────────────────────────────────────────────────────

$stmt = $conn->prepare("
    INSERT INTO reportes
        (descripcion, remitente, fecha, foto_url, estatus,
         categoria, prioridad, residencial, tipo_reporte, nivel_urgencia, equipo)
    VALUES (?, ?, ?, ?, 'Pendiente', ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "ssssssssss",
    $texto, $usuario, $fecha, $ruta_imagen,
    $categoria, $prioridad, $residencial,
    $tipo_reporte, $nivel_urgencia, $equipo
);
$exito      = $stmt->execute();
$id_insertado = $conn->insert_id;
$error      = $stmt->error;
$stmt->close();

// ─────────────────────────────────────────────────────────────
// 9. NOTIFICAR NUEVO REPORTE AL GRUPO
// ─────────────────────────────────────────────────────────────

if ($exito && $id_insertado) {
    $mensaje  = "📋 *NUEVO REPORTE #{$id_insertado}*\n";
    $mensaje .= "📂 Categoría: {$categoria}\n";
    $mensaje .= "📍 Residencial: " . ($residencial ?: 'No especificado') . "\n";
    if (!empty($equipo)) $mensaje .= "🔩 Equipo: {$equipo}\n";
    $mensaje .= "🏷️ Tipo: {$tipo_reporte}\n";
    if ($nivel_urgencia) $mensaje .= "⚠️ Nivel: {$nivel_urgencia}\n";
    $mensaje .= "👤 Reportado por: {$usuario}";

    $stmt_n = $conn->prepare(
        "INSERT INTO notificaciones_pendientes (mensaje, reporte_id, tipo) VALUES (?, ?, 'nuevo')"
    );
    $stmt_n->bind_param("si", $mensaje, $id_insertado);
    $stmt_n->execute();
    $stmt_n->close();

    // ✅ CORREGIDO: devuelve OK:ID para que el bot confirme el número al encargado
    echo "OK:" . $id_insertado;

} else {
    http_response_code(500);
    echo "Error: " . $error;
}

$conn->close();
?>
