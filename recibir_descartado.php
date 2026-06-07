<?php
$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error){ echo "Error"; exit; }

$texto    = isset($_POST['texto'])        ? $_POST['texto']        : '';
$usuario  = isset($_POST['usuario'])      ? $_POST['usuario']      : 'Desconocido';
$fecha    = isset($_POST['fecha'])        ? $_POST['fecha']        : date('Y-m-d H:i:s');
$motivo   = isset($_POST['motivo'])       ? $_POST['motivo']       : 'prefiltro';
$imagen   = isset($_POST['imagen_base64'])? $_POST['imagen_base64']: '';

$texto   = mb_convert_encoding($texto,   'UTF-8', 'UTF-8');
$usuario = mb_convert_encoding($usuario, 'UTF-8', 'UTF-8');

if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha))
    $fecha = date('Y-m-d H:i:s');

$ruta_imagen = "";
if(!empty($imagen)){
    $datos = base64_decode($imagen);
    if($datos !== false){
        $nombre = "desc_".time()."_".mt_rand(1000,9999).".jpg";
        $ruta   = __DIR__."/uploads/".$nombre;
        if(!is_dir(__DIR__."/uploads")) mkdir(__DIR__."/uploads", 0755, true);
        file_put_contents($ruta, $datos);
        $ruta_imagen = "uploads/".$nombre;
    }
}

if(!empty($texto)){
    $stmt = $conn->prepare("INSERT INTO reportes_descartados (remitente, texto, fecha, motivo, foto_url) VALUES (?,?,?,?,?)");
    $stmt->bind_param("sssss", $usuario, $texto, $fecha, $motivo, $ruta_imagen);
    echo $stmt->execute() ? "OK" : "Error: ".$stmt->error;
} else {
    echo "VACIO";
}
$conn->close();
?>
