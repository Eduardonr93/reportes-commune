<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /reportes/login.php');
        exit;
    }
}

function hasRole(...$roles) {
    return in_array($_SESSION['rol'] ?? '', $roles);
}

function canAccessResidencial($residencial) {
    if (hasRole('superadmin', 'coordinador')) return true;
    if (hasRole('admin', 'tecnico')) {
        return $_SESSION['residencial'] === $residencial;
    }
    return false;
}

function getCurrentUser() {
    return [
        'id'          => $_SESSION['user_id'] ?? null,
        'nombre'      => $_SESSION['nombre'] ?? '',
        'usuario'     => $_SESSION['usuario'] ?? '',
        'rol'         => $_SESSION['rol'] ?? '',
        'residencial' => $_SESSION['residencial'] ?? null,
    ];
}
