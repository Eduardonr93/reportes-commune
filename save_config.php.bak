<?php
header('Content-Type: application/json; charset=utf-8');

// Solo acepta POST con JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

// Sanitizar y validar campos
$config = [
    'alert_nuevo'      => !empty($data['alert_nuevo']),
    'alert_terminado'  => !empty($data['alert_terminado']),
    'alert_urgente'    => !empty($data['alert_urgente']),
    'push_enabled'     => !empty($data['push_enabled']),
    'whatsapp_number'  => preg_replace('/[^0-9]/', '', $data['whatsapp_number'] ?? ''),
    'email'            => filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL),
];

$file = __DIR__ . '/alertas_config.json';

if (file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT))) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'No se pudo escribir el archivo']);
}
?>
