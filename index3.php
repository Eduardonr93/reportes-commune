<?php
// ── DB ────────────────────────────────────────────────────
$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error) die("Error: ".$conn->connect_error);

// ── Roles ─────────────────────────────────────────────────
$roles = [
    'admin'     => ['label'=>'Administrador','perms'=>['edit','close','delete','config']],
    'tecnico'   => ['label'=>'Técnico',      'perms'=>['edit','close']],
    'seguridad' => ['label'=>'Seguridad',    'perms'=>[]],
];
$rol_activo = 'admin';
$perms = $roles[$rol_activo]['perms'];
function can($p){ global $perms; return in_array($p,$perms); }

// ── Columnas opcionales ───────────────────────────────────
@$conn->query("ALTER TABLE reportes ADD COLUMN IF NOT EXISTS prioridad VARCHAR(20) DEFAULT 'Normal'");
@$conn->query("ALTER TABLE reportes ADD COLUMN IF NOT EXISTS tecnico_asignado VARCHAR(100) DEFAULT ''");
@$conn->query("ALTER TABLE reportes ADD COLUMN IF NOT EXISTS tiempo_trabajado INT DEFAULT 0");
@$conn->query("ALTER TABLE reportes ADD COLUMN IF NOT EXISTS hora_inicio_trabajo DATETIME DEFAULT NULL");
@$conn->query("ALTER TABLE reportes ADD COLUMN IF NOT EXISTS residencial VARCHAR(50) DEFAULT ''");

// ── Config alertas ────────────────────────────────────────
$cfg_file = __DIR__.'/alertas_config.json';
$cfg_def  = ['whatsapp_number'=>'','email'=>'','push_enabled'=>false,'alert_nuevo'=>true,'alert_terminado'=>true,'alert_urgente'=>true];
$cfg      = file_exists($cfg_file) ? array_merge($cfg_def, json_decode(file_get_contents($cfg_file),true)??[]) : $cfg_def;

// ── Acciones POST ─────────────────────────────────────────
if(can('edit') && isset($_POST['actualizar_estado'])){
    $id    = (int)$_POST['id'];
    $est   = $conn->real_escape_string($_POST['nuevo_estado']);
    $cat   = $conn->real_escape_string($_POST['categoria_manual']);
    $prior = $conn->real_escape_string($_POST['prioridad']??'Normal');
    $tec   = $conn->real_escape_string($_POST['tecnico_asignado']??'');
    
    if(!empty($tec) && $est === 'En Proceso'){
        $conn->query("UPDATE reportes SET estatus='$est',categoria='$cat',prioridad='$prior',tecnico_asignado='$tec', hora_inicio_trabajo=NOW() WHERE id=$id");
    } else {
        $conn->query("UPDATE reportes SET estatus='$est',categoria='$cat',prioridad='$prior',tecnico_asignado='$tec' WHERE id=$id");
    }
    header("Location: ".strtok($_SERVER["REQUEST_URI"],'?')); exit;
}

if(can('close') && isset($_POST['finalizar'])){
    $id  = (int)$_POST['id'];
    $tec = $conn->real_escape_string($_POST['tecnico']);
    $obs = $conn->real_escape_string($_POST['observaciones']);
    $fin = date('Y-m-d H:i:s');
    $ruta = '';
    if(!empty($_FILES['evidencia']['name'])){
        $ext  = pathinfo($_FILES['evidencia']['name'],PATHINFO_EXTENSION);
        $nom  = 'fin_'.time().'_'.$id.'.'.$ext;
        $ruta = 'uploads/'.$nom;
        move_uploaded_file($_FILES['evidencia']['tmp_name'],$ruta);
    }
    
    $hora_inicio = $conn->query("SELECT hora_inicio_trabajo FROM reportes WHERE id=$id")->fetch_assoc();
    $tiempo_trabajado = 0;
    if($hora_inicio && $hora_inicio['hora_inicio_trabajo']){
        $start = new DateTime($hora_inicio['hora_inicio_trabajo']);
        $end = new DateTime($fin);
        $tiempo_trabajado = ($end->getTimestamp() - $start->getTimestamp()) / 60;
    }
    
    $stmt = $conn->prepare("UPDATE reportes SET estatus='Terminado',nombre_tecnico=?,observaciones_cierre=?,fecha_terminado=?,evidencia_cierre_url=?, tiempo_trabajado=? WHERE id=?");
    $stmt->bind_param("ssssii",$tec,$obs,$fin,$ruta,$tiempo_trabajado,$id);
    $stmt->execute();
    setcookie('alert_done','1',time()+10,'/');
    header("Location: ".strtok($_SERVER["REQUEST_URI"],'?')); exit;
}

// ── Filtros y vista ───────────────────────────────────────
$vista = $_GET['vista'] ?? 'cards';
$f_estado    = $conn->real_escape_string($_GET['f_estado']??'');
$f_cat       = $conn->real_escape_string($_GET['f_cat']??'');
$f_fecha_ini = $conn->real_escape_string($_GET['f_fecha_ini']??'');
$f_fecha_fin = $conn->real_escape_string($_GET['f_fecha_fin']??'');
$f_search    = $conn->real_escape_string($_GET['f_search']??'');
$f_prior     = $conn->real_escape_string($_GET['f_prior']??'');

$where = "WHERE 1=1";
if($f_estado)      $where .= " AND estatus='$f_estado'";
if($f_cat)         $where .= " AND categoria='$f_cat'";
if($f_prior)       $where .= " AND prioridad='$f_prior'";
if($f_fecha_ini)   $where .= " AND DATE(fecha)>='$f_fecha_ini'";
if($f_fecha_fin)   $where .= " AND DATE(fecha)<='$f_fecha_fin'";
if($f_search)      $where .= " AND (descripcion LIKE '%$f_search%' OR remitente LIKE '%$f_search%')";

// ── Stats ─────────────────────────────────────────────────
function q($c,$s){ $r=$c->query($s); return $r?$r->fetch_assoc():['c'=>0]; }
$stats = [
    'total'    => q($conn,"SELECT COUNT(*) c FROM reportes")['c'],
    'pendiente'=> q($conn,"SELECT COUNT(*) c FROM reportes WHERE estatus='Pendiente'")['c'],
    'proceso'  => q($conn,"SELECT COUNT(*) c FROM reportes WHERE estatus='En Proceso'")['c'],
    'terminado'=> q($conn,"SELECT COUNT(*) c FROM reportes WHERE estatus='Terminado'")['c'],
    'urgente'  => q($conn,"SELECT COUNT(*) c FROM reportes WHERE prioridad='Urgente' AND estatus!='Terminado'")['c'],
];

// ── Gráficas ──────────────────────────────────────────────
$semana = [];
for($i=6;$i>=0;$i--){
    $dia = date('Y-m-d',strtotime("-$i days"));
    $semana[] = ['label' => date('D',strtotime("-$i days")), 'count' => (int)q($conn,"SELECT COUNT(*) c FROM reportes WHERE DATE(fecha)='$dia'")['c']];
}
$maxVal = max(array_column($semana,'count')?:[1]);

