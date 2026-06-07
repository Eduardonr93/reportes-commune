<?php
// ── DB ────────────────────────────────────────────────────
$host="localhost"; $user="thenetgu_reportes"; $pass="thenetgu_reportes"; $db="thenetgu_reportes";
$conn=new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error) die("Error: ".$conn->connect_error);

// ── Acciones ──────────────────────────────────────────────
if(isset($_POST['recuperar'])){
    $id=(int)$_POST['id'];
    $r=$conn->query("SELECT * FROM reportes_descartados WHERE id=$id")->fetch_assoc();
    if($r){
        $stmt=$conn->prepare("INSERT INTO reportes (descripcion,remitente,fecha,estatus,categoria) VALUES (?,?,?,'Pendiente','General')");
        $stmt->bind_param("sss",$r['mensaje'],$r['remitente'],$r['fecha']);
        $stmt->execute();
        $conn->query("UPDATE reportes_descartados SET restaurado=1 WHERE id=$id");
    }
    header("Location: basurero.php"); exit;
}

if(isset($_POST['eliminar'])){
    $id=(int)$_POST['id'];
    $conn->query("DELETE FROM reportes_descartados WHERE id=$id");
    header("Location: basurero.php"); exit;
}

if(isset($_POST['vaciar_todo'])){
    $conn->query("TRUNCATE TABLE reportes_descartados");
    header("Location: basurero.php"); exit;
}

// ── Stats ─────────────────────────────────────────────────
$total = $conn->query("SELECT COUNT(*) c FROM reportes_descartados")->fetch_assoc()['c'];
$porGemini = $conn->query("SELECT COUNT(*) c FROM reportes_descartados WHERE motivo='gemini-no'")->fetch_assoc()['c'];
$porPrefiltro = $conn->query("SELECT COUNT(*) c FROM reportes_descartados WHERE motivo='prefiltro'")->fetch_assoc()['c'];

// ── Paginación ────────────────────────────────────────────
$pagina = isset($_GET['p']) ? max(1,(int)$_GET['p']) : 1;
$por_pagina = 20;
$offset = ($pagina-1)*$por_pagina;
$total_paginas = ceil($total/$por_pagina);

$res = $conn->query("SELECT * FROM reportes_descartados ORDER BY id DESC LIMIT $por_pagina OFFSET $offset");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Basurero — Commune</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f1f5fb;--bg2:#ffffff;--bg3:#f8fafd;--bg4:#eef2f9;
  --border:#e2e8f4;--border2:#c7d4eb;
  --text:#0f172a;--text2:#475569;--text3:#94a3b8;
  --accent:#2563eb;--accent-l:#eff6ff;
  --danger:#dc2626;--danger-l:#fef2f2;
  --warn:#d97706;--warn-l:#fffbeb;
  --success:#16a34a;--success-l:#f0fdf4;
  --sh:0 1px 3px rgba(15,23,42,.06),0 4px 12px rgba(15,23,42,.04);
  --r:12px;--r-sm:8px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;padding:20px}
