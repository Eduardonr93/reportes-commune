<?php
// recibir_comunicado.php - Guarda mensajes del equipo Commune
$host = "localhost"; $user = "commune_reportes";
$pass = "ComuneReportes2026"; $db = "commune_reportes";
$conn = new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error) die("Error: ".$conn->connect_error);

$texto   = isset($_POST['texto']) ? $_POST['texto'] : '';
$usuario = isset($_POST['usuario']) ? $_POST['usuario'] : 'Desconocido';
$numero  = isset($_POST['numero']) ? $_POST['numero'] : '';
$fecha   = isset($_POST['fecha']) ? $_POST['fecha'] : date('Y-m-d H:i:s');
$imagen  = isset($_POST['imagen_base64']) ? $_POST['imagen_base64'] : '';

$texto   = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
$usuario = mb_convert_encoding($usuario, 'UTF-8', 'UTF-8');

if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d H:i:s');
}

$ruta_imagen = "";
if (!empty($imagen)) {
    $datos = base64_decode($imagen);
    if ($datos !== false) {
        $nombre = "com_".time()."_".mt_rand(1000,9999).".jpg";
        $ruta = __DIR__ . "/uploads/" . $nombre;
        if (!is_dir(__DIR__ . "/uploads")) mkdir(__DIR__ . "/uploads", 0755, true);
        file_put_contents($ruta, $datos);
        $ruta_imagen = "uploads/" . $nombre;
    }
}

$stmt = $conn->prepare("INSERT INTO comunicados_internos (remitente, remitente_numero, mensaje, tiene_media, foto_url, fecha) VALUES (?, ?, ?, ?, ?, ?)");
$tiene_media = !empty($ruta_imagen) ? 1 : 0;
$stmt->bind_param("sssiss", $usuario, $numero, $texto, $tiene_media, $ruta_imagen, $fecha);
$exito = $stmt->execute();
$stmt->close();

echo $exito ? "OK" : "Error: " . $conn->error;
$conn->close();
?>