$evolucion_mensual = [];
for($i=5;$i>=0;$i--){
    $mes = date('Y-m', strtotime("-$i months"));
    $evolucion_mensual[] = ['label' => date('M Y', strtotime("-$i months")), 'count' => q($conn,"SELECT COUNT(*) c FROM reportes WHERE DATE_FORMAT(fecha, '%Y-%m') = '$mes'")['c']];
}

$categorias_stats = [];
foreach(['CCTV','Redes','Perímetro','Accesos','Alarma','General'] as $cat){
    $cnt = q($conn,"SELECT COUNT(*) c FROM reportes WHERE categoria='$cat'")['c'];
    if($cnt > 0) $categorias_stats[] = ['label' => $cat, 'count' => $cnt];
}

// ── Residenciales ─────────────────────────────────────────
$residenciales = ['RIO','Cumbres','Via Cumbres','Aqua','Palmaris','Arbolada','Altai','Kyra'];
$cats = ['CCTV'=>'📷','Redes'=>'🌐','Perímetro'=>'⚡','Accesos'=>'🔑','Alarma'=>'🚨','General'=>'📋'];

// ── Carga de técnicos ─────────────────────────────────────
$carga_tecnicos = [];
$res_tec = $conn->query("SELECT tecnico_asignado, SUM(CASE WHEN estatus!='Terminado' THEN 1 ELSE 0 END) as pendientes, SUM(CASE WHEN prioridad='Urgente' AND estatus!='Terminado' THEN 1 ELSE 0 END) as urgentes, SUM(CASE WHEN estatus='Terminado' THEN 1 ELSE 0 END) as terminados, AVG(tiempo_trabajado) as tiempo_promedio FROM reportes WHERE tecnico_asignado != '' AND tecnico_asignado IS NOT NULL GROUP BY tecnico_asignado ORDER BY pendientes DESC");
if($res_tec) while($r=$res_tec->fetch_assoc()) $carga_tecnicos[] = $r;

// ── Basurero ──────────────────────────────────────────────
$tiene_basurero = false;
$total_descartados = 0;
$ultimos_descartados = [];
$check = $conn->query("SHOW TABLES LIKE 'reportes_descartados'");
if($check && $check->num_rows > 0){
    $tiene_basurero = true;
    $total_descartados = q($conn,"SELECT COUNT(*) c FROM reportes_descartados")['c'];
    $ult = $conn->query("SELECT id, remitente, mensaje, motivo, fecha FROM reportes_descartados ORDER BY id DESC LIMIT 3");
    if($ult) while($d=$ult->fetch_assoc()) $ultimos_descartados[] = $d;
}

// ── Paginación ────────────────────────────────────────────
$por_pagina = 20;
$pagina = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($pagina - 1) * $por_pagina;
$total_fil = q($conn,"SELECT COUNT(*) c FROM reportes $where")['c'];
$total_paginas = ceil($total_fil / $por_pagina);

$sql = "SELECT * FROM reportes $where ORDER BY
        CASE prioridad WHEN 'Urgente' THEN 0 ELSE 1 END,
        CASE estatus WHEN 'Pendiente' THEN 0 WHEN 'En Proceso' THEN 1 ELSE 2 END,
        fecha DESC
        LIMIT " . intval($por_pagina) . " OFFSET " . intval($offset);
$res = $conn->query($sql);

// ── Para endpoint AJAX de conteo urgentes ─────────────────
if(isset($_GET['get_urgent_count'])){
    header('Content-Type: application/json');
    echo json_encode($stats['urgente']);
    exit;
}

// ── Export CSV ────────────────────────────────────────────
if(isset($_GET['export']) && $_GET['export']==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reportes_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['ID','Fecha','Remitente','Descripcion','Categoria','Prioridad','Estatus','Tecnico','Residencial','Tiempo(min)','Fecha Cierre']);
    $r = $conn->query("SELECT * FROM reportes $where ORDER BY fecha DESC");
    while($row=$r->fetch_assoc())
        fputcsv($out,[$row['id'],$row['fecha'],$row['remitente'],$row['descripcion'],$row['categoria'],$row['prioridad']??'Normal',$row['estatus'],$row['tecnico_asignado']??'', $row['residencial']??'', $row['tiempo_trabajado']??'', $row['fecha_terminado']??'']);
    fclose($out); exit;
}

