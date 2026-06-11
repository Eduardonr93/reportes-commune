<?php
require_once __DIR__ . "/auth.php";
requireLogin();
require_once __DIR__ . '/config.php';
$conn = getDB();
$conn->set_charset("utf8mb4");

// ── Parámetro residencial (para admins de una sola comunidad)
$filtro_res = $conn->real_escape_string($_GET['residencial'] ?? '');

// ── Residenciales disponibles
$residenciales = ['RIO','Cumbres','Via Cumbres','Aqua','Palmaris','Arbolada','Altai','Kyra','Monte Athos'];

// ── Stats globales o por residencial
$where_global = $filtro_res ? "WHERE residencial='$filtro_res'" : "WHERE 1=1";

// Stats generales
$total        = $conn->query("SELECT COUNT(*) c FROM reportes $where_global")->fetch_assoc()['c'];
$pendientes   = $conn->query("SELECT COUNT(*) c FROM reportes $where_global AND estatus='Pendiente'")->fetch_assoc()['c'];
$en_proceso   = $conn->query("SELECT COUNT(*) c FROM reportes $where_global AND estatus='En Proceso'")->fetch_assoc()['c'];
$terminados   = $conn->query("SELECT COUNT(*) c FROM reportes $where_global AND estatus='Terminado'")->fetch_assoc()['c'];
$urgentes     = $conn->query("SELECT COUNT(*) c FROM reportes $where_global AND prioridad='Urgente' AND estatus!='Terminado'")->fetch_assoc()['c'];

// Tiempo promedio de resolución (en horas)
$avg_res = $conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, fecha, fecha_terminado)/60) avg_h FROM reportes $where_global AND estatus='Terminado' AND fecha_terminado IS NOT NULL")->fetch_assoc()['avg_h'];
$avg_res = $avg_res ? round($avg_res, 1) : 0;

// Stats por residencial
$stats_por_res = [];
foreach ($residenciales as $res) {
    $r = $conn->real_escape_string($res);
    $w = $filtro_res ? ($filtro_res === $res ? "WHERE residencial='$r'" : null) : "WHERE residencial='$r'";
    if ($w === null) continue;
    $row = [
        'nombre'     => $res,
        'total'      => $conn->query("SELECT COUNT(*) c FROM reportes $w")->fetch_assoc()['c'],
        'pendiente'  => $conn->query("SELECT COUNT(*) c FROM reportes $w AND estatus='Pendiente'")->fetch_assoc()['c'],
        'proceso'    => $conn->query("SELECT COUNT(*) c FROM reportes $w AND estatus='En Proceso'")->fetch_assoc()['c'],
        'terminado'  => $conn->query("SELECT COUNT(*) c FROM reportes $w AND estatus='Terminado'")->fetch_assoc()['c'],
        'urgente'    => $conn->query("SELECT COUNT(*) c FROM reportes $w AND prioridad='Urgente' AND estatus!='Terminado'")->fetch_assoc()['c'],
        'avg_h'      => round($conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE,fecha,fecha_terminado)/60) a FROM reportes $w AND estatus='Terminado' AND fecha_terminado IS NOT NULL")->fetch_assoc()['a'] ?? 0, 1),
    ];
    if ($row['total'] > 0 || !$filtro_res) $stats_por_res[] = $row;
}

// Top técnicos
$where_tec = $filtro_res ? "WHERE residencial='$filtro_res' AND estatus='Terminado'" : "WHERE estatus='Terminado'";
$top_tecs = $conn->query("SELECT tecnico_asignado, COUNT(*) total, AVG(tiempo_trabajado) avg_min FROM reportes $where_tec AND tecnico_asignado != '' GROUP BY tecnico_asignado ORDER BY total DESC LIMIT 5");

// Tendencia 30 días
$tendencia = [];
for ($i = 29; $i >= 0; $i--) {
    $dia   = date('Y-m-d', strtotime("-$i days"));
    $label = date('d/m',   strtotime("-$i days"));
    $w2    = $filtro_res ? "AND residencial='$filtro_res'" : "";
    $cnt   = $conn->query("SELECT COUNT(*) c FROM reportes WHERE DATE(fecha)='$dia' $w2")->fetch_assoc()['c'];
    $tendencia[] = ['label' => $label, 'count' => (int)$cnt];
}

