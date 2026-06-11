<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /reportes/');
    exit;
}

require_once __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($usuario && $password) {
        $conn = getDB();
        $stmt = $conn->prepare("SELECT id, nombre, usuario, rol, residencial FROM usuarios WHERE usuario=? AND password=SHA2(?,256) AND activo=1");
        $stmt->bind_param('ss', $usuario, $password);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();

        if ($user) {
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['nombre']      = $user['nombre'];
            $_SESSION['usuario']     = $user['usuario'];
            $_SESSION['rol']         = $user['rol'];
            $_SESSION['residencial'] = $user['residencial'];

            $conn->query("UPDATE usuarios SET ultimo_acceso=NOW() WHERE id={$user['id']}");

            $redirect = $_GET['redirect'] ?? '/reportes/';
            header("Location: $redirect");
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos';
        }
    } else {
        $error = 'Completa todos los campos';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Commune — Iniciar sesión</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#09090b;--bg1:#111113;--bg2:#18181b;--border:#3f3f46;
  --ink:#fafafa;--ink2:#a1a1aa;--ink3:#71717a;
  --amber:#f59e0b;--amber2:#fbbf24;
  --red:#ef4444;--green:#22c55e;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Syne',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
background-image:radial-gradient(ellipse at top left,rgba(245,158,11,.06),transparent 50%),radial-gradient(ellipse at bottom right,rgba(59,130,246,.04),transparent 50%);}
.card{width:100%;max-width:420px;background:var(--bg1);border:1px solid var(--border);border-radius:12px;padding:40px 36px;}
.logo{font-size:28px;font-weight:800;letter-spacing:-.04em;margin-bottom:8px;}
.logo span{color:var(--amber);}
.subtitle{font-size:12px;color:var(--ink3);font-family:'DM Mono',monospace;letter-spacing:.1em;text-transform:uppercase;margin-bottom:36px;}
.field{margin-bottom:18px;}
.field label{display:block;font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);font-family:'DM Mono',monospace;margin-bottom:7px;}
.field input{width:100%;background:var(--bg2);border:1px solid var(--border);color:var(--ink);padding:11px 14px;border-radius:8px;font-size:14px;font-family:'Syne',sans-serif;transition:border-color .15s;}
.field input:focus{outline:none;border-color:var(--amber);}
.btn{width:100%;background:var(--amber);color:#1a1308;border:none;padding:13px;border-radius:8px;font-size:14px;font-weight:700;font-family:'Syne',sans-serif;cursor:pointer;letter-spacing:.02em;transition:background .15s;margin-top:6px;}
.btn:hover{background:var(--amber2);}
.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--red);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:18px;}
.divider{height:1px;background:var(--border);margin:28px 0;}
.footer{text-align:center;font-size:11px;color:var(--ink3);font-family:'DM Mono',monospace;}
</style>
</head>
<body>
<div class="card">
  <div class="logo">commune<span>.</span></div>
  <div class="subtitle">Panel de operaciones</div>

  <?php if($error): ?>
  <div class="error">⚠ <?php echo htmlspecialchars($error);?></div>
  <?php endif;?>

  <form method="POST">
    <div class="field">
      <label>Usuario</label>
      <input type="text" name="usuario" placeholder="tu_usuario" autocomplete="username" required value="<?php echo htmlspecialchars($_POST['usuario']??'');?>">
    </div>
    <div class="field">
      <label>Contraseña</label>
      <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn">Entrar →</button>
  </form>

  <div class="divider"></div>
  <div class="footer">Commune · Sistema de gestión Cancún</div>
</div>
</body>
</html>