// Contador para notificación inicial
$initial_total = $stats['total'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<title>Commune — Gestión Cancún</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{
  --bg:#f1f5fb;--bg2:#ffffff;--bg3:#f8fafd;--bg4:#eef2f9;
  --border:#e2e8f4;--border2:#c7d4eb;
  --text:#0f172a;--text2:#475569;--text3:#94a3b8;
  --accent:#2563eb;--accent-l:#eff6ff;--accent-d:#1d4ed8;
  --purple:#7c3aed;--purple-l:#faf5ff;
  --danger:#dc2626;--danger-l:#fef2f2;
  --warn:#d97706;--warn-l:#fffbeb;
  --success:#16a34a;--success-l:#f0fdf4;
  --urgent:#e11d48;--urgent-l:#fff1f2;
  --sh:0 1px 3px rgba(15,23,42,.06),0 4px 12px rgba(15,23,42,.04);
  --sh-md:0 4px 16px rgba(15,23,42,.08),0 16px 40px rgba(15,23,42,.06);
  --r:12px;--r-sm:8px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 15px;min-height:58px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:100;flex-wrap:wrap}
.logo{width:34px;height:34px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700}
.brand-name{font-weight:700;font-size:14px}
.brand-sub{font-size:10px;color:var(--text3)}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pill{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:500;border:1px solid}
.pill-live{background:var(--success-l);border-color:#bbf7d0;color:var(--success)}
.pill-clock{background:var(--bg3);border-color:var(--border);color:var(--text2);font-family:'JetBrains Mono'}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--success);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}
.tb-btn{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:6px 11px;font-size:12px;color:var(--text2);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.tb-btn:hover{border-color:var(--accent);color:var(--accent)}
.layout{display:grid;grid-template-columns:230px 1fr;min-height:calc(100vh - 58px)}
.sidebar{background:var(--bg2);border-right:1px solid var(--border);padding:14px 0;position:sticky;top:58px;height:calc(100vh - 58px);overflow-y:auto}
.sb-sec{padding:0 10px;margin-bottom:18px}
.sb-lbl{font-size:9px;font-weight:700;color:var(--text3);text-transform:uppercase;margin-bottom:5px;padding:0 6px}
.sb-item{display:flex;align-items:center;justify-content:space-between;padding:7px 8px;border-radius:var(--r-sm);color:var(--text2);font-size:13px;text-decoration:none}
.sb-item:hover{background:var(--bg3);color:var(--text)}
.sb-item.on{background:var(--accent-l);color:var(--accent);font-weight:500}
.sb-item-l{display:flex;align-items:center;gap:7px}
.sb-n{font-size:11px;font-weight:600;padding:2px 6px;border-radius:20px}
.n-r{background:var(--danger-l);color:var(--danger)}
.n-b{background:var(--accent-l);color:var(--accent)}
.n-g{background:var(--success-l);color:var(--success)}
.n-w{background:var(--warn-l);color:var(--warn)}
.n-x{background:var(--bg4);color:var(--text3)}
.sb-div{height:1px;background:var(--border);margin:6px 10px}
.main{padding:20px;min-width:0}
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px}
.sc{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
.sc-n{font-family:'JetBrains Mono';font-size:26px;font-weight:600}
.sc-l{font-size:10px;color:var(--text3);margin-top:3px;text-transform:uppercase}
.sc-ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.s0 .sc-ico{background:#ede9fe} .s1 .sc-ico{background:var(--danger-l)} .s2 .sc-ico{background:var(--warn-l)} .s3 .sc-ico{background:var(--success-l)} .s4 .sc-ico{background:var(--urgent-l)}
.graficas-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.grafica-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:16px}
.grafica-titulo{font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;margin-bottom:12px}
canvas{max-height:200px;width:100%}
.tabla-vista{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);overflow-x:auto;margin-bottom:14px}
.tabla-vista table{width:100%;border-collapse:collapse;font-size:12px}
.tabla-vista th,.tabla-vista td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.tabla-vista th{background:var(--bg3);font-weight:600}
.tabla-vista tr:hover{background:var(--bg3)}
.badge-estado{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px}
.badge-pend{background:var(--danger-l);color:var(--danger)}
.badge-proc{background:var(--warn-l);color:var(--warn)}
.badge-done{background:var(--success-l);color:var(--success)}
.cg{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:12px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.card.urg{border-left:3px solid var(--urgent)}
.ct{padding:11px 13px 7px;display:flex;justify-content:space-between}
.cbadges{display:flex;gap:4px;flex-wrap:wrap}
.badge{padding:2px 8px;border-radius:20px;font-size:11px}
.b-cat{background:var(--bg4);color:var(--text2)}
.b-pend{background:var(--danger-l);color:var(--danger)}
.b-proc{background:var(--warn-l);color:var(--warn)}
.b-done{background:var(--success-l);color:var(--success)}
.ctime{font-family:'JetBrains Mono';font-size:10px;color:var(--text3)}
.cb{padding:2px 13px 10px}
.cname{font-size:13px;font-weight:600;color:var(--accent)}
.cdesc{font-size:12px;color:var(--text2);line-height:1.55;cursor:pointer;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.cimg{width:100%;height:135px;object-fit:cover;cursor:pointer}
.csla{padding:5px 13px;font-size:11px;background:var(--bg3);border-top:1px solid var(--border);display:flex;align-items:center;gap:4px}
.sla-verde{background:#f0fdf4;border-top-color:#bbf7d0}
.sla-naranja{background:#fffbeb;border-top-color:#fde68a}
.sla-rojo{background:#fef2f2;border-top-color:#fecaca}
.sla-dot{width:7px;height:7px;border-radius:50%;display:inline-block;margin-left:5px}
.cf{padding:10px 13px;border-top:1px solid var(--border)}
.cfr{display:grid;grid-template-columns:1fr auto auto auto;gap:5px;margin-bottom:7px}
.fs,.fi,.fta{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:6px 7px;font-size:12px;width:100%}
.bok{background:var(--accent);color:#fff;border:none;border-radius:6px;padding:6px 11px;cursor:pointer}
.bclose{background:var(--success-l);border:1px solid #bbf7d0;color:var(--success);border-radius:var(--r-sm);padding:7px 11px;cursor:pointer;width:100%;margin-top:3px}
.coll{display:none;background:var(--bg3);border-radius:var(--r-sm);padding:11px;margin-top:7px}
.coll.open{display:block}
.cbox{background:var(--success-l);border:1px solid #bbf7d0;border-radius:var(--r-sm);padding:8px 11px;font-size:12px}
.ov{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center}
.ov.open{display:flex}
.modal{background:var(--bg2);border-radius:16px;width:100%;max-width:660px;max-height:90vh;overflow-y:auto}
.mh{padding:15px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;position:sticky;top:0;background:var(--bg2)}
.mb{padding:18px}
.fl{font-size:10px;font-weight:600;color:var(--text3);text-transform:uppercase}
.fv{font-size:13px;background:var(--bg3);border-radius:var(--r-sm);padding:9px 11px;margin-bottom:12px}
.mig{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.mig img{width:100%;border-radius:var(--r-sm);border:1px solid var(--border)}
.toast{position:fixed;bottom:20px;right:20px;background:var(--text);color:#fff;border-radius:10px;padding:11px 16px;z-index:300;opacity:0;transition:.2s;pointer-events:none}
.toast.on{opacity:1}
.empty{text-align:center;padding:56px 20px;color:var(--text3)}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:24px}
.page{padding:6px 12px;background:var(--bg2);border:1px solid var(--border);border-radius:6px;color:var(--text2);text-decoration:none}
.page.active{background:var(--accent);color:#fff}
.toolbar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:12px 14px;margin-bottom:14px}
.trow{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.sw{flex:1;min-width:160px;position:relative}
.sw input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 11px 8px 34px}
.si{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text3)}
.fsel{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 9px}
.btn{padding:8px 13px;border-radius:var(--r-sm);font-weight:500;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.btn-p{background:var(--accent);color:#fff}
.btn-s{background:var(--bg3);border:1px solid var(--border);color:var(--text2)}
.extra-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.extra-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:16px}
.ph{display:flex;justify-content:space-between;margin-bottom:12px}
.pt{font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase}
.tec-item{display:flex;align-items:center;justify-content:space-between;padding:8px;background:var(--bg3);border-radius:8px;margin-bottom:8px}
.tec-name{font-size:12px;font-weight:600}
.tec-pend{font-family:'JetBrains Mono';font-size:13px;font-weight:700;color:var(--danger)}
.tec-done{font-size:11px;color:var(--success)}
.desc-item{padding:6px 8px;background:var(--bg3);border-radius:6px;margin-bottom:5px}
.desc-text{font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.desc-badge{background:var(--bg4);padding:1px 5px;border-radius:4px}
.tiempo-cronometro{font-size:11px;color:var(--accent);margin-top:4px}
@media(max-width:960px){.layout{grid-template-columns:1fr}.sidebar{display:none}.stats-row{grid-template-columns:repeat(3,1fr)}.graficas-grid{grid-template-columns:1fr}}
@media(max-width:580px){.stats-row{grid-template-columns:repeat(2,1fr)}.pill-clock{display:none}.main{padding:12px}}
</style>
</head>
<body>

<header class="topbar">
    <div class="logo">C</div>
    <div class="brand"><div class="brand-name">Commune</div><div class="brand-sub">Gestión Cancún</div></div>
    <div class="tb-right">
        <span class="pill pill-live"><span class="live-dot"></span>En vivo</span>
        <span class="pill pill-clock" id="clock">--:--</span>
        <a href="?vista=cards&<?php echo http_build_query(array_merge($_GET,['vista'=>'cards'])); ?>" class="tb-btn <?php echo $vista==='cards'?'on':'';?>">📇 Cards</a>
        <a href="?vista=tabla&<?php echo http_build_query(array_merge($_GET,['vista'=>'tabla'])); ?>" class="tb-btn <?php echo $vista==='tabla'?'on':'';?>">📋 Tabla</a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'csv']));?>" class="tb-btn">⬇ CSV</a>
		<button class="tb-btn" onclick="document.getElementById('comunicados-widget').scrollIntoView({behavior:'smooth'})">
    💬 Comunicados
</button>
    </div>
</header>

<div class="layout">
<aside class="sidebar">
    <div class="sb-sec">
        <div class="sb-lbl">Estado</div>
        <a href="?<?php echo http_build_query(array_merge($_GET,['f_estado'=>'','f_prior'=>'','p'=>''])); ?>" class="sb-item <?php if(!$f_estado&&!$f_prior) echo 'on';?>">
            <span class="sb-item-l">📋 Todos</span>
            <span class="sb-n n-b"><?php echo $stats['total'];?></span>
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['f_estado'=>'Pendiente','p'=>''])); ?>" class="sb-item <?php if($f_estado==='Pendiente') echo 'on';?>">
            <span class="sb-item-l">🔴 Pendientes</span>
            <span class="sb-n n-r"><?php echo $stats['pendiente'];?></span>
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['f_estado'=>'En Proceso','p'=>''])); ?>" class="sb-item <?php if($f_estado==='En Proceso') echo 'on';?>">
            <span class="sb-item-l">🟡 En proceso</span>
            <span class="sb-n n-w"><?php echo $stats['proceso'];?></span>
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['f_estado'=>'Terminado','p'=>''])); ?>" class="sb-item <?php if($f_estado==='Terminado') echo 'on';?>">
            <span class="sb-item-l">🟢 Terminados</span>
            <span class="sb-n n-g"><?php echo $stats['terminado'];?></span>
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['f_prior'=>'Urgente','p'=>''])); ?>" class="sb-item <?php if($f_prior==='Urgente') echo 'on';?>">
            <span class="sb-item-l">🚨 Urgentes</span>
            <?php if($stats['urgente']>0): ?><span class="sb-n n-r"><?php echo $stats['urgente'];?></span><?php endif;?>
        </a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-sec">
        <div class="sb-lbl">Categoría</div>
        <?php foreach($cats as $c=>$ico):
            $cnt=q($conn,"SELECT COUNT(*) c FROM reportes WHERE categoria='$c' AND estatus!='Terminado'")['c'];
        ?>
        <a href="?<?php echo http_build_query(array_merge($_GET,['f_cat'=>$c,'p'=>''])); ?>" class="sb-item <?php if($f_cat===$c) echo 'on';?>">
            <span class="sb-item-l"><?php echo $ico.' '.$c;?></span>
            <?php if($cnt>0): ?><span class="sb-n n-x"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
    <div class="sb-div"></div>
    <div class="sb-sec">
        <div class="sb-lbl">Residencial</div>
        <?php foreach($residenciales as $resid):
            $r=$conn->real_escape_string($resid);
            $cnt=q($conn,"SELECT COUNT(*) c FROM reportes WHERE descripcion LIKE '%$r%' AND estatus!='Terminado'")['c'];
        ?>
        <a href="?<?php echo http_build_query(array_merge($_GET,['f_search'=>$resid,'p'=>''])); ?>" class="sb-item <?php if($f_search===$resid) echo 'on';?>">
            <span class="sb-item-l"><?php echo $resid;?></span>
            <?php if($cnt>0): ?><span class="sb-n n-x"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