// Distribución por categoría
$cats_data = [];
$w3 = $filtro_res ? "WHERE residencial='$filtro_res'" : "WHERE 1=1";
$res_cats = $conn->query("SELECT categoria, COUNT(*) c FROM reportes $w3 GROUP BY categoria ORDER BY c DESC");
while ($r = $res_cats->fetch_assoc()) $cats_data[] = $r;

// Últimos 5 reportes abiertos
$w4 = $filtro_res ? "AND residencial='$filtro_res'" : "";
$ultimos = $conn->query("SELECT id,fecha,remitente,descripcion,categoria,estatus,prioridad,residencial,tecnico_asignado FROM reportes WHERE estatus!='Terminado' $w4 ORDER BY CASE prioridad WHEN 'Urgente' THEN 0 ELSE 1 END, fecha ASC LIMIT 8");
$ultimos_arr = [];
while ($r = $ultimos->fetch_assoc()) $ultimos_arr[] = $r;

// Tasa de resolución este mes
$mes_ini = date('Y-m-01');
$w5 = $filtro_res ? "AND residencial='$filtro_res'" : "";
$este_mes_total = $conn->query("SELECT COUNT(*) c FROM reportes WHERE DATE(fecha)>='$mes_ini' $w5")->fetch_assoc()['c'];
$este_mes_resueltos = $conn->query("SELECT COUNT(*) c FROM reportes WHERE DATE(fecha)>='$mes_ini' AND estatus='Terminado' $w5")->fetch_assoc()['c'];
$tasa = $este_mes_total > 0 ? round(($este_mes_resueltos / $este_mes_total) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Commune<?php echo $filtro_res ? " — $filtro_res" : ""; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --bg:     #09090b;
  --bg1:    #111113;
  --bg2:    #18181b;
  --bg3:    #27272a;
  --border: #3f3f46;
  --ink:    #fafafa;
  --ink2:   #a1a1aa;
  --ink3:   #71717a;
  --amber:  #f59e0b;
  --amber2: #fbbf24;
  --amber-d:rgba(245,158,11,.12);
  --green:  #22c55e;
  --green-d:rgba(34,197,94,.12);
  --red:    #ef4444;
  --red-d:  rgba(239,68,68,.12);
  --blue:   #3b82f6;
  --blue-d: rgba(59,130,246,.12);
  --r:      8px;
}

[data-theme="light"]{
  --bg:#f1f5fb;--bg1:#ffffff;--bg2:#ffffff;--bg3:#f8fafd;
  --ink:#0f172a;--ink2:#475569;--ink3:#94a3b8;
  --border:#e2e8f0;--accent:#2563eb;
  --green:#16a34a;--green-d:rgba(22,163,74,.1);
  --red:#dc2626;--red-d:rgba(220,38,38,.1);
  --r:10px;
}

.theme-toggle{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:20px;padding:4px 10px;font-size:12px;color:var(--ink2,#94a3b8);cursor:pointer;display:inline-flex;align-items:center;gap:5px;}
[data-theme="light"] .theme-toggle{background:var(--bg2);border-color:var(--border);color:var(--ink2);}

*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Syne',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;font-size:14px;}
a{color:inherit;text-decoration:none}

/* TOPBAR */
.topbar{background:var(--bg1);border-bottom:1px solid var(--border);padding:0 24px;height:60px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:50;}
.logo{font-size:18px;font-weight:800;letter-spacing:-.03em;}
.logo span{color:var(--amber);}
.topbar-sub{font-size:11px;color:var(--ink3);font-family:'DM Mono',monospace;letter-spacing:.08em;}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.pill{padding:4px 10px;border-radius:20px;font-size:11px;font-family:'DM Mono',monospace;border:1px solid}
.pill-live{background:var(--green-d);border-color:rgba(34,197,94,.3);color:var(--green);}
.pill-dot{width:6px;height:6px;border-radius:50%;background:var(--green);display:inline-block;margin-right:4px;animation:blink 2s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.filter-sel{background:var(--bg2);border:1px solid var(--border);color:var(--ink);padding:6px 10px;border-radius:var(--r);font-size:12px;font-family:'Syne',sans-serif;}
.btn-back{background:var(--bg2);border:1px solid var(--border);color:var(--ink2);padding:6px 12px;border-radius:var(--r);font-size:12px;cursor:pointer;}
.btn-back:hover{border-color:var(--amber);color:var(--amber);}

/* LAYOUT */
.wrap{padding:24px;max-width:1400px;margin:0 auto;}
.section-title{font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);font-family:'DM Mono',monospace;margin-bottom:14px;}

/* STATS ROW */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:28px;}
.stat{background:var(--bg1);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px;position:relative;overflow:hidden;}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--c,var(--amber));}
.stat-val{font-size:32px;font-weight:800;letter-spacing:-.04em;line-height:1;margin-bottom:4px;}
.stat-lbl{font-size:10px;color:var(--ink3);font-family:'DM Mono',monospace;letter-spacing:.08em;text-transform:uppercase;}
.stat-sub{font-size:11px;color:var(--ink3);margin-top:6px;}
.c-amber{--c:var(--amber)} .c-red{--c:var(--red)} .c-blue{--c:var(--blue)} .c-green{--c:var(--green)}