.header{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:20px 24px;margin-bottom:20px;box-shadow:var(--sh);display:flex;justify-content:space-between;align-items:center}
.title{font-size:18px;font-weight:700}
.sub{font-size:12px;color:var(--text3);margin-top:2px}
.back{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 14px;font-size:13px;color:var(--text2);text-decoration:none}
.back:hover{border-color:var(--accent);color:var(--accent)}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.stat{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px;text-align:center}
.stat-n{font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700}
.stat-l{font-size:11px;color:var(--text3);margin-top:4px;text-transform:uppercase}
.actions{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:14px 18px;margin-bottom:20px}
.btn{padding:8px 14px;border-radius:var(--r-sm);font-size:13px;font-weight:500;cursor:pointer;border:none}
.btn-danger{background:var(--danger-l);border:1px solid #fca5a5;color:var(--danger)}
.btn-danger:hover{background:#fee2e2}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:14px 16px;margin-bottom:12px}
.card-header{display:flex;justify-content:space-between;margin-bottom:8px}
.card-time{font-size:11px;color:var(--text3);font-family:'JetBrains Mono'}
.badge{font-size:10px;padding:3px 8px;border-radius:20px;font-weight:500;margin-left:8px}
.badge-gemini{background:var(--warn-l);color:var(--warn)}
.badge-prefiltro{background:#dbeafe;color:#1d4ed8}
.card-name{font-size:13px;font-weight:600;color:var(--text2);margin-bottom:6px}
.card-msg{font-size:13px;color:var(--text2);line-height:1.6;background:var(--bg3);padding:10px 12px;border-radius:var(--r-sm);margin-bottom:10px}
.card-actions{display:flex;gap:8px}
.btn-sm{padding:6px 12px;font-size:12px;border-radius:6px;cursor:pointer;border:none;font-weight:500}
.btn-success{background:var(--success-l);border:1px solid #bbf7d0;color:var(--success)}
.btn-success:hover{background:#dcfce7}
.btn-del{background:var(--danger-l);border:1px solid #fca5a5;color:var(--danger)}
.btn-del:hover{background:#fee2e2}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:20px}
.page{padding:6px 12px;background:var(--bg2);border:1px solid var(--border);border-radius:6px;font-size:13px;color:var(--text2);text-decoration:none}
.page.active{background:var(--accent);color:#fff}
.page:hover:not(.active){border-color:var(--accent)}
.empty{text-align:center;padding:60px 20px;color:var(--text3)}
</style>
</head>
<body>
<div class="header"><div><div class="title">🗑️ Basurero — Mensajes descartados</div><div class="sub">Audita los mensajes que la IA descartó. Si ves un reporte válido, recupéralo.</div></div><a href="index.php" class="back">← Volver al panel</a></div>
<div class="stats"><div class="stat"><div class="stat-n"><?php echo $total;?></div><div class="stat-l">Total descartados</div></div><div class="stat"><div class="stat-n"><?php echo $porGemini;?></div><div class="stat-l">Por Gemini</div></div><div class="stat"><div class="stat-n"><?php echo $porPrefiltro;?></div><div class="stat-l">Por prefiltro</div></div></div>
<?php if($total>0): ?><div class="actions"><form method="POST" onsubmit="return confirm('¿Eliminar TODOS los mensajes descartados?')"><button type="submit" name="vaciar_todo" class="btn btn-danger">🗑️ Vaciar todo</button></form></div><?php endif;?>
<?php if($res && $res->num_rows>0): while($d=$res->fetch_assoc()): $restaurado=(int)$d['restaurado']; ?>
<div class="card" style="<?php echo $restaurado?'opacity:.5;border-color:#bbf7d0':'';?>"><div class="card-header"><span class="card-time"><?php echo date('d/m/Y H:i',strtotime($d['fecha']));?></span><span><span class="badge badge-<?php echo $d['motivo']==='gemini-no'?'gemini':'prefiltro';?>"><?php echo $d['motivo']==='gemini-no'?'🤖 Gemini':'⚡ Prefiltro';?></span><?php if($restaurado): ?><span class="badge" style="background:var(--success-l);color:var(--success)">✅ Recuperado</span><?php endif;?></span></div><div class="card-name">👤 <?php echo htmlspecialchars($d['remitente']);?></div><div class="card-msg"><?php echo nl2br(htmlspecialchars($d['mensaje']?:'(sin texto)'));?></div><?php if(!$restaurado): ?><div class="card-actions"><form method="POST" style="display:inline"><input type="hidden" name="id" value="<?php echo $d['id'];?>"><button type="submit" name="recuperar" class="btn-sm btn-success">✅ Recuperar como reporte</button></form><form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar permanentemente?')"><input type="hidden" name="id" value="<?php echo $d['id'];?>"><button type="submit" name="eliminar" class="btn-sm btn-del">🗑️ Eliminar</button></form></div><?php endif;?></div>
<?php endwhile; else: ?>
<div class="empty"><div style="font-size:48px;margin-bottom:12px">🎉</div><div style="font-size:16px;font-weight:600;margin-bottom:4px">No hay mensajes descartados</div><div style="font-size:13px">Todos los mensajes han sido aprobados o el basurero está vacío.</div></div>
<?php endif;?>
<?php if($total_paginas>1): ?><div class="pagination"><?php for($i=1;$i<=$total_paginas;$i++): ?><a href="?p=<?php echo $i;?>" class="page <?php echo $i===$pagina?'active':'';?>"><?php echo $i;?></a><?php endfor;?></div><?php endif;?>
</body>
</html>