</aside>

<main class="main">

<div class="stats-row">
    <?php $sdata=[['n'=>$stats['total'],'l'=>'Total','i'=>'📊','cls'=>'s0'],['n'=>$stats['pendiente'],'l'=>'Pendientes','i'=>'⏳','cls'=>'s1'],['n'=>$stats['proceso'],'l'=>'En proceso','i'=>'🔧','cls'=>'s2'],['n'=>$stats['terminado'],'l'=>'Terminados','i'=>'✅','cls'=>'s3'],['n'=>$stats['urgente'],'l'=>'Urgentes','i'=>'🚨','cls'=>'s4']]; ?>
    <?php foreach($sdata as $s): ?>
    <div class="sc <?php echo $s['cls'];?>">
        <div><div class="sc-n"><?php echo $s['n'];?></div><div class="sc-l"><?php echo $s['l'];?></div></div>
        <div class="sc-ico"><?php echo $s['i'];?></div>
    </div>
    <?php endforeach;?>
</div>

<div class="graficas-grid">
    <div class="grafica-card">
        <div class="grafica-titulo">📊 Actividad últimos 7 días</div>
        <canvas id="chartSemanal" height="150"></canvas>
    </div>
    <div class="grafica-card">
        <div class="grafica-titulo">🥧 Reportes por categoría</div>
        <canvas id="chartCategorias" height="150"></canvas>
    </div>
    <div class="grafica-card" style="grid-column:span 2">
        <div class="grafica-titulo">📈 Evolución mensual (últimos 6 meses)</div>
        <canvas id="chartEvolucion" height="100"></canvas>
    </div>
</div>

<div class="extra-grid">
    <div class="extra-card">
        <div class="ph"><div class="pt">👷 Carga de trabajo</div></div>
        <?php if(!empty($carga_tecnicos)): ?>
            <?php foreach($carga_tecnicos as $tec): ?>
            <div class="tec-item">
                <div class="tec-name"><?php echo htmlspecialchars($tec['tecnico_asignado']);?></div>
                <div><span class="tec-pend"><?php echo $tec['pendientes'];?> pend</span> <?php if($tec['urgentes']>0): ?>🚨<?php echo $tec['urgentes'];?><?php endif;?> <span class="tec-done">✓<?php echo $tec['terminados'];?></span></div>
            </div>
            <?php endforeach;?>
        <?php else: ?>
            <div style="text-align:center;padding:20px;color:var(--text3)">Sin técnicos asignados</div>
        <?php endif; ?>
    </div>
    <?php if($tiene_basurero): ?>
    <div class="extra-card">
        <div class="ph"><div class="pt">🗑️ Basurero</div><a href="basurero.php" style="font-size:11px;color:var(--accent)">Ver todos →</a></div>
        <div style="text-align:center;padding:10px"><div style="font-size:32px;font-weight:700"><?php echo $total_descartados;?></div><div style="font-size:11px;color:var(--text3)">Mensajes descartados</div></div>
        <?php if(!empty($ultimos_descartados)): ?>
            <?php foreach($ultimos_descartados as $d): ?>
            <div class="desc-item"><div class="desc-text"><?php echo htmlspecialchars(substr($d['mensaje']??'',0,60));?></div><div><span class="desc-badge"><?php echo $d['motivo'];?></span></div></div>
            <?php endforeach;?>
        <?php endif;?>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================================= -->
