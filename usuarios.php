<?php
require_once __DIR__ . '/auth.php';
requireLogin();
if (!hasRole('superadmin')) {
    header('Location: /reportes/');
    exit;
}
require_once __DIR__ . '/config.php';
$conn = getDB();

$residenciales = ['RIO','Cumbres','Via Cumbres','Aqua','Palmaris','Arbolada','Altai','Kyra','Monte Athos'];
$roles_list    = ['superadmin','coordinador','admin','tecnico'];
$msg = ''; $msg_type = '';

// ── Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre     = trim($_POST['nombre'] ?? '');
        $usuario    = trim($_POST['usuario'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $rol        = $_POST['rol'] ?? 'tecnico';
        $residencial= $_POST['residencial'] ?? null;
        $residencial= ($rol === 'superadmin' || $rol === 'coordinador') ? null : ($residencial ?: null);

        if ($nombre && $usuario && $password && in_array($rol, $roles_list)) {
            $stmt = $conn->prepare("INSERT INTO usuarios (nombre,usuario,password,rol,residencial) VALUES (?,?,SHA2(?,256),?,?)");
            $stmt->bind_param('sssss', $nombre, $usuario, $password, $rol, $residencial);
            if ($stmt->execute()) {
                $msg = "Usuario '$nombre' creado correctamente.";
                $msg_type = 'ok';
            } else {
                $msg = "Error: usuario ya existe o datos inválidos.";
                $msg_type = 'err';
            }
        } else {
            $msg = "Completa todos los campos requeridos.";
            $msg_type = 'err';
        }
    }

    if ($accion === 'toggle') {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE usuarios SET activo = NOT activo WHERE id=$id AND usuario != 'eduardo'");
        $msg = "Estado actualizado."; $msg_type = 'ok';
    }

    if ($accion === 'reset_pass') {
        $id       = (int)$_POST['id'];
        $new_pass = trim($_POST['new_pass'] ?? '');
        if ($new_pass && strlen($new_pass) >= 6) {
            $stmt = $conn->prepare("UPDATE usuarios SET password=SHA2(?,256) WHERE id=?");
            $stmt->bind_param('si', $new_pass, $id);
            $stmt->execute();
            $msg = "Contraseña actualizada."; $msg_type = 'ok';
        } else {
            $msg = "La contraseña debe tener al menos 6 caracteres."; $msg_type = 'err';
        }
    }

    if ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM usuarios WHERE id=$id AND usuario != 'eduardo'");
        $msg = "Usuario eliminado."; $msg_type = 'ok';
    }
}