/* CHARTS */
.charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:28px;}
.card{background:var(--bg1);border:1px solid var(--border);border-radius:var(--r);padding:20px;}
.card-title{font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);font-family:'DM Mono',monospace;margin-bottom:16px;}
canvas{max-height:200px}

/* RESIDENCIALES */
.res-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:28px;}
.res-card{background:var(--bg1);border:1px solid var(--border);border-radius:var(--r);padding:16px;cursor:pointer;transition:border-color .15s,transform .15s;}
.res-card:hover{border-color:var(--amber);transform:translateY(-2px);}
.res-card.has-urgent{border-left:3px solid var(--red);}
.res-name{font-size:15px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;}
.res-badge{font-size:10px;padding:2px 8px;border-radius:12px;font-family:'DM Mono',monospace;}
.rb-urg{background:var(--red-d);color:var(--red);}
.rb-ok{background:var(--green-d);color:var(--green);}
.res-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;}
.rs{text-align:center;padding:8px 4px;background:var(--bg2);border-radius:6px;}
.rs-n{font-size:18px;font-weight:700;font-family:'DM Mono',monospace;}
.rs-l{font-size:9px;color:var(--ink3);text-transform:uppercase;letter-spacing:.06em;margin-top:2px;}
.res-bar{height:4px;background:var(--bg3);border-radius:2px;margin-top:12px;overflow:hidden;}
.res-bar-fill{height:100%;background:linear-gradient(90deg,var(--green),var(--amber));border-radius:2px;transition:width .5s ease;}
.res-avg{font-size:10px;color:var(--ink3);margin-top:8px;font-family:'DM Mono',monospace;}

/* BOTTOM GRID */
.bottom-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:28px;}

/* TABLA REPORTES */
.tabla{width:100%;border-collapse:collapse;font-size:12px;}
.tabla th{font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);font-family:'DM Mono',monospace;padding:8px 10px;border-bottom:1px solid var(--border);text-align:left;}
.tabla td{padding:8px 10px;border-bottom:1px solid var(--bg2);}
.tabla tr:last-child td{border-bottom:none;}
.tabla tr:hover td{background:var(--bg2);}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-family:'DM Mono',monospace;}
.b-pend{background:var(--red-d);color:var(--red);}
.b-proc{background:rgba(245,158,11,.12);color:var(--amber);}
.b-done{background:var(--green-d);color:var(--green);}
.b-urg{background:var(--red-d);color:var(--red);}
.trunc{max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* TOP TÉCNICOS */
.tec-list{display:flex;flex-direction:column;gap:10px;}
.tec-item{display:flex;align-items:center;gap:12px;padding:10px 12px;background:var(--bg2);border-radius:6px;}
.tec-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--amber),#ef4444);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;}
.tec-name{font-weight:600;font-size:13px;}
.tec-stats{font-size:10px;color:var(--ink3);font-family:'DM Mono',monospace;margin-top:2px;}
.tec-bar-wrap{flex:1;height:4px;background:var(--bg3);border-radius:2px;overflow:hidden;}
.tec-bar{height:100%;background:var(--amber);border-radius:2px;}