<!-- SECCIÓN DE CONTROL TÉCNICO (Solo visible para admin) -->
<!-- ========================================================= -->
<?php if(can('admin')): ?>
<div class="extra-card" style="grid-column: span 2; margin-top: 14px;">
    <div class="ph">
        <div class="pt">🛠️ CONTROL TÉCNICO</div>
        <div style="display: flex; gap: 8px;">
            <button class="tb-btn" onclick="mostrarTabTecnico('compras')">📦 Requerimientos</button>
            <button class="tb-btn" onclick="mostrarTabTecnico('instalados')">✅ Instalados</button>
            <button class="tb-btn" onclick="mostrarTabTecnico('mantenimientos')">🔧 Mantenimientos</button>
        </div>
    </div>
    <div id="panel-tecnico" style="margin-top: 12px;">
        <div style="text-align:center; padding:40px; color:var(--text3)">
            <div class="empty-ico">🛠️</div>
            <div>Selecciona una categoría</div>
        </div>
    </div>
</div>
<?php endif; ?>
	
<!-- Widget de Comunicados Internos -->
<div class="extra-card" style="grid-column: span 2; margin-top: 14px;">
    <div class="ph">
        <div class="pt">💬 COMUNICADOS INTERNOS</div>
        <div style="display: flex; gap: 8px;">
            <span class="pill" style="background:var(--accent-l);color:var(--accent)" id="comunicados-contador">0 nuevos</span>
            <button class="tb-btn" onclick="marcarComunicadosLeidos()" style="padding:4px 8px">✓ Marcar todos como leídos</button>
            <button class="tb-btn" onclick="cargarComunicados()" style="padding:4px 8px">🔄 Actualizar</button>
        </div>
    </div>
    <div id="comunicados-lista" style="max-height: 400px; overflow-y: auto; margin-top: 12px;">
        <div style="text-align:center; padding:40px; color:var(--text3)">
            <div class="empty-ico">💬</div>
            <div>Cargando comunicados...</div>
        </div>
    </div>
</div>
	
<form method="GET" id="ff">
<input type="hidden" name="vista" value="<?php echo $vista;?>">
<div class="toolbar">
    <div class="trow">
        <div class="sw"><span class="si">🔍</span><input type="text" name="f_search" placeholder="Buscar..." value="<?php echo htmlspecialchars($f_search);?>"></div>
        <select name="f_estado" class="fsel" onchange="this.form.submit()"><option value="">Estado</option><option value="Pendiente" <?php if($f_estado==='Pendiente') echo 'selected';?>>Pendiente</option><option value="En Proceso" <?php if($f_estado==='En Proceso') echo 'selected';?>>En proceso</option><option value="Terminado" <?php if($f_estado==='Terminado') echo 'selected';?>>Terminado</option></select>
        <select name="f_cat" class="fsel" onchange="this.form.submit()"><option value="">Categoría</option><?php foreach($cats as $c=>$ico): ?><option value="<?php echo $c;?>" <?php if($f_cat===$c) echo 'selected';?>><?php echo $c;?></option><?php endforeach;?></select>
        <input type="date" name="f_fecha_ini" class="fsel" value="<?php echo $f_fecha_ini;?>" onchange="this.form.submit()">
        <input type="date" name="f_fecha_fin" class="fsel" value="<?php echo $f_fecha_fin;?>" onchange="this.form.submit()">
        <button type="submit" class="btn btn-p">Buscar</button>
        <a href="?vista=<?php echo $vista;?>" class="btn btn-s">Limpiar</a>
        <span class="rc-info"><?php echo $total_fil;?> resultados</span>
    </div>
</div>
</form>

