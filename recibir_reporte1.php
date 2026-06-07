<?php
$host = "localhost";
$user = "thenetgu_reportes";
$pass = "thenetgu_reportes";
$db   = "thenetgu_reportes";

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) { die("Error: " . $conn->connect_error); }

$texto   = isset($_POST['texto'])        ? $_POST['texto']        : '';
$usuario = isset($_POST['usuario'])      ? $_POST['usuario']      : 'Desconocido';
$fecha   = isset($_POST['fecha'])        ? $_POST['fecha']        : date('Y-m-d H:i:s');
$imagen  = isset($_POST['imagen_base64'])? $_POST['imagen_base64']: '';

// Limpiar encoding UTF-8
$texto   = mb_convert_encoding($texto,   'UTF-8', 'UTF-8');
$usuario = mb_convert_encoding($usuario, 'UTF-8', 'UTF-8');

// Validar fecha — si no viene en formato MySQL la generamos aquí
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d H:i:s');
}

if (!empty($texto) || !empty($imagen)) {

    // Clasificación automática por palabras clave
    $cat = "General";
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/c[aá]mara|cctv|dvr|nvr|hikvision|dahua|grabador|video/u', $t))      $cat = "CCTV";
    elseif (preg_match('/cerco|concertina|per[ií]metro|hilo|malla|el[eé]ctric/u', $t))   $cat = "Perímetro";
    elseif (preg_match('/alarma|sensor|panel|sirena|bocina|detector/u', $t))             $cat = "Alarma";
    elseif (preg_match('/barrera|acceso|biom[eé]tric|zkteco|tarjeta|pluma|lector/u', $t)) $cat = "Accesos";
    elseif (preg_match('/wifi|red|router|switch|starlink|ubiquiti|fibra|internet/u', $t)) $cat = "Redes";

    // Guardar imagen si viene
    $ruta_imagen = "";
    if (!empty($imagen)) {
        $datos = base64_decode($imagen);
        if ($datos !== false) {
            $nombre = "in_" . time() . "_" . mt_rand(1000,9999) . ".jpg";
            $ruta   = __DIR__ . "/uploads/" . $nombre;
            if (!is_dir(__DIR__ . "/uploads")) mkdir(__DIR__ . "/uploads", 0755, true);
            file_put_contents($ruta, $datos);
            $ruta_imagen = "uploads/" . $nombre;
        }
    }

    $stmt = $conn->prepare("INSERT INTO reportes (descripcion, remitente, fecha, foto_url, estatus, categoria) VALUES (?, ?, ?, ?, 'Pendiente', ?)");
    $stmt->bind_param("sssss", $texto, $usuario, $fecha, $ruta_imagen, $cat);

    if ($stmt->execute()) {
        echo "OK";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "VACIO";
}

$conn->close();
?>