// ── Listar usuarios
$usuarios = $conn->query("SELECT * FROM usuarios ORDER BY rol, nombre")->fetch_all(MYSQLI_ASSOC);
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Usuarios — Commune</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#09090b;--bg1:#111113;--bg2:#18181b;--bg3:#27272a;--border:#3f3f46;--ink:#fafafa;--ink2:#a1a1aa;--ink3:#71717a;--amber:#f59e0b;--red:#ef4444;--green:#22c55e;--blue:#3b82f6;}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Syne',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;font-size:14px;}
.topbar{background:var(--bg1);border-bottom:1px solid var(--border);padding:0 24px;height:58px;display:flex;align-items:center;gap:14px;position:sticky;top:0;z-index:50;}
.logo{font-size:18px;font-weight:800;letter-spacing:-.03em;}.logo span{color:var(--amber);}
.topbar-sub{font-size:11px;color:var(--ink3);font-family:'DM Mono',monospace;letter-spacing:.08em;}
.topbar-right{margin-left:auto;display:flex;gap:10px;}
.btn-sm{padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--bg2);color:var(--ink2);text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
.btn-sm:hover{border-color:var(--amber);color:var(--amber);}
.btn-primary{background:var(--amber);color:#1a1308;border-color:var(--amber);}
.btn-primary:hover{background:#fbbf24;color:#1a1308;}
.wrap{padding:24px;max-width:1200px;margin:0 auto;}
.msg{padding:11px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;}
.msg-ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--green);}
.msg-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--red);}
.grid{display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;}
.card{background:var(--bg1);border:1px solid var(--border);border-radius:10px;padding:22px;}
.card-title{font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);font-family:'DM Mono',monospace;margin-bottom:18px;}
.field{margin-bottom:14px;}
.field label{display:block;font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);font-family:'DM Mono',monospace;margin-bottom:6px;}
.field input,.field select{width:100%;background:var(--bg2);border:1px solid var(--border);color:var(--ink);padding:9px 12px;border-radius:7px;font-size:13px;font-family:'Syne',sans-serif;}
.field input:focus,.field select:focus{outline:none;border-color:var(--amber);}
.btn-full{width:100%;background:var(--amber);color:#1a1308;border:none;padding:11px;border-radius:8px;font-size:13px;font-weight:700;font-family:'Syne',sans-serif;cursor:pointer;}
.btn-full:hover{background:#fbbf24;}
.tabla{width:100%;border-collapse:collapse;font-size:13px;}
.tabla th{font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);font-family:'DM Mono',monospace;padding:9px 12px;border-bottom:1px solid var(--border);text-align:left;}
.tabla td{padding:10px 12px;border-bottom:1px solid var(--bg2);}
.tabla tr:last-child td{border-bottom:none;}
.tabla tr:hover td{background:var(--bg2);}
.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:10px;font-family:'DM Mono',monospace;font-weight:500;}
.b-superadmin{background:rgba(245,158,11,.15);color:var(--amber);}
.b-coordinador{background:rgba(139,92,246,.15);color:#a78bfa;}
.b-admin{background:rgba(59,130,246,.15);color:var(--blue);}
.b-tecnico{background:rgba(34,197,94,.15);color:var(--green);}
.b-inactivo{background:var(--bg3);color:var(--ink3);}
.actions{display:flex;gap:6px;flex-wrap:wrap;}
.btn-act{padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer;border:1px solid var(--border);background:var(--bg2);color:var(--ink2);font-family:'Syne',sans-serif;}
.btn-act:hover{border-color:var(--amber);color:var(--amber);}
.btn-danger{border-color:rgba(239,68,68,.3);color:var(--red);}
.btn-danger:hover{background:rgba(239,68,68,.1);border-color:var(--red);}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:100;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--bg1);border:1px solid var(--border);border-radius:10px;padding:24px;width:100%;max-width:380px;}
.modal-title{font-size:14px;font-weight:700;margin-bottom:16px;}
.modal-actions{display:flex;gap:10px;margin-top:16px;}
.avatar{width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;}
.user-cell{display:flex;align-items:center;gap:10px;}
.res-hint{font-size:11px;color:var(--ink3);margin-top:4px;}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
  <div class="logo">commune<span>.</span></div>
  <div class="topbar-sub">GESTIÓN DE USUARIOS</div>
  <div class="topbar-right">
    <a href="/reportes/" class="btn-sm">← Panel</a>
    <a href="/reportes/logout.php" class="btn-sm">Cerrar sesión</a>
  </div>
</header>