<?php if($vista === 'tabla'): ?>
<div class="tabla-vista">
<table><thead><tr><th>ID</th><th>Fecha</th><th>Remitente</th><th>Descripción</th><th>Categoría</th><th>Prioridad</th><th>Estado</th><th>Técnico</th><th></th></tr></thead>
<tbody>
<?php if($res && $res->num_rows > 0){ while($row=$res->fetch_assoc()){ $urg=($row['prioridad']??'Normal')==='Urgente'; echo '<tr'.($urg?' class="urgente"':'').'><td>'.$row['id'].'<td>'.date('d/m H:i',strtotime($row['fecha'])).'<td>'.htmlspecialchars($row['remitente']).'<td>'.htmlspecialchars(substr($row['descripcion']??'',0,50)).'<td>'.$row['categoria'].'<td>'.($urg?'🚨 Urgente':'Normal').'<td><span class="badge-estado badge-'.($row['estatus']==='Pendiente'?'pend':($row['estatus']==='En Proceso'?'proc':'done')).'">'.$row['estatus'].'</span><td>'.htmlspecialchars($row['tecnico_asignado']??'—').'<td><button class="btn-s" style="padding:4px 8px" onclick="openM('.$row['id'].')">Ver</button></tr>'; } } else { echo '<tr><td colspan="9" style="text-align:center">Sin resultados</tr>'; } ?>
</tbody></table>
</div>
<?php else: ?>
<div class="cg">
<?php if($res === false){ echo '<div class="empty">Error en consulta</div>'; } elseif($res->num_rows === 0){ echo '<div class="empty">📭 Sin resultados</div>'; } else { while($row=$res->fetch_assoc()): $id=(int)$row['id']; $urg=($row['prioridad']??'Normal')==='Urgente'; $ec=$row['estatus']==='Pendiente'?'b-pend':($row['estatus']==='En Proceso'?'b-proc':'b-done'); $sla=''; $sla_color=''; if($row['fecha']){ $fin=$row['fecha_terminado']??date('Y-m-d H:i:s'); $horas=round((strtotime($fin)-strtotime($row['fecha']))/3600,1); if($row['estatus']==='Terminado'){ if($horas>=24){ $dias=round($horas/24,1); $sla="✓ Resuelto en {$dias} días"; } else { $sla="✓ Resuelto en {$horas}h"; } } else { if($horas>=24){ $dias=round($horas/24,1); $sla="Abierto hace {$dias} días"; } else { $sla="Abierto hace {$horas}h"; } $es_urg=($row['prioridad']??'Normal')==='Urgente'; if($es_urg){ if($horas<2) $sla_color='sla-verde'; elseif($horas<4) $sla_color='sla-naranja'; else $sla_color='sla-rojo'; } else { if($horas<4) $sla_color='sla-verde'; elseif($horas<12) $sla_color='sla-naranja'; else $sla_color='sla-rojo'; } } } ?>
<div class="card <?php echo $urg?'urg':'';?>" id="c<?php echo $id;?>">
    <div class="ct"><div class="cbadges"><span class="badge b-cat"><?php echo htmlspecialchars($row['categoria']);?></span><span class="badge <?php echo $ec;?>"><?php echo $row['estatus'];?></span><?php if($urg): ?><span class="badge b-urg">🚨 Urgente</span><?php endif;?></div><div class="ctime"><?php echo $row['fecha']?date('d/m H:i',strtotime($row['fecha'])):'—';?></div></div>
    <div class="cb"><div class="cname"><?php echo htmlspecialchars($row['remitente']?:'Sin remitente');?></div><?php if(!empty($row['tecnico_asignado'])): ?><div class="ctec">👷 <?php echo htmlspecialchars($row['tecnico_asignado']);?></div><?php endif;?><div class="cdesc" onclick="openM(<?php echo $id;?>)"><?php echo nl2br(htmlspecialchars($row['descripcion']?:'(sin texto)'));?></div></div>
    <?php if(!empty($row['foto_url'])): ?><img src="<?php echo htmlspecialchars($row['foto_url']);?>" class="cimg" onclick="openM(<?php echo $id;?>)" loading="lazy"><?php endif;?>
    <?php if($sla): ?><div class="csla <?php echo $sla_color;?>"><span><?php echo $sla;?></span><?php if($sla_color): ?><span class="sla-dot"></span><?php endif;?></div><?php endif;?>
    <?php if(can('edit')): ?>
    <div class="cf"><form method="POST" class="reporte-form" data-id="<?php echo $id;?>"><input type="hidden" name="id" value="<?php echo $id;?>"><div class="cfr"><select name="nuevo_estado" class="fs"><option value="Pendiente" <?php if($row['estatus']==='Pendiente') echo 'selected';?>>🔴 Pendiente</option><option value="En Proceso" <?php if($row['estatus']==='En Proceso') echo 'selected';?>>🟡 En proceso</option><option value="Terminado" <?php if($row['estatus']==='Terminado') echo 'selected';?>>🟢 Terminado</option></select><select name="categoria_manual" class="fs"><?php foreach($cats as $c=>$ico): ?><option value="<?php echo $c;?>" <?php if($row['categoria']===$c) echo 'selected';?>><?php echo $c;?></option><?php endforeach;?></select><select name="prioridad" class="fs"><option value="Normal" <?php if(($row['prioridad']??'Normal')==='Normal') echo 'selected';?>>Normal</option><option value="Urgente" <?php if(($row['prioridad']??'Normal')==='Urgente') echo 'selected';?>>🚨 Urgente</option></select><button type="submit" name="actualizar_estado" class="bok">OK</button></div><input type="text" name="tecnico_asignado" class="fi" placeholder="Asignar técnico..." value="<?php echo htmlspecialchars($row['tecnico_asignado']??'');?>"></form>
        <?php if(can('close') && $row['estatus']!=='Terminado'): ?>
        <button class="bclose" onclick="toggleC(<?php echo $id;?>)">✅ Documentar cierre</button>
        <div class="coll" id="col<?php echo $id;?>"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="id" value="<?php echo $id;?>"><select class="fi" onchange="document.querySelector('#obs<?php echo $id;?>').value = this.value"><option value="">📋 Plantilla...</option><option value="Se realizó mantenimiento preventivo, equipo funcionando correctamente.">🔧 Mantenimiento preventivo</option><option value="Se reemplazó el componente dañado, sistema operativo.">🔄 Reemplazo de componente</option><option value="Se reinició el sistema, todo ok.">🖥️ Reinicio</option><option value="Se actualizó firmware, pendiente monitoreo.">📦 Actualización</option><option value="Requiere refacción, pendiente cotización.">⏳ Esperando refacción</option></select><input type="text" name="tecnico" class="fi" placeholder="Técnico responsable" required><textarea name="observaciones" id="obs<?php echo $id;?>" class="fta" placeholder="Trabajos realizados..."></textarea><div class="tiempo-cronometro">⏱️ Tiempo trabajado: <span id="crono-tiempo-<?php echo $id;?>">0</span> minutos</div><input type="file" name="evidencia" class="fi" accept="image/*"><button type="submit" name="finalizar" class="bok" style="width:100%;padding:8px">Cerrar reporte</button></form></div>
        <?php elseif($row['estatus']==='Terminado'): ?>
        <div class="cbox">✅ <strong><?php echo htmlspecialchars($row['nombre_tecnico']??'—');?></strong> <?php if(!empty($row['observaciones_cierre'])): ?>— <?php echo htmlspecialchars($row['observaciones_cierre']);?><?php endif;?> <?php if($row['tiempo_trabajado']): ?>(⏱️ <?php echo round($row['tiempo_trabajado']);?> min)<?php endif;?></div>
        <?php endif;?>
    </div>
    <?php endif;?>
</div>
<div class="ov" id="m<?php echo $id;?>" onclick="if(event.target===this)closeM(<?php echo $id;?>)"><div class="modal"><div class="mh"><div class="mt">Reporte #<?php echo $id;?></div><button class="mx" onclick="closeM(<?php echo $id;?>)">✕</button></div><div class="mb"><div><div class="fl">Remitente</div><div class="fv"><?php echo htmlspecialchars($row['remitente']??'—');?></div></div><div><div class="fl">Fecha</div><div class="fv"><?php echo $row['fecha']?date('d/m/Y H:i',strtotime($row['fecha'])):'—';?></div></div><div class="fl">Descripción</div><div class="fv"><?php echo nl2br(htmlspecialchars($row['descripcion']??'(sin texto)'));?></div><?php if($row['estatus']==='Terminado' && !empty($row['observaciones_cierre'])): ?><div class="fl">Cierre</div><div class="fv" style="background:var(--success-l);color:var(--success)"><?php echo htmlspecialchars($row['observaciones_cierre']);?></div><?php endif;?><?php if(!empty($row['foto_url'])||!empty($row['evidencia_cierre_url'])): ?><div class="mig"><?php if(!empty($row['foto_url'])): ?><a href="<?php echo htmlspecialchars($row['foto_url']);?>" target="_blank"><img src="<?php echo htmlspecialchars($row['foto_url']);?>"></a><?php endif;?><?php if(!empty($row['evidencia_cierre_url'])): ?><a href="<?php echo htmlspecialchars($row['evidencia_cierre_url']);?>" target="_blank"><img src="<?php echo htmlspecialchars($row['evidencia_cierre_url']);?>"></a><?php endif;?></div><?php endif;?></div></div></div>
<?php endwhile; } ?>
</div>
<?php endif; ?>