/* RESPONSIVE */
@media(max-width:1024px){.stats-grid{grid-template-columns:repeat(3,1fr)}.charts-grid{grid-template-columns:1fr}.bottom-grid{grid-template-columns:1fr}}
@media(max-width:600px){.stats-grid{grid-template-columns:repeat(2,1fr)}.res-grid{grid-template-columns:1fr}.wrap{padding:12px}}
</style>
</head>
<body>

<header class="topbar">
  <div class="logo">commune<span>.</span></div>
  <div class="topbar-sub"><?php echo $filtro_res ? strtoupper($filtro_res) : 'OPERATIONS DASHBOARD'; ?></div>
  <div class="topbar-right">
    <span class="pill pill-live"><span class="pill-dot"></span>En vivo</span>
    <?php if(!$filtro_res): ?>
    <form method="GET" style="display:inline">
      <select class="filter-sel" name="residencial" onchange="this.form.submit()">
        <option value="">Todas las comunidades</option>
        <?php foreach($residenciales as $r): ?>
        <option value="<?php echo $r;?>"><?php echo $r;?></option>
        <?php endforeach;?>
      </select>
    </form>
    <?php else: ?>
    <a href="?" class="btn-back">← Todas las comunidades</a>
    <?php endif;?>
    <a href="/reportes/" class="btn-back">Panel de reportes</a><button class="theme-toggle" id="themeBtn" onclick="toggleTheme()">☀️ Claro</button>
    <span id="clock" style="font-family:'DM Mono',monospace;font-size:11px;color:var(--ink3)"></span>
  </div>
</header>

