<?php
$conn = new mysqli('localhost', 'commune_reportes', 'ComuneReportes2026', 'commune_reportes');
if ($conn->connect_error) {
    echo "Error: " . $conn->connect_error;
} else {
    echo "Conexión exitosa a BD";
    $conn->close();
}
?>