<?php if($total_paginas > 1): ?>
<div class="pagination">
    <?php for($i=1; $i<=$total_paginas; $i++): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['p'=>$i])); ?>" class="page <?php echo $i===$pagina?'active':'';?>"><?php echo $i;?></a>
    <?php endfor;?>
</div>
<?php endif; ?>

</main>
</div>

<div class="toast" id="toast"></div>

<script>
// Gráficas
const semanaLabels = <?php echo json_encode(array_column($semana,'label')); ?>;
const semanaData = <?php echo json_encode(array_column($semana,'count')); ?>;
new Chart(document.getElementById('chartSemanal'), { type:'line', data:{ labels:semanaLabels, datasets:[{ label:'Reportes', data:semanaData, borderColor:'#2563eb', tension:0.3 }] }, options:{ responsive:true, maintainAspectRatio:true } });
const catLabels = <?php echo json_encode(array_column($categorias_stats,'label')); ?>;
const catData = <?php echo json_encode(array_column($categorias_stats,'count')); ?>;
if(catLabels.length) new Chart(document.getElementById('chartCategorias'), { type:'pie', data:{ labels:catLabels, datasets:[{ data:catData, backgroundColor:['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#94a3b8'] }] }, options:{ responsive:true, maintainAspectRatio:true } });
const evoLabels = <?php echo json_encode(array_column($evolucion_mensual,'label')); ?>;
const evoData = <?php echo json_encode(array_column($evolucion_mensual,'count')); ?>;
new Chart(document.getElementById('chartEvolucion'), { type:'bar', data:{ labels:evoLabels, datasets:[{ label:'Reportes', data:evoData, backgroundColor:'#93c5fd' }] }, options:{ responsive:true, maintainAspectRatio:true } });

// Reloj
function tick(){ document.getElementById('clock').textContent=new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}); }
setInterval(tick,1000); tick();

// Auto-refresh solo cuando no hay modales abiertos (cada 60 segundos, menos agresivo)
let refreshInterval = setInterval(()=>{ if(!document.querySelector('.ov.open')) location.reload(); }, 60000);

function openM(id){ document.getElementById('m'+id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeM(id){ document.getElementById('m'+id).classList.remove('open'); document.body.style.overflow=''; }
function toggleC(id){ document.getElementById('col'+id).classList.toggle('open'); }
function toast(msg,dur=4000){ const t=document.getElementById('toast'); t.textContent=msg; t.classList.add('on'); setTimeout(()=>t.classList.remove('on'),dur); }

// Cronómetros
document.querySelectorAll('.reporte-form').forEach(form=>{ const estadoSelect=form.querySelector('select[name="nuevo_estado"]'); const id=form.dataset.id; if(estadoSelect && estadoSelect.value==='En Proceso'){ let start=Date.now(); setInterval(()=>{ const span=document.getElementById(`crono-tiempo-${id}`); if(span) span.textContent=Math.floor((Date.now()-start)/60000); },60000); } });

// Notificación push solo si se acaba de cerrar un reporte
<?php if(isset($_COOKIE['alert_done'])): setcookie('alert_done','',time()-1,'/'); ?>
toast('✅ Reporte cerrado correctamente');
<?php endif; ?>

// Contador de urgentes en título
const urgentes = <?php echo $stats['urgente'];?>;
if(urgentes>0) document.title=`(${urgentes}) Commune — Gestión Cancún`;

// Service Worker
if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(e=>console.log('SW error:',e));

// Polling SEGURO (solo notifica, NO recarga)
let lastCount = <?php echo $initial_total; ?>;
setInterval(() => {
    fetch('verificar_nuevos_reports.php')
        .then(r => r.json())
        .then(data => {
            if(data.total > lastCount){
                const nuevos = data.total - lastCount;
                toast(`📢 ${nuevos} nuevo(s) reporte(s) recibido(s)`);
                if('Notification' in window && Notification.permission === 'granted'){
                    new Notification('Commune', { body: `${nuevos} nuevo(s) reporte(s)`, icon: 'icons/icon-192.png' });
                }
                lastCount = data.total;
                // Solo actualizar el contador de urgentes sin recargar
                fetch('?get_urgent_count=1').then(r=>r.json()).then(urg=>{ if(urg>0) document.title=`(${urg}) Commune`; else document.title='Commune — Gestión Cancún'; });
            }
        });
}, 15000); // Cada 15 segundos

// Solicitar permisos de notificación
if('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied'){
    setTimeout(() => Notification.requestPermission(), 5000);
}
	// ── COMUNICADOS INTERNOS ─────────────────────────────────
let ultimoComunicadoId = 0;

async function cargarComunicados() {
    try {
        const response = await fetch('obtener_comunicados.php?limite=50');
        const data = await response.json();
        
        if (data.error) {
            console.error(data.error);
            return;
        }
        
        // Actualizar contador de no leídos
        const noLeidos = data.filter(c => !c.leido).length;
        const contadorSpan = document.getElementById('comunicados-contador');
        if (contadorSpan) {
            contadorSpan.innerHTML = `${noLeidos} nuevo${noLeidos !== 1 ? 's' : ''}`;
            contadorSpan.style.background = noLeidos > 0 ? 'var(--danger-l)' : 'var(--accent-l)';
            contadorSpan.style.color = noLeidos > 0 ? 'var(--danger)' : 'var(--accent)';
        }
        
        // Guardar el ID más reciente para polling
        if (data.length > 0) {
            ultimoComunicadoId = data[0].id;
        }
        
        // Generar HTML
        const listaDiv = document.getElementById('comunicados-lista');
        if (!listaDiv) return;
        
        if (data.length === 0) {
            listaDiv.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text3)"><div class="empty-ico">💬</div><div>No hay comunicados internos</div></div>';
            return;
        }
        
        let html = '<div style="display:flex; flex-direction:column; gap:8px;">';
        for (const c of data) {
            const noLeidoClass = !c.leido ? 'style="border-left: 3px solid var(--danger); background: var(--danger-l);"' : '';
            html += `
                <div class="desc-item" ${noLeidoClass}>
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:4px;">
                        <strong style="color:var(--accent)">👤 ${escapeHtml(c.remitente)}</strong>
                        <small style="color:var(--text3); font-family:monospace">${c.fecha}</small>
                    </div>
                    <div style="margin-top:6px; color:var(--text2); font-size:12px; line-height:1.5;">
                        ${c.mensaje}
                    </div>
                    ${c.tiene_media && c.foto_url ? `
                        <div style="margin-top:6px;">
                            <a href="${c.foto_url}" target="_blank" style="color:var(--accent); font-size:11px;">📷 Ver imagen adjunta</a>
                        </div>
                    ` : ''}
                    ${!c.leido ? `
                        <div style="margin-top:6px; font-size:10px; color:var(--danger);">🔴 Nuevo</div>
                    ` : ''}
                </div>
            `;
        }
        html += '</div>';
        listaDiv.innerHTML = html;
        
    } catch (error) {
        console.error('Error cargando comunicados:', error);
    }
}

// Función para marcar todos como leídos
async function marcarComunicadosLeidos() {
    try {
        const response = await fetch('obtener_comunicados.php?marcar_leidos=1');
        toast('✅ Comunicados marcados como leídos');
        cargarComunicados(); // Recargar para actualizar la interfaz
    } catch (error) {
        console.error('Error marcando leídos:', error);
    }
}

// Polling para nuevos comunicados (cada 15 segundos)
let pollingInterval;
function iniciarPollingComunicados() {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(() => {
        cargarComunicados();
    }, 15000);
}

// Helper para escapar HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Cargar comunicados al inicio
document.addEventListener('DOMContentLoaded', () => {
    cargarComunicados();
    iniciarPollingComunicados();
});

// ── PANEL DE CONTROL TÉCNICO ──────────────────────────────
let tabActiva = 'compras';

async function mostrarTabTecnico(tab) {
    tabActiva = tab;
    const panel = document.getElementById('panel-tecnico');
    if (!panel) return;
    
    panel.innerHTML = '<div style="text-align:center; padding:40px;"><div class="empty-ico">⏳</div><div>Cargando...</div></div>';
    
    if (tab === 'compras') {
        await cargarComprasPendientes();
    } else if (tab === 'instalados') {
        await cargarInstalados();
    } else if (tab === 'mantenimientos') {
        await cargarMantenimientos();
    }
}

async function cargarComprasPendientes() {
    try {
        const response = await fetch('obtener_compras.php?estatus=pendiente_compra');
        const data = await response.json();
        const panel = document.getElementById('panel-tecnico');
        
        if (data.length === 0) {
            panel.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text3)"><div class="empty-ico">📦</div><div>No hay requerimientos de compra pendientes</div></div>';
            return;
        }
        
        let html = '<div style="display:flex; flex-direction:column; gap:8px;">';
        for (const item of data) {
            html += `
                <div class="tec-item" style="flex-wrap:wrap;">
                    <div style="flex:1; min-width:150px;">
                        <div><strong>📦 ${escapeHtml(item.material_equipo)}</strong> x${item.cantidad}</div>
                        <div style="font-size:11px; color:var(--text3)">📍 ${escapeHtml(item.residencial)} | 🧑 ${escapeHtml(item.solicitado_por)}</div>
                        <div style="font-size:10px; color:var(--text3)">📅 ${item.fecha_solicitud}</div>
                        ${item.mensaje_original ? `<div style="font-size:11px; margin-top:4px;">💬 "${escapeHtml(item.mensaje_original)}"</div>` : ''}
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <select id="estatus-${item.id}" class="fs" style="width:120px;">
                            <option value="pendiente_compra" ${item.estatus === 'pendiente_compra' ? 'selected' : ''}>⏳ Pendiente</option>
                            <option value="comprado" ${item.estatus === 'comprado' ? 'selected' : ''}>💰 Comprado</option>
                            <option value="instalado" ${item.estatus === 'instalado' ? 'selected' : ''}>✅ Instalado</option>
                        </select>
                        <button class="bok" style="padding:6px 12px;" onclick="actualizarEstatusMaterial(${item.id})">Actualizar</button>
                    </div>
                </div>
            `;
        }
        html += '</div>';
        panel.innerHTML = html;
        
    } catch (error) {
        console.error('Error cargando compras:', error);
    }
}