<div class="wrap">

  <!-- STATS -->
  <div class="section-title">Resumen general</div>
  <div class="stats-grid">
    <div class="stat c-amber">
      <div class="stat-val"><?php echo $total;?></div>
      <div class="stat-lbl">Total reportes</div>
    </div>
    <div class="stat c-red">
      <div class="stat-val"><?php echo $pendientes;?></div>
      <div class="stat-lbl">Pendientes</div>
    </div>
    <div class="stat c-amber">
      <div class="stat-val"><?php echo $en_proceso;?></div>
      <div class="stat-lbl">En proceso</div>
    </div>
    <div class="stat c-green">
      <div class="stat-val"><?php echo $terminados;?></div>
      <div class="stat-lbl">Terminados</div>
    </div>
    <div class="stat c-red">
      <div class="stat-val"><?php echo $urgentes;?></div>
      <div class="stat-lbl">Urgentes activos</div>
    </div>
    <div class="stat c-blue">
      <div class="stat-val"><?php echo $avg_res > 0 ? $avg_res.'h' : '—';?></div>
      <div class="stat-lbl">Tiempo prom. resolución</div>
      <div class="stat-sub">Tasa mes: <?php echo $tasa;?>%</div>
    </div>
  </div>

  <!-- GRÁFICAS -->
  <div class="charts-grid">
    <div class="card">
      <div class="card-title">📈 Actividad últimos 30 días</div>
      <canvas id="chartTendencia"></canvas>
    </div>
    <div class="card">
      <div class="card-title">🥧 Por categoría</div>
      <canvas id="chartCats"></canvas>
    </div>
  </div>

  <!-- RESIDENCIALES -->
  <?php if(!$filtro_res): ?>
  <div class="section-title">Estado por comunidad</div>
  <div class="res-grid">
    <?php foreach($stats_por_res as $res): 
      $pct = $res['total'] > 0 ? round(($res['terminado']/$res['total'])*100) : 0;
    ?>
    <a href="?residencial=<?php echo urlencode($res['nombre']);?>" class="res-card <?php echo $res['urgente']>0?'has-urgent':'';?>">
      <div class="res-name">
        <?php echo $res['nombre'];?>
        <?php if($res['urgente']>0): ?>
        <span class="res-badge rb-urg">🚨 <?php echo $res['urgente'];?> urgente<?php echo $res['urgente']>1?'s':'';?></span>
        <?php elseif($res['pendiente']==0&&$res['proceso']==0): ?>
        <span class="res-badge rb-ok">✓ Al día</span>
        <?php endif;?>
      </div>
      <div class="res-stats">
        <div class="rs"><div class="rs-n"><?php echo $res['total'];?></div><div class="rs-l">Total</div></div>
        <div class="rs" style="background:var(--red-d)"><div class="rs-n" style="color:var(--red)"><?php echo $res['pendiente'];?></div><div class="rs-l">Pend.</div></div>
        <div class="rs" style="background:rgba(245,158,11,.08)"><div class="rs-n" style="color:var(--amber)"><?php echo $res['proceso'];?></div><div class="rs-l">Proceso</div></div>
        <div class="rs" style="background:var(--green-d)"><div class="rs-n" style="color:var(--green)"><?php echo $res['terminado'];?></div><div class="rs-l">Resueltos</div></div>
      </div>
      <div class="res-bar"><div class="res-bar-fill" style="width:<?php echo $pct;?>%"></div></div>
      <div class="res-avg">
        Resolución: <?php echo $pct;?>% · 
        <?php echo $res['avg_h']>0 ? $res['avg_h'].'h prom.' : 'sin datos';?>
      </div>
    </a>
    <?php endforeach;?>
  </div>
  <?php endif;?>

  <!-- BOTTOM -->
  <div class="bottom-grid">

    <!-- REPORTES ABIERTOS -->
    <div class="card">
      <div class="card-title">⏳ Reportes abiertos <?php echo $filtro_res?"— $filtro_res":"(más antiguos primero)";?></div>
      <?php if(empty($ultimos_arr)): ?>
      <div style="text-align:center;padding:30px;color:var(--ink3)">✅ Sin reportes abiertos</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="tabla">
        <thead><tr>
          <th>ID</th><th>Fecha</th>
          <?php if(!$filtro_res): ?><th>Comunidad</th><?php endif;?>
          <th>Descripción</th><th>Estado</th><th>Técnico</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach($ultimos_arr as $r):
          $ec = $r['estatus']==='Pendiente'?'b-pend':($r['estatus']==='En Proceso'?'b-proc':'b-done');
          $horas = round((time()-strtotime($r['fecha']))/3600,1);
          $tiempo_txt = $horas > 24 ? round($horas/24,1).'d' : $horas.'h';
        ?>
        <tr>
          <td style="font-family:'DM Mono',monospace;font-size:11px">#<?php echo $r['id'];?></td>
          <td style="color:var(--ink3);font-size:11px;white-space:nowrap">
            <?php echo date('d/m H:i',strtotime($r['fecha']));?>
            <div style="font-size:9px;color:<?php echo $horas>24?'var(--red)':'var(--ink3)';?>">hace <?php echo $tiempo_txt;?></div>
          </td>
          <?php if(!$filtro_res): ?><td style="font-size:11px"><?php echo htmlspecialchars($r['residencial']??'—');?></td><?php endif;?>
          <td class="trunc"><?php echo htmlspecialchars(substr($r['descripcion']??'',0,50));?></td>
          <td><span class="badge <?php echo $ec;?>"><?php echo $r['estatus'];?></span>
          <?php if(($r['prioridad']??'')=='Urgente'): ?><span class="badge b-urg" style="margin-left:3px">🚨</span><?php endif;?></td>
          <td style="font-size:11px;color:var(--ink2)"><?php echo htmlspecialchars($r['tecnico_asignado']??'—');?></td>
          <td><button onclick="abrirModalDash(<?php echo $r['id'];?>)" style="background:var(--accent);color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:11px">Ver</button></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
      </div>
      <?php endif;?>
    </div>

    <!-- TOP TÉCNICOS -->
    <div class="card">
      <div class="card-title">👷 Top técnicos (resueltos)</div>
      <?php
      $tec_arr = [];
      while($t = $top_tecs->fetch_assoc()) $tec_arr[] = $t;
      $max_tec = !empty($tec_arr) ? $tec_arr[0]['total'] : 1;
      ?>
      <?php if(empty($tec_arr)): ?>
      <div style="text-align:center;padding:30px;color:var(--ink3)">Sin datos de técnicos</div>
      <?php else: ?>
      <div class="tec-list">
        <?php foreach($tec_arr as $i => $t): 
          $iniciales = strtoupper(substr($t['tecnico_asignado'],0,2));
          $pct_bar = round(($t['total']/$max_tec)*100);
          $avg_min = round($t['avg_min']??0);
          $avg_txt = $avg_min > 60 ? round($avg_min/60,1).'h' : $avg_min.'min';
          $colors = ['#f59e0b,#ef4444','#3b82f6,#8b5cf6','#22c55e,#06b6d4','#f59e0b,#22c55e','#ef4444,#f59e0b'];
        ?>
        <div class="tec-item">
          <div class="tec-avatar" style="background:linear-gradient(135deg,<?php echo $colors[$i%5];?>)"><?php echo $iniciales;?></div>
          <div style="flex:1;min-width:0">
            <div class="tec-name"><?php echo htmlspecialchars($t['tecnico_asignado']);?></div>
            <div class="tec-stats"><?php echo $t['total'];?> resueltos · prom <?php echo $avg_txt;?></div>
            <div class="tec-bar-wrap" style="margin-top:6px"><div class="tec-bar" style="width:<?php echo $pct_bar;?>%"></div></div>
          </div>
          <div style="font-size:20px;font-weight:800;font-family:'DM Mono',monospace;color:var(--amber)"><?php echo $t['total'];?></div>
        </div>
        <?php endforeach;?>
      </div>
      <?php endif;?>
    </div>

  </div>