<div class="wrap">
  <?php if($msg): ?>
  <div class="msg msg-<?php echo $msg_type;?>"><?php echo htmlspecialchars($msg);?></div>
  <?php endif;?>

  <div class="grid">

    <!-- CREAR USUARIO -->
    <div class="card">
      <div class="card-title">➕ Nuevo usuario</div>
      <form method="POST">
        <input type="hidden" name="accion" value="crear">
        <div class="field">
          <label>Nombre completo *</label>
          <input type="text" name="nombre" placeholder="Ej: Juan Pérez" required>
        </div>
        <div class="field">
          <label>Usuario *</label>
          <input type="text" name="usuario" placeholder="Ej: juan_perez" required autocomplete="off">
        </div>
        <div class="field">
          <label>Contraseña *</label>
          <input type="password" name="password" placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
        </div>
        <div class="field">
          <label>Rol *</label>
          <select name="rol" id="rolSel" onchange="toggleResidencial()">
            <option value="tecnico">Técnico</option>
            <option value="admin">Admin (residencial)</option>
            <option value="coordinador">Coordinador</option>
            <option value="superadmin">Superadmin</option>
          </select>
        </div>
        <div class="field" id="resField">
          <label>Residencial asignada</label>
          <select name="residencial">
            <option value="">— Sin asignar —</option>
            <?php foreach($residenciales as $r): ?>
            <option value="<?php echo $r;?>"><?php echo $r;?></option>
            <?php endforeach;?>
          </select>
          <div class="res-hint">Solo para roles Admin y Técnico</div>
        </div>
        <button type="submit" class="btn-full">Crear usuario</button>
      </form>
    </div>

    <!-- LISTA USUARIOS -->
    <div class="card">
      <div class="card-title">👥 Usuarios registrados (<?php echo count($usuarios);?>)</div>
      <div style="overflow-x:auto">
      <table class="tabla">
        <thead><tr>
          <th>Usuario</th><th>Rol</th><th>Residencial</th><th>Último acceso</th><th>Estado</th><th>Acciones</th>
        </tr></thead>
        <tbody>
        <?php 
        $colores = ['#f59e0b','#3b82f6','#22c55e','#ef4444','#8b5cf6','#06b6d4','#f97316'];
        foreach($usuarios as $i => $u): 
          $iniciales = strtoupper(substr($u['nombre'],0,1).substr(strstr($u['nombre'],' '),1,1));
          $color = $colores[$i % count($colores)];
        ?>
        <tr>
          <td>
            <div class="user-cell">
              <div class="avatar" style="background:<?php echo $color;?>20;color:<?php echo $color;?>;border:1px solid <?php echo $color;?>40"><?php echo $iniciales;?></div>
              <div>
                <div style="font-weight:600"><?php echo htmlspecialchars($u['nombre']);?></div>
                <div style="font-size:11px;color:var(--ink3);font-family:'DM Mono',monospace"><?php echo htmlspecialchars($u['usuario']);?></div>
              </div>
            </div>
          </td>
          <td><span class="badge b-<?php echo $u['rol'];?>"><?php echo $u['rol'];?></span></td>
          <td style="font-size:12px;color:var(--ink2)"><?php echo $u['residencial'] ?? '—';?></td>
          <td style="font-size:11px;color:var(--ink3);font-family:'DM Mono',monospace">
            <?php echo $u['ultimo_acceso'] ? date('d/m/y H:i', strtotime($u['ultimo_acceso'])) : 'Nunca';?>
          </td>
          <td>
            <?php if($u['activo']): ?>
            <span class="badge b-tecnico">Activo</span>
            <?php else: ?>
            <span class="badge b-inactivo">Inactivo</span>
            <?php endif;?>
          </td>
          <td>
            <div class="actions">
              <?php if($u['usuario'] !== 'eduardo'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="accion" value="toggle">
                <input type="hidden" name="id" value="<?php echo $u['id'];?>">
                <button type="submit" class="btn-act"><?php echo $u['activo']?'Desactivar':'Activar';?></button>
              </form>
              <button class="btn-act" onclick="openReset(<?php echo $u['id'];?>,'<?php echo htmlspecialchars($u['nombre']);?>')">🔑 Pass</button>
              <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar a <?php echo htmlspecialchars($u['nombre']);?>?')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?php echo $u['id'];?>">
                <button type="submit" class="btn-act btn-danger">Eliminar</button>
              </form>
              <?php else: ?>
              <span style="font-size:11px;color:var(--ink3)">Cuenta protegida</span>
              <?php endif;?>
            </div>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
      </div>
    </div>

  </div>
</div>

<!-- Modal reset password -->
<div class="modal-overlay" id="resetModal" onclick="if(event.target===this)closeReset()">
  <div class="modal">
    <div class="modal-title">🔑 Cambiar contraseña</div>
    <div id="resetNombre" style="color:var(--ink2);font-size:13px;margin-bottom:14px;"></div>
    <form method="POST">
      <input type="hidden" name="accion" value="reset_pass">
      <input type="hidden" name="id" id="resetId">
      <div class="field">
        <label>Nueva contraseña</label>
        <input type="password" name="new_pass" placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-full" style="margin:0">Guardar</button>
        <button type="button" class="btn-sm" onclick="closeReset()" style="flex-shrink:0">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleResidencial() {
  const rol = document.getElementById('rolSel').value;
  const resField = document.getElementById('resField');
  resField.style.display = (rol === 'superadmin' || rol === 'coordinador') ? 'none' : 'block';
}
toggleResidencial();

function openReset(id, nombre) {
  document.getElementById('resetId').value = id;
  document.getElementById('resetNombre').textContent = 'Usuario: ' + nombre;
  document.getElementById('resetModal').classList.add('open');
}
function closeReset() {
  document.getElementById('resetModal').classList.remove('open');
}
</script>
</body>
</html>