async function cargarInstalados() {
    try {
        const response = await fetch('obtener_compras.php?estatus=instalado');
        const data = await response.json();
        const panel = document.getElementById('panel-tecnico');
        
        if (data.length === 0) {
            panel.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text3)"><div class="empty-ico">✅</div><div>No hay equipos instalados registrados</div></div>';
            return;
        }
        
        let html = '<div style="display:flex; flex-direction:column; gap:8px;">';
        for (const item of data) {
            html += `
                <div class="tec-item">
                    <div style="flex:1;">
                        <div><strong>✅ ${escapeHtml(item.material_equipo)}</strong> x${item.cantidad}</div>
                        <div style="font-size:11px; color:var(--text3)">📍 ${escapeHtml(item.residencial)} | 🧑 ${escapeHtml(item.instalado_por)}</div>
                        <div style="font-size:11px; color:var(--success)">📅 Instalado: ${item.fecha_instalacion}</div>
                        ${item.ubicacion_exacta ? `<div style="font-size:11px;">📍 Ubicación: ${escapeHtml(item.ubicacion_exacta)}</div>` : ''}
                    </div>
                </div>
            `;
        }
        html += '</div>';
        panel.innerHTML = html;
        
    } catch (error) {
        console.error('Error cargando instalados:', error);
    }
}

async function cargarMantenimientos() {
    try {
        const response = await fetch('obtener_mantenimientos.php');
        const data = await response.json();
        const panel = document.getElementById('panel-tecnico');
        
        if (data.length === 0) {
            panel.innerHTML = '<div style="text-align:center; padding:40px; color:var(--text3)"><div class="empty-ico">🔧</div><div>No hay mantenimientos registrados</div></div>';
            return;
        }
        
        let html = '<div style="display:flex; flex-direction:column; gap:8px;">';
        for (const item of data) {
            html += `
                <div class="tec-item">
                    <div style="flex:1;">
                        <div><strong>🔧 ${escapeHtml(item.accion_realizada)}</strong></div>
                        <div style="font-size:11px; color:var(--text3)">📍 ${escapeHtml(item.residencial)} | 🔩 ${escapeHtml(item.equipo_afectado)}</div>
                        <div style="font-size:11px;">👷 ${escapeHtml(item.tecnico)} | 📅 ${item.fecha}</div>
                        ${item.mensaje_original ? `<div style="font-size:11px; margin-top:4px;">💬 "${escapeHtml(item.mensaje_original)}"</div>` : ''}
                        ${item.foto_url ? `<div style="margin-top:4px;"><a href="${item.foto_url}" target="_blank" style="font-size:11px;">📷 Ver evidencia</a></div>` : ''}
                    </div>
                </div>
            `;
        }
        html += '</div>';
        panel.innerHTML = html;
        
    } catch (error) {
        console.error('Error cargando mantenimientos:', error);
    }
}

async function actualizarEstatusMaterial(id) {
    const select = document.getElementById(`estatus-${id}`);
    const nuevoEstatus = select.value;
    
    try {
        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('estatus', nuevoEstatus);
        
        const response = await fetch('actualizar_estatus_material.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        });
        const result = await response.json();
        
        if (result.ok) {
            toast(`✅ Estatus actualizado a ${nuevoEstatus}`);
            mostrarTabTecnico(tabActiva);
        }
    } catch (error) {
        console.error('Error actualizando estatus:', error);
        toast('⚠️ Error al actualizar');
    }
}
</script>
</body>
</html>