</div>

<script>
// Reloj
setInterval(()=>{document.getElementById('clock').textContent=new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'});},1000);
document.getElementById('clock').textContent=new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'});

// Gráfica tendencia
const tLabels = <?php echo json_encode(array_column($tendencia,'label'));?>;
const tData   = <?php echo json_encode(array_column($tendencia,'count'));?>;
new Chart(document.getElementById('chartTendencia'),{
  type:'line',
  data:{labels:tLabels,datasets:[{
    label:'Reportes',data:tData,
    borderColor:'#f59e0b',
    backgroundColor:'rgba(245,158,11,0.08)',
    fill:true,tension:0.4,pointRadius:2,pointHoverRadius:5,
    borderWidth:2
  }]},
  options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#71717a',font:{size:10}}},y:{ticks:{color:'#71717a',font:{size:10}},beginAtZero:true}}}
});

// Gráfica categorías
const cLabels = <?php echo json_encode(array_column($cats_data,'categoria'));?>;
const cData   = <?php echo json_encode(array_column($cats_data,'c'));?>;
new Chart(document.getElementById('chartCats'),{
  type:'doughnut',
  data:{labels:cLabels,datasets:[{data:cData,backgroundColor:['#f59e0b','#3b82f6','#22c55e','#ef4444','#8b5cf6','#06b6d4'],borderWidth:0,hoverOffset:6}]},
  options:{responsive:true,maintainAspectRatio:true,cutout:'65%',plugins:{legend:{position:'bottom',labels:{color:'#a1a1aa',font:{size:11},padding:12}}}}
});

// Auto-refresh cada 2 minutos
setTimeout(()=>location.reload(), 120000);
</script>

<?php
// Cargar datos completos para modal del dashboard
$modal_ids = array_column($ultimos_arr, 'id');
$modales_data = [];
if(!empty($modal_ids)){
    $ids_str = implode(',', array_map('intval', $modal_ids));
    $qm = $conn->query("SELECT * FROM reportes WHERE id IN ($ids_str)");
    while($rm = $qm->fetch_assoc()) $modales_data[$rm['id']] = $rm;
}
?>
<!-- MODALES DASHBOARD -->
<?php foreach($modales_data as $mid => $rm): ?>
<div id="dmod<?php echo $mid;?>" onclick="if(event.target===this)cerrarModalDash(<?php echo $mid;?>)" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;align-items:center;justify-content:center;padding:12px">
  <div style="background:var(--bg,#1e293b);border-radius:16px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;padding:20px;position:relative">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid rgba(255,255,255,.1);padding-bottom:12px">
      <div style="font-weight:700;font-size:16px">Reporte #<?php echo $mid;?> — <?php echo htmlspecialchars($rm['categoria']??'');?></div>
      <button onclick="cerrarModalDash(<?php echo $mid;?>)" style="background:none;border:none;color:inherit;font-size:20px;cursor:pointer">✕</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <div><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">RESIDENCIAL</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo htmlspecialchars($rm['residencial']??'—');?></div></div>
      <div><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">FECHA</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo $rm['fecha']?date('d/m/Y H:i',strtotime($rm['fecha'])):'—';?></div></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <div><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">ESTADO</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo htmlspecialchars($rm['estatus']??'—');?></div></div>
      <div><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">PRIORIDAD</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo htmlspecialchars($rm['prioridad']??'Normal');?></div></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <div><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">NIVEL URGENCIA</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo htmlspecialchars($rm['nivel_urgencia']??'—');?></div></div>
      <div><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">TIPO</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo htmlspecialchars($rm['tipo_reporte']??'Incidencia');?></div></div>
    </div>
    <?php if(!empty($rm['equipo'])): ?>
    <div style="margin-bottom:10px"><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">🔩 EQUIPO</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px;font-weight:600"><?php echo htmlspecialchars($rm['equipo']);?></div></div>
    <?php endif; ?>
    <div style="margin-bottom:10px"><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">TÉCNICO ASIGNADO</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo htmlspecialchars($rm['tecnico_asignado']??'Sin asignar');?></div></div>
    <div style="margin-bottom:10px"><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">REMITENTE</div><div style="background:rgba(255,255,255,.05);padding:6px 10px;border-radius:8px"><?php echo htmlspecialchars($rm['remitente']??'—');?></div></div>
    <div style="margin-bottom:10px"><div style="font-size:10px;color:#94a3b8;margin-bottom:3px">DESCRIPCIÓN COMPLETA</div><div style="background:rgba(255,255,255,.05);padding:8px 10px;border-radius:8px;max-height:180px;overflow-y:auto;white-space:pre-wrap;font-size:13px"><?php echo nl2br(htmlspecialchars($rm['descripcion']??'(sin texto)'));?></div></div>
    <?php if(!empty($rm['foto_url'])): ?>
    <div style="margin-bottom:10px"><div style="font-size:10px;color:#94a3b8;margin-bottom:6px">FOTO</div><a href="<?php echo htmlspecialchars($rm['foto_url']);?>" target="_blank"><img src="<?php echo htmlspecialchars($rm['foto_url']);?>" style="max-width:100%;border-radius:8px;max-height:200px;object-fit:cover"></a></div>
    <?php endif; ?>
    <div style="margin-top:16px;text-align:right">
      <a href="/reportes/?search=<?php echo $mid;?>" style="background:var(--accent,#3b82f6);color:#fff;padding:8px 18px;border-radius:8px;text-decoration:none;font-size:13px">✏️ Editar en panel</a>
    </div>
  </div>
</div>
<?php endforeach; ?>
<script>
function abrirModalDash(id){var m=document.getElementById('dmod'+id);if(m){m.style.display='flex';document.body.style.overflow='hidden';}}
function cerrarModalDash(id){var m=document.getElementById('dmod'+id);if(m){m.style.display='none';document.body.style.overflow='';}}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('[id^="dmod"]').forEach(function(m){m.style.display='none';});document.body.style.overflow='';}});
</script>

<script>
(function(){
  var saved = localStorage.getItem('commune_theme');
  var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  var theme = saved || (prefersDark ? 'dark' : 'light');
  if(theme === 'light') document.documentElement.setAttribute('data-theme','light');
  var btn = document.getElementById('themeBtn');
  if(btn) btn.textContent = theme === 'light' ? '🌙 Oscuro' : '☀️ Claro';
})();
function toggleTheme(){
  var cur = document.documentElement.getAttribute('data-theme');
  var next = cur === 'light' ? 'dark' : 'light';
  if(next === 'light') document.documentElement.setAttribute('data-theme','light');
  else document.documentElement.removeAttribute('data-theme');
  localStorage.setItem('commune_theme', next);
  var btn = document.getElementById('themeBtn');
  if(btn) btn.textContent = next === 'light' ? '🌙 Oscuro' : '☀️ Claro';
}
</script>
</body>
</html>
