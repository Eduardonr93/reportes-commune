<?php
// ── DB ────────────────────────────────────────────────────
//$host = "localhost"; $user = "thenetgu_reportes";
//$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
require_once __DIR__ . '/config.php';
$conn = getDB();
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
$f_residencial = $conn->real_escape_string($_GET['f_residencial']??'');
$f_nivel     = $conn->real_escape_string($_GET['f_nivel']??'');
$f_tipo      = $conn->real_escape_string($_GET['f_tipo']??'');

// ── Construcción del WHERE para la consulta principal ─────
$where = "WHERE 1=1";
if($f_estado)      $where .= " AND estatus='$f_estado'";
if($f_cat)         $where .= " AND categoria='$f_cat'";
if($f_prior)       $where .= " AND prioridad='$f_prior'";
if($f_fecha_ini)   $where .= " AND DATE(fecha)>='$f_fecha_ini'";
if($f_fecha_fin)   $where .= " AND DATE(fecha)<='$f_fecha_fin'";
if($f_search)      $where .= " AND (descripcion LIKE '%$f_search%' OR remitente LIKE '%$f_search%')";
if($f_residencial) $where .= " AND residencial='$f_residencial'";
if($f_nivel)       $where .= " AND nivel_urgencia='$f_nivel'";
if($f_tipo)        $where .= " AND tipo_reporte='$f_tipo'";

// Si no hay filtro de estado, ocultar los reportes terminados SOLO si no hay NINGÚN filtro
if(empty($f_estado) && empty($f_prior)) {
    if(empty($f_residencial) && empty($f_nivel) && empty($f_tipo)) {
        $where .= " AND estatus IN ('Pendiente', 'En Proceso')";
    }
}

// ── Stats filtrados ───────────────────────────────────────
function q($c,$s){ $r=$c->query($s); return $r?$r->fetch_assoc():['c'=>0]; }

$stats_filtrados = [
    'total'    => q($conn,"SELECT COUNT(*) c FROM reportes $where")['c'],
    'pendiente'=> q($conn,"SELECT COUNT(*) c FROM reportes $where AND estatus='Pendiente'")['c'],
    'proceso'  => q($conn,"SELECT COUNT(*) c FROM reportes $where AND estatus='En Proceso'")['c'],
    'terminado'=> q($conn,"SELECT COUNT(*) c FROM reportes $where AND estatus='Terminado'")['c'],
    'urgente'  => q($conn,"SELECT COUNT(*) c FROM reportes $where AND prioridad='Urgente' AND estatus!='Terminado'")['c'],
];

// ── Gráfica mensual ───────────────────────────────────────
$where_grafica = "";
if($f_residencial) $where_grafica .= " AND residencial='$f_residencial'";
if($f_nivel) $where_grafica .= " AND nivel_urgencia='$f_nivel'";
if($f_tipo) $where_grafica .= " AND tipo_reporte='$f_tipo'";

$mes = [];
for($i=29;$i>=0;$i--){
    $dia = date('Y-m-d',strtotime("-$i days"));
    $label = date('d/m',strtotime("-$i days"));
    $count = (int)q($conn,"SELECT COUNT(*) c FROM reportes WHERE DATE(fecha)='$dia' $where_grafica")['c'];
    $mes[] = ['label' => $label, 'count' => $count, 'fecha' => $dia];
}

// ── Gráfica de categorías ─────────────────────────────────
$categorias_stats = [];
$where_cat = $where_grafica;
foreach(['CCTV','Redes','Perímetro','Accesos','Alarma'] as $cat){
    $cnt = q($conn,"SELECT COUNT(*) c FROM reportes WHERE categoria='$cat' $where_cat")['c'];
    if($cnt > 0) $categorias_stats[] = ['label' => $cat, 'count' => $cnt];
}
$total_categorias = array_sum(array_column($categorias_stats, 'count'));

// ── Residenciales ─────────────────────────────────────────
$residenciales = ['RIO','Cumbres','Via Cumbres','Aqua','Palmaris','Arbolada','Altai','Kyra','Monte Athos'];
$cats = ['CCTV'=>'📷','Redes'=>'🌐','Perímetro'=>'⚡','Accesos'=>'🔑','Alarma'=>'🚨','General'=>'📋'];
$niveles = ['Nivel 1', 'Nivel 2', 'Nivel 3'];
$tipos_reporte = ['Incidencia', 'Preventivo', 'Mantenimiento'];

// ── Carga de técnicos ─────────────────────────────────────
$carga_tecnicos = [];
$where_tec = "";
if($f_residencial) $where_tec .= " AND residencial='$f_residencial'";
$res_tec = $conn->query("SELECT tecnico_asignado, 
    SUM(CASE WHEN estatus!='Terminado' THEN 1 ELSE 0 END) as pendientes, 
    SUM(CASE WHEN prioridad='Urgente' AND estatus!='Terminado' THEN 1 ELSE 0 END) as urgentes, 
    SUM(CASE WHEN estatus='Terminado' THEN 1 ELSE 0 END) as terminados, 
    AVG(tiempo_trabajado) as tiempo_promedio 
    FROM reportes WHERE tecnico_asignado != '' AND tecnico_asignado IS NOT NULL $where_tec
    GROUP BY tecnico_asignado ORDER BY pendientes DESC");
if($res_tec) while($r=$res_tec->fetch_assoc()) $carga_tecnicos[] = $r;

// ── LISTA DE TÉCNICOS ─────────────────────────────────────
$tecnicos_lista = [];
$res_tecnicos = $conn->query("SELECT id, nombre, numero_whatsapp, especialidad FROM tecnicos WHERE activo = 1 ORDER BY nombre");
if ($res_tecnicos) {
    while ($row = $res_tecnicos->fetch_assoc()) {
        $tecnicos_lista[] = $row;
    }
} else {
    $tecnicos_lista = [
        ['nombre' => 'Eduardo', 'numero_whatsapp' => '5219983067953', 'especialidad' => 'CCTV, Accesos'],
        ['nombre' => 'Israel', 'numero_whatsapp' => '', 'especialidad' => 'Redes'],
        ['nombre' => 'Cristian', 'numero_whatsapp' => '', 'especialidad' => 'Alarma, Perímetro'],
        ['nombre' => 'Martín', 'numero_whatsapp' => '5219981461823', 'especialidad' => '']
    ];
}

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

// ── Para endpoint AJAX ────────────────────────────────────
if(isset($_GET['get_urgent_count'])){
    header('Content-Type: application/json');
    echo json_encode($stats_filtrados['urgente']);
    exit;
}

// ── Export CSV ────────────────────────────────────────────
if(isset($_GET['export']) && $_GET['export']==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reportes_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['ID','Fecha','Remitente','Descripcion','Categoria','Prioridad','Estatus','Tecnico','Residencial','Nivel Urgencia','Tipo Reporte','Tiempo(min)','Fecha Cierre']);
    $r = $conn->query("SELECT * FROM reportes $where ORDER BY fecha DESC");
    while($row=$r->fetch_assoc())
        fputcsv($out,[$row['id'],$row['fecha'],$row['remitente'],$row['descripcion'],$row['categoria'],$row['prioridad']??'Normal',$row['estatus'],$row['tecnico_asignado']??'', $row['residencial']??'', $row['nivel_urgencia']??'', $row['tipo_reporte']??'Incidencia', $row['tiempo_trabajado']??'', $row['fecha_terminado']??'']);
    fclose($out); exit;
}

$initial_total = $stats_filtrados['total'];
$titulo_residencial = $f_residencial ? " - " . $f_residencial : "";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,user-scalable=yes">
<meta name="theme-color" content="#2563eb">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Commune">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icons/icon-192.png">
<title>Commune — Gestión Cancún<?php echo $titulo_residencial; ?></title>
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
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;-webkit-tap-highlight-color:transparent}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 12px;min-height:58px;display:flex;align-items:center;gap:8px;position:sticky;top:0;z-index:100;box-shadow:var(--sh);flex-wrap:wrap}
.logo{width:34px;height:34px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0}
.brand{line-height:1.2}
.brand-name{font-weight:700;font-size:14px}
.brand-sub{font-size:10px;color:var(--text3)}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.menu-toggle{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);width:36px;height:36px;font-size:20px;cursor:pointer;display:none;align-items:center;justify-content:center}
.menu-toggle:hover{background:var(--bg4)}
.pill{display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:20px;font-size:10px;font-weight:500;border:1px solid}
.pill-live{background:var(--success-l);border-color:#bbf7d0;color:var(--success)}
.pill-clock{background:var(--bg3);border-color:var(--border);color:var(--text2);font-family:'JetBrains Mono',monospace;font-size:10px}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--success);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}
.tb-btn{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:6px 10px;font-size:12px;color:var(--text2);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.tb-btn:hover{border-color:var(--accent);color:var(--accent)}
.layout{display:grid;grid-template-columns:250px 1fr;min-height:calc(100vh - 58px)}
.sidebar{background:var(--bg2);border-right:1px solid var(--border);padding:14px 0;position:sticky;top:58px;height:calc(100vh - 58px);overflow-y:auto;transition:left 0.3s ease;z-index:99}
.sb-sec{padding:0 12px;margin-bottom:18px}
.sb-lbl{font-size:9px;font-weight:700;color:var(--text3);letter-spacing:.12em;text-transform:uppercase;margin-bottom:5px;padding:0 6px}
.sb-item{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:var(--r-sm);color:var(--text2);font-size:13px;text-decoration:none}
.sb-item:hover{background:var(--bg3);color:var(--text)}
.sb-item.on{background:var(--accent-l);color:var(--accent);font-weight:500}
.sb-item-l{display:flex;align-items:center;gap:8px}
.sb-n{font-size:11px;font-weight:600;padding:2px 6px;border-radius:20px}
.n-r{background:var(--danger-l);color:var(--danger)}
.n-b{background:var(--accent-l);color:var(--accent)}
.n-g{background:var(--success-l);color:var(--success)}
.n-w{background:var(--warn-l);color:var(--warn)}
.n-x{background:var(--bg4);color:var(--text3)}
.sb-div{height:1px;background:var(--border);margin:8px 12px}
.main{padding:16px;min-width:0}
.residencia-activa{background:var(--accent-l);border-left:3px solid var(--accent);margin-top:8px;padding:8px 12px;border-radius:var(--r-sm);font-size:12px;margin-bottom:12px}
.residencia-activa span{font-weight:600;color:var(--accent)}
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:16px}
.sc{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;display:flex;align-items:center;justify-content:space-between}
.sc-n{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:600}
.sc-l{font-size:9px;color:var(--text3);margin-top:2px;text-transform:uppercase}
.sc-ico{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px}
.s0 .sc-ico{background:#ede9fe} .s1 .sc-ico{background:var(--danger-l)} .s2 .sc-ico{background:var(--warn-l)} .s3 .sc-ico{background:var(--success-l)} .s4 .sc-ico{background:var(--urgent-l)}
.graficas-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.grafica-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:12px}
.grafica-titulo{font-size:10px;font-weight:600;color:var(--text2);text-transform:uppercase;margin-bottom:10px}
canvas{max-height:200px;width:100%}
.leyenda-porcentajes{margin-top:10px;font-size:10px}
.leyenda-item{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.leyenda-color{width:10px;height:10px;border-radius:50%}
.tabla-vista{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);overflow-x:auto;margin-bottom:12px}
.tabla-vista table{width:100%;border-collapse:collapse;font-size:11px}
.tabla-vista th,.tabla-vista td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--border)}
.tabla-vista th{background:var(--bg3);font-weight:600}
.badge-estado{display:inline-block;padding:2px 8px;border-radius:20px;font-size:9px}
.badge-pend{background:var(--danger-l);color:var(--danger)}
.badge-proc{background:var(--warn-l);color:var(--warn)}
.badge-done{background:var(--success-l);color:var(--success)}
.cg{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.card.urg{border-left:3px solid var(--urgent)}
.ct{padding:10px 12px 6px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px}
.cbadges{display:flex;gap:4px;flex-wrap:wrap}
.badge{padding:2px 8px;border-radius:20px;font-size:10px}
.b-cat{background:var(--bg4);color:var(--text2)}
.b-pend{background:var(--danger-l);color:var(--danger)}
.b-proc{background:var(--warn-l);color:var(--warn)}
.b-done{background:var(--success-l);color:var(--success)}
.b-nivel1{background:var(--urgent-l);color:var(--urgent)}
.b-nivel2{background:var(--warn-l);color:var(--warn)}
.b-nivel3{background:var(--bg4);color:var(--text2)}
.ctime{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--text3)}
.cb{padding:2px 12px 8px}
.cname{font-size:12px;font-weight:600;color:var(--accent)}
.cdesc{font-size:11px;color:var(--text2);line-height:1.5;cursor:pointer;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.cimg{width:100%;height:120px;object-fit:cover;cursor:pointer}
.csla{padding:4px 12px;font-size:10px;background:var(--bg3);border-top:1px solid var(--border);display:flex;align-items:center;gap:4px}
.sla-verde{background:#f0fdf4;border-top-color:#bbf7d0}
.sla-naranja{background:#fffbeb;border-top-color:#fde68a}
.sla-rojo{background:#fef2f2;border-top-color:#fecaca}
.sla-dot{width:6px;height:6px;border-radius:50%;display:inline-block;margin-left:4px}
.cf{padding:8px 12px;border-top:1px solid var(--border)}
.cfr{display:grid;grid-template-columns:1fr auto auto auto;gap:4px;margin-bottom:6px}
.fs,.fi,.fta{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:6px 6px;font-size:11px;width:100%}
.bok{background:var(--accent);color:#fff;border:none;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:11px}
.bclose{background:var(--success-l);border:1px solid #bbf7d0;color:var(--success);border-radius:var(--r-sm);padding:6px 10px;cursor:pointer;width:100%;margin-top:3px;font-size:11px}
.coll{display:none;background:var(--bg3);border-radius:var(--r-sm);padding:10px;margin-top:6px}
.coll.open{display:block}
.cbox{background:var(--success-l);border:1px solid #bbf7d0;border-radius:var(--r-sm);padding:6px 10px;font-size:11px}
.ov{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:12px}
.ov.open{display:flex}
.modal{background:var(--bg2);border-radius:16px;width:100%;max-width:650px;max-height:90vh;overflow-y:auto}
.mh{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;position:sticky;top:0;background:var(--bg2);z-index:1}
.mt{font-size:14px;font-weight:600}
.mx{background:var(--bg3);border:none;color:var(--text2);width:28px;height:28px;border-radius:6px;cursor:pointer}
.mb{padding:16px}
.fl{font-size:9px;font-weight:600;color:var(--text3);text-transform:uppercase;margin-bottom:3px}
.fv{font-size:12px;background:var(--bg3);border-radius:var(--r-sm);padding:8px 10px;margin-bottom:10px}
.mig{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.mig img{max-width:100%;max-height:100px;border-radius:var(--r-sm);border:1px solid var(--border)}
.toast{position:fixed;bottom:20px;right:20px;background:var(--text);color:#fff;border-radius:10px;padding:10px 14px;z-index:300;opacity:0;transition:.2s;pointer-events:none;font-size:12px}
.toast.on{opacity:1}
.empty{text-align:center;padding:40px 20px;color:var(--text3)}
.pagination{display:flex;gap:5px;justify-content:center;margin-top:20px;flex-wrap:wrap}
.page{padding:5px 10px;background:var(--bg2);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text2);text-decoration:none}
.page.active{background:var(--accent);color:#fff}
.toolbar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:12px;margin-bottom:12px}
.trow{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.sw{flex:1;min-width:140px;position:relative}
.sw input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 10px 8px 32px;font-size:12px}
.si{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:12px}
.fsel{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 8px;font-size:12px}
.btn{padding:8px 14px;border-radius:var(--r-sm);font-size:12px;font-weight:500;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.btn-p{background:var(--accent);color:#fff}
.btn-s{background:var(--bg3);border:1px solid var(--border);color:var(--text2)}
.extra-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.extra-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:14px}
.ph{display:flex;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px}
.pt{font-size:10px;font-weight:600;color:var(--text2);text-transform:uppercase}
.tec-item{display:flex;align-items:center;justify-content:space-between;padding:8px;background:var(--bg3);border-radius:8px;margin-bottom:6px;flex-wrap:wrap;gap:6px}
.tec-name{font-size:11px;font-weight:600}
.tec-pend{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;color:var(--danger)}
.tec-done{font-size:10px;color:var(--success)}
.desc-item{padding:6px 8px;background:var(--bg3);border-radius:6px;margin-bottom:5px}
.desc-text{font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.desc-badge{background:var(--bg4);padding:1px 5px;border-radius:4px;font-size:9px}
.tiempo-cronometro{font-size:10px;color:var(--accent);margin-top:4px}
.whatsapp-hint-modal{font-size:10px;color:var(--text3);margin-bottom:10px}
.sugerencia-badge{background:var(--accent-l);color:var(--accent);padding:2px 6px;border-radius:12px;font-size:9px;margin-left:6px}
.resumen-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.resumen-card{background:var(--bg3);border-radius:8px;padding:10px;text-align:center}
.resumen-numero{font-size:24px;font-weight:700}
.resumen-label{font-size:9px;color:var(--text3)}
@media (max-width: 960px){
    .menu-toggle{display:flex}
    .sidebar{position:fixed;left:-260px;width:260px;height:100%;top:58px}
    .sidebar.open{left:0}
    .layout{grid-template-columns:1fr}
    .stats-row{grid-template-columns:repeat(3,1fr)}
    .graficas-grid{grid-template-columns:1fr}
    .extra-grid{grid-template-columns:1fr}
}
@media (max-width: 580px){
    .tb-btn span{display:none}
    .tb-btn{padding:6px 8px}
    .pill-clock{display:none}
    .brand-sub{display:none}
    .stats-row{grid-template-columns:repeat(2,1fr)}
    .stats-row .sc:last-child{grid-column:span 2}
    .trow{flex-direction:column;align-items:stretch}
    .sw,.fsel,.btn{width:100%}
    .rc-info{text-align:center;margin-top:6px}
    .cg{grid-template-columns:1fr}
    .card{margin:0}
    .cfr{grid-template-columns:1fr auto auto}
    .cfr select:first-child{grid-column:span 3}
    .main{padding:12px}
    .sc-n{font-size:18px}
    .sc-ico{width:28px;height:28px;font-size:14px}
    .sc{padding:8px 10px}
    .resumen-stats{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<header class="topbar">
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    <div class="logo">C</div>
    <div class="brand"><div class="brand-name">Commune</div><div class="brand-sub">Gestión Cancún</div></div>
    <div class="tb-right">
        <span class="pill pill-live"><span class="live-dot"></span>En vivo</span>
        <span class="pill pill-clock" id="clock">--:--</span>
        <a href="?vista=cards&<?php echo http_build_query(['vista'=>'cards', 'f_residencial'=>$f_residencial]); ?>" class="tb-btn <?php echo $vista==='cards'?'on':'';?>">📇</a>
        <a href="?vista=tabla&<?php echo http_build_query(['vista'=>'tabla', 'f_residencial'=>$f_residencial]); ?>" class="tb-btn <?php echo $vista==='tabla'?'on':'';?>">📋</a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'csv']));?>" class="tb-btn">⬇</a>
    </div>
</header>

<div class="layout">
<aside class="sidebar" id="sidebar">
    <div class="sb-sec">
        <div class="sb-lbl">Estado</div>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_estado'=>'', 'f_prior'=>'', 'p'=>'']); ?>" class="sb-item <?php if(!$f_estado&&!$f_prior) echo 'on';?>">
            <span class="sb-item-l">📋 Activos</span>
            <span class="sb-n n-b"><?php echo $stats_filtrados['pendiente']+$stats_filtrados['proceso'];?></span>
        </a>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_estado'=>'Pendiente', 'p'=>'']); ?>" class="sb-item <?php if($f_estado==='Pendiente') echo 'on';?>">
            <span class="sb-item-l">🔴 Pendientes</span>
            <span class="sb-n n-r"><?php echo $stats_filtrados['pendiente'];?></span>
        </a>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_estado'=>'En Proceso', 'p'=>'']); ?>" class="sb-item <?php if($f_estado==='En Proceso') echo 'on';?>">
            <span class="sb-item-l">🟡 En proceso</span>
            <span class="sb-n n-w"><?php echo $stats_filtrados['proceso'];?></span>
        </a>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_estado'=>'Terminado', 'p'=>'']); ?>" class="sb-item <?php if($f_estado==='Terminado') echo 'on';?>">
            <span class="sb-item-l">🟢 Terminados</span>
            <span class="sb-n n-g"><?php echo $stats_filtrados['terminado'];?></span>
        </a>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_prior'=>'Urgente', 'p'=>'']); ?>" class="sb-item <?php if($f_prior==='Urgente') echo 'on';?>">
            <span class="sb-item-l">🚨 Urgentes</span>
            <?php if($stats_filtrados['urgente']>0): ?><span class="sb-n n-r"><?php echo $stats_filtrados['urgente'];?></span><?php endif;?>
        </a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-sec">
        <div class="sb-lbl">Categoría</div>
        <?php foreach($cats as $c=>$ico):
            $cnt=q($conn,"SELECT COUNT(*) c FROM reportes WHERE categoria='$c' AND estatus!='Terminado'")['c'];
        ?>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_cat'=>$c, 'p'=>'']); ?>" class="sb-item <?php if($f_cat===$c) echo 'on';?>">
            <span class="sb-item-l"><?php echo $ico.' '.$c;?></span>
            <?php if($cnt>0): ?><span class="sb-n n-x"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
    <div class="sb-div"></div>
    <div class="sb-sec">
        <div class="sb-lbl">Nivel de urgencia</div>
        <?php foreach($niveles as $nivel): 
            $cnt = q($conn,"SELECT COUNT(*) c FROM reportes WHERE nivel_urgencia='$nivel' AND estatus!='Terminado'")['c'];
        ?>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_nivel'=>$nivel, 'p'=>'']); ?>" class="sb-item <?php if($f_nivel===$nivel) echo 'on';?>">
            <span class="sb-item-l"><?php echo $nivel==='Nivel 1'?'🔴':($nivel==='Nivel 2'?'🟠':'🟡'); ?> <?php echo $nivel; ?></span>
            <?php if($cnt>0): ?><span class="sb-n n-r"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
    <div class="sb-div"></div>
    <div class="sb-sec">
        <div class="sb-lbl">Tipo de reporte</div>
        <?php foreach($tipos_reporte as $tipo): 
            $cnt = q($conn,"SELECT COUNT(*) c FROM reportes WHERE tipo_reporte='$tipo' AND estatus!='Terminado'")['c'];
        ?>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial, 'f_tipo'=>$tipo, 'p'=>'']); ?>" class="sb-item <?php if($f_tipo===$tipo) echo 'on';?>">
            <span class="sb-item-l"><?php echo $tipo==='Incidencia'?'⚡':($tipo==='Preventivo'?'🛡️':'🔧'); ?> <?php echo $tipo; ?></span>
            <?php if($cnt>0): ?><span class="sb-n n-x"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
    <div class="sb-div"></div>
    <div class="sb-sec">
        <div class="sb-lbl">Residencial</div>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>'', 'p'=>'']); ?>" class="sb-item <?php if(empty($f_residencial)) echo 'on';?>">
            <span class="sb-item-l">🏘️ Todos</span>
        </a>
        <?php foreach($residenciales as $resid):
            $cnt = q($conn,"SELECT COUNT(*) c FROM reportes WHERE residencial='$resid' AND estatus!='Terminado'")['c'];
        ?>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$resid, 'p'=>'']); ?>" class="sb-item <?php if($f_residencial===$resid) echo 'on';?>">
            <span class="sb-item-l">🏘️ <?php echo $resid;?></span>
            <?php if($cnt>0): ?><span class="sb-n n-x"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
</aside>

<main class="main">

<?php if($f_residencial): ?>
<div class="residencia-activa">
    📍 Mostrando datos de: <span><?php echo $f_residencial; ?></span>
    <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>'', 'p'=>'']); ?>" style="float:right; font-size:11px; color:var(--accent);">✕ Limpiar filtro</a>
</div>
<?php endif; ?>

<div class="stats-row">
    <?php 
    $sdata_filt = [
        ['n'=>$stats_filtrados['total'],'l'=>'Total','i'=>'📊','cls'=>'s0'],
        ['n'=>$stats_filtrados['pendiente'],'l'=>'Pendientes','i'=>'⏳','cls'=>'s1'],
        ['n'=>$stats_filtrados['proceso'],'l'=>'En proceso','i'=>'🔧','cls'=>'s2'],
        ['n'=>$stats_filtrados['terminado'],'l'=>'Terminados','i'=>'✅','cls'=>'s3'],
        ['n'=>$stats_filtrados['urgente'],'l'=>'Urgentes','i'=>'🚨','cls'=>'s4']
    ]; 
    foreach($sdata_filt as $s): ?>
    <div class="sc <?php echo $s['cls'];?>">
        <div><div class="sc-n"><?php echo $s['n'];?></div><div class="sc-l"><?php echo $s['l'];?></div></div>
        <div class="sc-ico"><?php echo $s['i'];?></div>
    </div>
    <?php endforeach;?>
</div>

<div class="graficas-grid">
    <div class="grafica-card">
        <div class="grafica-titulo">📈 Actividad últimos 30 días</div>
        <canvas id="chartMensual" height="150"></canvas>
    </div>
    <div class="grafica-card">
        <div class="grafica-titulo">🥧 Reportes por categoría</div>
        <canvas id="chartCategorias" height="150"></canvas>
        <?php if($total_categorias > 0): ?>
        <div class="leyenda-porcentajes">
            <?php 
            $colores = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed'];
            foreach($categorias_stats as $idx => $cat): 
                $porcentaje = round(($cat['count'] / $total_categorias) * 100, 1);
            ?>
            <div class="leyenda-item">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <div class="leyenda-color" style="background: <?php echo $colores[$idx % count($colores)]; ?>;"></div>
                    <span><?php echo $cat['label']; ?></span>
                </div>
                <div><strong><?php echo $cat['count']; ?></strong> (<?php echo $porcentaje; ?>%)</div>
            </div>
            <?php endforeach; ?>
            <div style="border-top: 1px solid var(--border); margin-top: 6px; padding-top: 4px;">
                <strong>Total: <?php echo $total_categorias; ?></strong>
            </div>
        </div>
        <?php endif; ?>
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
            <div style="text-align:center;padding:16px;color:var(--text3)">Sin técnicos asignados</div>
        <?php endif; ?>
    </div>
    <?php if($tiene_basurero): ?>
    <div class="extra-card">
        <div class="ph"><div class="pt">🗑️ Basurero</div><a href="basurero.php" style="font-size:11px;color:var(--accent)">Ver todos →</a></div>
        <div style="text-align:center;padding:8px"><div style="font-size:28px;font-weight:700"><?php echo $total_descartados;?></div><div style="font-size:10px;color:var(--text3)">Mensajes descartados</div></div>
        <?php if(!empty($ultimos_descartados)): ?>
            <?php foreach($ultimos_descartados as $d): ?>
            <div class="desc-item"><div class="desc-text"><?php echo htmlspecialchars(substr($d['mensaje']??'',0,50));?></div><div><span class="desc-badge"><?php echo $d['motivo'];?></span></div></div>
            <?php endforeach;?>
        <?php endif;?>
    </div>
    <?php endif; ?>
</div>

<form method="GET" id="ff">
<input type="hidden" name="vista" value="<?php echo $vista;?>">
<input type="hidden" name="f_residencial" value="<?php echo $f_residencial;?>">
<input type="hidden" name="f_nivel" value="<?php echo $f_nivel;?>">
<input type="hidden" name="f_tipo" value="<?php echo $f_tipo;?>">
<div class="toolbar">
    <div class="trow">
        <div class="sw"><span class="si">🔍</span><input type="text" name="f_search" placeholder="Buscar..." value="<?php echo htmlspecialchars($f_search);?>"></div>
        <select name="f_estado" class="fsel" onchange="this.form.submit()"><option value="">Estado</option><option value="Pendiente" <?php if($f_estado==='Pendiente') echo 'selected';?>>Pendiente</option><option value="En Proceso" <?php if($f_estado==='En Proceso') echo 'selected';?>>En proceso</option><option value="Terminado" <?php if($f_estado==='Terminado') echo 'selected';?>>Terminado</option></select>
        <select name="f_cat" class="fsel" onchange="this.form.submit()"><option value="">Categoría</option><?php foreach($cats as $c=>$ico): ?><option value="<?php echo $c;?>" <?php if($f_cat===$c) echo 'selected';?>><?php echo $c;?></option><?php endforeach;?></select>
        <input type="date" name="f_fecha_ini" class="fsel" value="<?php echo $f_fecha_ini;?>" onchange="this.form.submit()">
        <input type="date" name="f_fecha_fin" class="fsel" value="<?php echo $f_fecha_fin;?>" onchange="this.form.submit()">
        <button type="submit" class="btn btn-p">Buscar</button>
        <a href="?<?php echo http_build_query(['vista'=>$vista, 'f_residencial'=>$f_residencial]); ?>" class="btn btn-s">Limpiar</a>
        <button type="button" class="btn btn-s" onclick="generarResumenSemanal()">📊 Resumen semana</button>
        <button type="button" class="btn btn-s" onclick="abrirModalFechas()">📅 Reporte personalizado</button>
        <span class="rc-info"><?php echo $total_fil;?> resultados</span>
    </div>
</div>
</form>

<?php
$reportes_data = [];
if($res && $res->num_rows > 0){
    while($row = $res->fetch_assoc()){
        $reportes_data[] = $row;
    }
    if($vista === 'cards'){
        $res->data_seek(0);
    }
}
?>

<?php if($vista === 'tabla'): ?>
<div class="tabla-vista">
<table>
<thead>
<tr><th>ID</th><th>Fecha</th><th>Remitente</th><th>Descripción</th><th>Categoría</th><th>Prioridad</th><th>Nivel</th><th>Tipo</th><th>Estado</th><th>Técnico</th><th></th></tr>
</thead>
<tbody>
<?php if(!empty($reportes_data)): foreach($reportes_data as $row): $urg=($row['prioridad']??'Normal')==='Urgente'; ?>
<tr<?php echo $urg?' class="urgente"':'';?>><td><?php echo $row['id'];?></td><td><?php echo date('d/m H:i',strtotime($row['fecha']));?></td><td><?php echo htmlspecialchars($row['remitente']);?></td><td><?php echo htmlspecialchars(substr($row['descripcion']??'',0,40));?></td><td><?php echo $row['categoria'];?></td><td><?php echo $urg?'🚨 Urgente':'Normal';?></td><td><?php echo $row['nivel_urgencia']??'-';?></td><td><?php echo $row['tipo_reporte']??'Incidencia';?></td><td><span class="badge-estado badge-<?php echo $row['estatus']==='Pendiente'?'pend':($row['estatus']==='En Proceso'?'proc':'done');?>"><?php echo $row['estatus'];?></span></td><td><?php echo htmlspecialchars($row['tecnico_asignado']??'—');?></td>
<td><button class="btn-s" style="padding:4px 6px" onclick="openM(<?php echo $row['id'];?>)">Ver</button></td>
</tr>
<?php endforeach; else: ?><tr><td colspan="11" style="text-align:center">Sin resultados</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="cg">
<?php if($res===false){ echo '<div class="empty">Error en consulta</div>'; } elseif($res->num_rows===0){ echo '<div class="empty">📭 No hay reportes activos</div>'; } else { while($row=$res->fetch_assoc()): $id=(int)$row['id']; $urg=($row['prioridad']??'Normal')==='Urgente'; $ec=$row['estatus']==='Pendiente'?'b-pend':($row['estatus']==='En Proceso'?'b-proc':'b-done'); $sla=''; $sla_color=''; if($row['fecha']){ $fin=$row['fecha_terminado']??date('Y-m-d H:i:s'); $horas=round((strtotime($fin)-strtotime($row['fecha']))/3600,1); if($row['estatus']==='Terminado'){ if($horas>=24){ $dias=round($horas/24,1); $sla="✓ Resuelto en {$dias} días"; } else { $sla="✓ Resuelto en {$horas}h"; } } else { if($horas>=24){ $dias=round($horas/24,1); $sla="Abierto hace {$dias} días"; } else { $sla="Abierto hace {$horas}h"; } $es_urg=($row['prioridad']??'Normal')==='Urgente'; if($es_urg){ if($horas<2) $sla_color='sla-verde'; elseif($horas<4) $sla_color='sla-naranja'; else $sla_color='sla-rojo'; } else { if($horas<4) $sla_color='sla-verde'; elseif($horas<12) $sla_color='sla-naranja'; else $sla_color='sla-rojo'; } } } ?>
<div class="card <?php echo $urg?'urg':'';?>" id="c<?php echo $id;?>">
    <div class="ct">
        <div class="cbadges">
            <span class="badge b-cat"><?php echo htmlspecialchars($row['categoria']);?></span>
            <span class="badge <?php echo $ec;?>"><?php echo $row['estatus'];?></span>
            <?php if($urg): ?><span class="badge b-urg">🚨 Urgente</span><?php endif;?>
            <?php if($row['nivel_urgencia']): ?>
            <span class="badge <?php echo $row['nivel_urgencia']==='Nivel 1'?'b-nivel1':($row['nivel_urgencia']==='Nivel 2'?'b-nivel2':'b-nivel3');?>">
                <?php echo $row['nivel_urgencia']==='Nivel 1'?'🔴':($row['nivel_urgencia']==='Nivel 2'?'🟠':'🟡');?>
                <?php echo $row['nivel_urgencia'];?>
            </span>
            <?php endif; ?>
            <?php if($row['tipo_reporte'] && $row['tipo_reporte'] !== 'Incidencia'): ?>
            <span class="badge b-cat">
                <?php echo $row['tipo_reporte']==='Preventivo'?'🛡️':'🔧';?>
                <?php echo $row['tipo_reporte'];?>
            </span>
            <?php endif; ?>
        </div>
        <div class="ctime"><?php echo $row['fecha']?date('d/m H:i',strtotime($row['fecha'])):'—';?></div>
    </div>
    <div class="cb">
        <div class="cname"><?php echo htmlspecialchars($row['remitente']?:'Sin remitente');?></div>
        <?php if(!empty($row['tecnico_asignado'])): ?>
        <div class="ctec">👷 <?php echo htmlspecialchars($row['tecnico_asignado']);?></div>
        <?php endif;?>
        <div class="cdesc" onclick="openM(<?php echo $id;?>)"><?php echo nl2br(htmlspecialchars($row['descripcion']?:'(sin texto)'));?></div>
    </div>
    <?php if(!empty($row['foto_url'])): ?><img src="<?php echo htmlspecialchars($row['foto_url']);?>" class="cimg" onclick="openM(<?php echo $id;?>)" loading="lazy"><?php endif;?>
    <?php if($sla): ?><div class="csla <?php echo $sla_color;?>"><span><?php echo $sla;?></span><?php if($sla_color): ?><span class="sla-dot"></span><?php endif;?></div><?php endif;?>
    <?php if(can('edit')): ?>
    <div class="cf">
        <form method="POST" class="reporte-form" data-id="<?php echo $id;?>">
            <input type="hidden" name="id" value="<?php echo $id;?>">
            <div class="cfr">
                <select name="nuevo_estado" class="fs"><option value="Pendiente" <?php if($row['estatus']==='Pendiente') echo 'selected';?>>🔴 Pendiente</option><option value="En Proceso" <?php if($row['estatus']==='En Proceso') echo 'selected';?>>🟡 En proceso</option><option value="Terminado" <?php if($row['estatus']==='Terminado') echo 'selected';?>>🟢 Terminado</option></select>
                <select name="categoria_manual" class="fs" id="catSelect<?php echo $id;?>"><?php foreach($cats as $c=>$ico): ?><option value="<?php echo $c;?>" <?php if($row['categoria']===$c) echo 'selected';?>><?php echo $c;?></option><?php endforeach;?></select>
                <select name="prioridad" class="fs"><option value="Normal" <?php if(($row['prioridad']??'Normal')==='Normal') echo 'selected';?>>Normal</option><option value="Urgente" <?php if(($row['prioridad']??'Normal')==='Urgente') echo 'selected';?>>🚨 Urgente</option></select>
                <button type="submit" name="actualizar_estado" class="bok">OK</button>
            </div>
            <select name="tecnico_asignado" class="fs" style="width:100%; margin-top:5px;" id="tecSelect<?php echo $id;?>">
                <option value="">-- Seleccionar técnico --</option>
                <?php foreach($tecnicos_lista as $tec): ?>
                    <option value="<?php echo htmlspecialchars($tec['nombre']); ?>" <?php echo ($row['tecnico_asignado']??'')===$tec['nombre']?'selected':''; ?> data-especialidad="<?php echo htmlspecialchars($tec['especialidad'] ?? ''); ?>"><?php echo htmlspecialchars($tec['nombre']); ?><?php if(!empty($tec['especialidad'])): ?> (<?php echo htmlspecialchars($tec['especialidad']); ?>)<?php endif; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if(can('close') && $row['estatus']!=='Terminado'): ?>
        <button class="bclose" onclick="toggleC(<?php echo $id;?>)">✅ Documentar cierre</button>
        <div class="coll" id="col<?php echo $id;?>"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="id" value="<?php echo $id;?>"><select class="fi" onchange="document.querySelector('#obs<?php echo $id;?>').value=this.value"><option value="">📋 Plantilla...</option><option value="Se realizó mantenimiento preventivo, equipo funcionando correctamente.">🔧 Mantenimiento preventivo</option><option value="Se reemplazó el componente dañado, sistema operativo.">🔄 Reemplazo de componente</option><option value="Se reinició el sistema, todo ok.">🖥️ Reinicio</option><option value="Se actualizó firmware, pendiente monitoreo.">📦 Actualización</option><option value="Requiere refacción, pendiente cotización.">⏳ Esperando refacción</option></select><input type="text" name="tecnico" class="fi" placeholder="Técnico responsable" required><textarea name="observaciones" id="obs<?php echo $id;?>" class="fta" placeholder="Trabajos realizados..."></textarea><div class="tiempo-cronometro">⏱️ Tiempo: <span id="crono-tiempo-<?php echo $id;?>">0</span> min</div><input type="file" name="evidencia" class="fi" accept="image/*"><button type="submit" name="finalizar" class="bok" style="width:100%;padding:8px">Cerrar reporte</button></form></div>
        <?php elseif($row['estatus']==='Terminado'): ?><div class="cbox">✅ <strong><?php echo htmlspecialchars($row['nombre_tecnico']??'—');?></strong> <?php if(!empty($row['observaciones_cierre'])): ?>— <?php echo htmlspecialchars($row['observaciones_cierre']);?><?php endif;?> <?php if($row['tiempo_trabajado']): ?>(⏱️ <?php echo round($row['tiempo_trabajado']);?> min)<?php endif;?></div><?php endif;?>
    </div>
    <?php endif;?>
</div>
<?php endwhile; } ?>
</div>
<?php endif; ?>

<?php if($total_paginas > 1): ?>
<div class="pagination"><?php for($i=1;$i<=$total_paginas;$i++): ?><a href="?<?php echo http_build_query(array_merge($_GET, ['p'=>$i])); ?>" class="page <?php echo $i===$pagina?'active':'';?>"><?php echo $i;?></a><?php endfor; ?></div>
<?php endif; ?>

<?php if(!empty($reportes_data)): foreach($reportes_data as $row): $id=(int)$row['id']; ?>
<div class="ov" id="m<?php echo $id;?>" onclick="if(event.target===this)closeM(<?php echo $id;?>)"><div class="modal"><div class="mh"><div class="mt">Reporte #<?php echo $id;?> — <?php echo htmlspecialchars($row['categoria']);?></div><button class="mx" onclick="closeM(<?php echo $id;?>)">✕</button></div><div class="mb"><div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px"><div><div class="fl">Remitente</div><div class="fv" style="background:var(--bg4)"><?php echo htmlspecialchars($row['remitente']??'—');?></div></div><div><div class="fl">Fecha</div><div class="fv" style="background:var(--bg4)"><?php echo $row['fecha']?date('d/m/Y H:i',strtotime($row['fecha'])):'—';?></div></div></div><div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px"><div><div class="fl">Nivel urgencia</div><div class="fv" style="background:var(--bg4)"><?php echo $row['nivel_urgencia'] ?? 'No especificado';?></div></div><div><div class="fl">Tipo reporte</div><div class="fv" style="background:var(--bg4)"><?php echo $row['tipo_reporte'] ?? 'Incidencia';?></div></div></div><div class="fl">Descripción</div><div class="fv" style="background:var(--bg4); margin-bottom:14px"><?php echo nl2br(htmlspecialchars($row['descripcion']??'(sin texto)'));?></div><?php if(!empty($row['foto_url'])||!empty($row['evidencia_cierre_url'])): ?><div class="fl">Imágenes</div><div class="mig" style="margin-bottom:14px"><?php if(!empty($row['foto_url'])): ?><div><div class="fl" style="margin-bottom:4px">Inicial</div><a href="<?php echo htmlspecialchars($row['foto_url']);?>" target="_blank"><img src="<?php echo htmlspecialchars($row['foto_url']);?>" style="max-height:80px"></a></div><?php endif; ?><?php if(!empty($row['evidencia_cierre_url'])): ?><div><div class="fl" style="margin-bottom:4px">Cierre</div><a href="<?php echo htmlspecialchars($row['evidencia_cierre_url']);?>" target="_blank"><img src="<?php echo htmlspecialchars($row['evidencia_cierre_url']);?>" style="max-height:80px"></a></div><?php endif; ?></div><?php endif; ?><?php if($row['estatus']==='Terminado'&&!empty($row['observaciones_cierre'])): ?><div class="fl">Cierre — <?php echo htmlspecialchars($row['nombre_tecnico']??'');?></div><div class="fv" style="background:var(--success-l);color:var(--success); margin-bottom:14px"><?php echo htmlspecialchars($row['observaciones_cierre']);?></div><?php endif; ?><?php if(can('edit')): ?><div style="border-top:1px solid var(--border); padding-top:14px; margin-top:6px"><div class="fl" style="margin-bottom:8px">✏️ Editar reporte</div><form method="POST" class="modal-edit-form" data-id="<?php echo $id;?>"><input type="hidden" name="id" value="<?php echo $id;?>"><div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px"><select name="nuevo_estado" class="fs" style="background:var(--bg2);"><option value="Pendiente" <?php if($row['estatus']==='Pendiente') echo 'selected';?>>🔴 Pendiente</option><option value="En Proceso" <?php if($row['estatus']==='En Proceso') echo 'selected';?>>🟡 En proceso</option><option value="Terminado" <?php if($row['estatus']==='Terminado') echo 'selected';?>>🟢 Terminado</option></select><select name="categoria_manual" class="fs" style="background:var(--bg2);" id="modalCatSelect<?php echo $id;?>"><?php foreach($cats as $c=>$ico): ?><option value="<?php echo $c;?>" <?php if($row['categoria']===$c) echo 'selected';?>><?php echo $ico.' '.$c;?></option><?php endforeach;?></select></div><div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px"><select name="prioridad" class="fs" style="background:var(--bg2);"><option value="Normal" <?php if(($row['prioridad']??'Normal')==='Normal') echo 'selected';?>>Normal</option><option value="Urgente" <?php if(($row['prioridad']??'Normal')==='Urgente') echo 'selected';?>>🚨 Urgente</option></select><select name="tecnico_asignado" class="fs" style="background:var(--bg2);" id="modalTecSelect<?php echo $id;?>"><option value="">-- Seleccionar técnico --</option><?php foreach($tecnicos_lista as $tec): ?><option value="<?php echo htmlspecialchars($tec['nombre']); ?>" <?php echo ($row['tecnico_asignado']??'')===$tec['nombre']?'selected':''; ?> data-especialidad="<?php echo htmlspecialchars($tec['especialidad'] ?? ''); ?>"><?php echo htmlspecialchars($tec['nombre']); ?><?php if(!empty($tec['especialidad'])): ?> (<?php echo htmlspecialchars($tec['especialidad']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div><div class="whatsapp-hint-modal"></div><div id="sugerenciaTecnico<?php echo $id;?>" class="sugerencia-badge" style="display:none; margin-bottom:8px;"></div><button type="submit" name="actualizar_estado" class="bok" style="width:100%; padding:8px">💾 Guardar cambios</button></form><?php if(can('close')&&$row['estatus']!=='Terminado'): ?><div style="margin-top:10px"><button class="bclose" onclick="toggleCModal(<?php echo $id;?>)">✅ Documentar cierre</button><div class="coll" id="colModal<?php echo $id;?>" style="margin-top:8px"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="id" value="<?php echo $id;?>"><select class="fi" onchange="document.querySelector('#obsModal<?php echo $id;?>').value=this.value"><option value="">📋 Plantilla...</option><option value="Se realizó mantenimiento preventivo, equipo funcionando correctamente.">🔧 Mantenimiento preventivo</option><option value="Se reemplazó el componente dañado, sistema operativo.">🔄 Reemplazo de componente</option><option value="Se reinició el sistema, todo ok.">🖥️ Reinicio</option><option value="Se actualizó firmware, pendiente monitoreo.">📦 Actualización</option><option value="Requiere refacción, pendiente cotización.">⏳ Esperando refacción</option></select><input type="text" name="tecnico" class="fi" placeholder="Técnico responsable" required><textarea name="observaciones" id="obsModal<?php echo $id;?>" class="fta" placeholder="Trabajos realizados..."></textarea><input type="file" name="evidencia" class="fi" accept="image/*"><button type="submit" name="finalizar" class="bok" style="width:100%;padding:8px">Cerrar reporte</button></form></div></div><?php endif; ?></div><?php endif; ?></div></div></div>
<?php endforeach; endif; ?>

<!-- Modal para reporte personalizado -->
<div class="ov" id="modalFechas" onclick="if(event.target===this)closeModalFechas()">
    <div class="modal" style="max-width: 450px;">
        <div class="mh">
            <div class="mt">📅 Reporte personalizado</div>
            <button class="mx" onclick="closeModalFechas()">✕</button>
        </div>
        <div class="mb">
            <div class="fl">Fecha inicio</div>
            <input type="date" id="fechaInicio" class="fs" style="margin-bottom: 12px;" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
            <div class="fl">Fecha fin</div>
            <input type="date" id="fechaFin" class="fs" style="margin-bottom: 12px;" value="<?php echo date('Y-m-d'); ?>">
            <div class="fl">Residencial (opcional)</div>
            <select id="fechaResidencial" class="fs" style="margin-bottom: 12px;">
                <option value="">Todos</option>
                <?php foreach($residenciales as $res): ?>
                <option value="<?php echo $res; ?>" <?php echo $f_residencial===$res?'selected':''; ?>><?php echo $res; ?></option>
                <?php endforeach; ?>
            </select>
            <div class="fl">Tipo de reporte (opcional)</div>
            <select id="fechaTipo" class="fs" style="margin-bottom: 16px;">
                <option value="">Todos</option>
                <?php foreach($tipos_reporte as $tipo): ?>
                <option value="<?php echo $tipo; ?>"><?php echo $tipo; ?></option>
                <?php endforeach; ?>
            </select>
            <div class="fl">Agrupar por</div>
            <select id="agruparPor" class="fs" style="margin-bottom: 16px;">
                <option value="mes">📅 Por mes</option>
                <option value="tipo">📂 Por tipo de reporte</option>
                <option value="nivel">⚠️ Por nivel de urgencia</option>
                <option value="residencial">🏘️ Por residencial</option>
            </select>
            <button class="bok" style="width:100%;" onclick="generarReportePersonalizado()">Generar reporte</button>
        </div>
    </div>
</div>

<!-- Modal para mostrar resultados -->
<div class="ov" id="modalResultados" onclick="if(event.target===this)closeModalResultados()">
    <div class="modal" style="max-width: 550px;">
        <div class="mh">
            <div class="mt" id="resultadosTitulo">Resultados</div>
            <button class="mx" onclick="closeModalResultados()">✕</button>
        </div>
        <div class="mb" id="resultadosContenido">
            <div style="text-align:center; padding:20px;">Cargando...</div>
        </div>
    </div>
</div>

<div class="extra-card" style="grid-column: span 2; margin-top: 14px;"><div class="ph"><div class="pt">💬 COMUNICADOS INTERNOS</div><div style="display: flex; gap: 6px; flex-wrap: wrap;"><span class="pill" style="background:var(--accent-l);color:var(--accent)" id="comunicados-contador">0 nuevos</span><button class="tb-btn" onclick="marcarComunicadosLeidos()">✓ Marcar leídos</button><button class="tb-btn" onclick="cargarComunicados()">🔄 Actualizar</button></div></div><div id="comunicados-lista" style="max-height: 350px; overflow-y: auto; margin-top: 10px;"><div style="text-align:center; padding:40px; color:var(--text3)"><div class="empty-ico">💬</div><div>Cargando comunicados...</div></div></div></div>

</main>
</div>

<div class="toast" id="toast"></div>

<script>
// Gráficas
const mesLabels=<?php echo json_encode(array_column($mes,'label')); ?>;
const mesData=<?php echo json_encode(array_column($mes,'count')); ?>;
new Chart(document.getElementById('chartMensual'),{type:'line',data:{labels:mesLabels,datasets:[{label:'Reportes',data:mesData,borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,0.1)',fill:true,tension:0.3}]},options:{responsive:true,maintainAspectRatio:true}});

const catLabels=<?php echo json_encode(array_column($categorias_stats,'label')); ?>;
const catData=<?php echo json_encode(array_column($categorias_stats,'count')); ?>;
const totalCat=catData.reduce((a,b)=>a+b,0);
if(catLabels.length){
    new Chart(document.getElementById('chartCategorias'),{
        type:'pie',
        data:{labels:catLabels,datasets:[{data:catData,backgroundColor:['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed'],borderWidth:0}]},
        options:{
            responsive:true,
            maintainAspectRatio:true,
            plugins:{
                tooltip:{callbacks:{label:function(context){const label=context.label||'';const value=context.raw||0;const percentage=Math.round((value/totalCat)*100);return`${label}: ${value} (${percentage}%)`;}}}
            }
        }
    });
}

function tick(){document.getElementById('clock').textContent=new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'});}
setInterval(tick,1000);tick();

function openM(id){document.getElementById('m'+id).classList.add('open');document.body.style.overflow='hidden';}
function closeM(id){document.getElementById('m'+id).classList.remove('open');document.body.style.overflow='';}
function toggleC(id){document.getElementById('col'+id).classList.toggle('open');}
function toggleCModal(id){document.getElementById('colModal'+id).classList.toggle('open');}
function toast(msg,dur=3500){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('on');setTimeout(()=>t.classList.remove('on'),dur);}
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
document.addEventListener('click',function(e){const sidebar=document.getElementById('sidebar');if(sidebar.classList.contains('open')&&!sidebar.contains(e.target)&&!e.target.closest('.menu-toggle')){sidebar.classList.remove('open');}});

function abrirModalFechas(){document.getElementById('modalFechas').classList.add('open');}
function closeModalFechas(){document.getElementById('modalFechas').classList.remove('open');}
function closeModalResultados(){document.getElementById('modalResultados').classList.remove('open');}

async function generarResumenSemanal(){
    const residencial = '<?php echo $f_residencial; ?>';
    let url = 'obtener_resumen_semanal.php';
    if(residencial) url += `?residencial=${encodeURIComponent(residencial)}`;
    try{
        const response=await fetch(url);
        const data=await response.json();
        if(data.error){toast('⚠️ Error al generar resumen');return;}
        let html=`<div class="resumen-stats"><div class="resumen-card"><div class="resumen-numero">${data.total}</div><div class="resumen-label">Total reportes</div></div><div class="resumen-card"><div class="resumen-numero">${data.pendiente}</div><div class="resumen-label">Pendientes</div></div><div class="resumen-card"><div class="resumen-numero">${data.proceso}</div><div class="resumen-label">En proceso</div></div><div class="resumen-card"><div class="resumen-numero">${data.terminado}</div><div class="resumen-label">Terminados</div></div><div class="resumen-card"><div class="resumen-numero">${data.urgente}</div><div class="resumen-label">Urgentes</div></div></div><div class="fl" style="margin-top:8px;">📂 Por categoría</div><div class="fv" style="background:var(--bg4);">`;
        for(const cat of data.por_categoria){html+=`<div style="display:flex; justify-content:space-between; padding:4px 0;"><span>${cat.categoria}</span><strong>${cat.total}</strong></div>`;}
        html+=`</div><div class="fl" style="margin-top:8px;">📅 Período: ${data.inicio} al ${data.fin}</div>`;
        if(data.residencial) html+=`<div class="fl" style="margin-top:8px;">📍 Residencial: ${data.residencial}</div>`;
        document.getElementById('resultadosTitulo').innerHTML='📊 Resumen de la semana';
        document.getElementById('resultadosContenido').innerHTML=html;
        document.getElementById('modalResultados').classList.add('open');
    }catch(error){console.error('Error:',error);toast('⚠️ Error al generar resumen');}
}

async function generarReportePersonalizado(){
    const inicio=document.getElementById('fechaInicio').value;
    const fin=document.getElementById('fechaFin').value;
    const residencial=document.getElementById('fechaResidencial').value;
    const tipoFiltro=document.getElementById('fechaTipo').value;
    const agrupar=document.getElementById('agruparPor').value;
    if(!inicio||!fin){toast('⚠️ Selecciona ambas fechas');return;}
    closeModalFechas();
    document.getElementById('resultadosTitulo').innerHTML='📅 Generando reporte...';
    document.getElementById('resultadosContenido').innerHTML='<div style="text-align:center; padding:20px;">Cargando...</div>';
    document.getElementById('modalResultados').classList.add('open');
    try{
        let url=`obtener_reporte_estadistico.php?inicio=${inicio}&fin=${fin}&agrupar=${agrupar}`;
        if(residencial) url+=`&residencial=${encodeURIComponent(residencial)}`;
        if(tipoFiltro) url+=`&tipo_reporte=${encodeURIComponent(tipoFiltro)}`;
        const response=await fetch(url);
        const data=await response.json();
        if(data.error){document.getElementById('resultadosContenido').innerHTML='<div style="text-align:center; padding:20px; color:var(--danger)">Error al cargar datos</div>';return;}
        
        let html=`<div class="resumen-stats"><div class="resumen-card"><div class="resumen-numero">${data.total}</div><div class="resumen-label">Total reportes</div></div><div class="resumen-card"><div class="resumen-numero">${data.pendiente}</div><div class="resumen-label">Pendientes</div></div><div class="resumen-card"><div class="resumen-numero">${data.proceso}</div><div class="resumen-label">En proceso</div></div><div class="resumen-card"><div class="resumen-numero">${data.terminado}</div><div class="resumen-label">Terminados</div></div><div class="resumen-card"><div class="resumen-numero">${data.urgente}</div><div class="resumen-label">Urgentes</div></div></div>`;
        
        if(agrupar==='mes' && data.por_mes){
            html+=`<div class="fl" style="margin-top:12px;">📅 Distribución por mes</div><div class="fv" style="background:var(--bg4);">`;
            for(const item of data.por_mes){
                html+=`<div style="display:flex; justify-content:space-between; padding:4px 0;"><span>📅 ${item.mes}</span><strong>${item.total}</strong></div>`;
            }
            html+=`</div>`;
        }else if(agrupar==='tipo' && data.por_tipo_reporte){
            html+=`<div class="fl" style="margin-top:12px;">📂 Distribución por tipo de reporte</div><div class="fv" style="background:var(--bg4);">`;
            for(const item of data.por_tipo_reporte){
                const icon=item.tipo_reporte==='Incidencia'?'⚡':(item.tipo_reporte==='Preventivo'?'🛡️':'🔧');
                html+=`<div style="display:flex; justify-content:space-between; padding:4px 0;"><span>${icon} ${item.tipo_reporte}</span><strong>${item.total}</strong></div>`;
            }
            html+=`</div>`;
        }else if(agrupar==='nivel' && data.por_nivel){
            html+=`<div class="fl" style="margin-top:12px;">⚠️ Distribución por nivel de urgencia</div><div class="fv" style="background:var(--bg4);">`;
            for(const item of data.por_nivel){
                const icon=item.nivel==='Nivel 1'?'🔴':(item.nivel==='Nivel 2'?'🟠':'🟡');
                html+=`<div style="display:flex; justify-content:space-between; padding:4px 0;"><span>${icon} ${item.nivel}</span><strong>${item.total}</strong></div>`;
            }
            html+=`</div>`;
        }else if(agrupar==='residencial' && data.por_residencial){
            html+=`<div class="fl" style="margin-top:12px;">🏘️ Distribución por residencial</div><div class="fv" style="background:var(--bg4);">`;
            for(const item of data.por_residencial){
                html+=`<div style="display:flex; justify-content:space-between; padding:4px 0;"><span>📍 ${item.residencial}</span><strong>${item.total}</strong></div>`;
            }
            html+=`</div>`;
        }
        
        html+=`<div class="fl" style="margin-top:12px;">📅 Período: ${data.inicio} al ${data.fin}</div>`;
        if(data.residencial) html+=`<div class="fl" style="margin-top:8px;">📍 Residencial: ${data.residencial}</div>`;
        html+=`<div style="margin-top:12px;"><button class="bok" style="width:100%;" onclick="exportarFechas('${inicio}','${fin}','${residencial}','${tipoFiltro}')">⬇ Exportar a CSV</button></div>`;
        
        document.getElementById('resultadosTitulo').innerHTML=`📅 Reporte estadístico ${data.inicio} al ${data.fin}`;
        document.getElementById('resultadosContenido').innerHTML=html;
    }catch(error){console.error('Error:',error);document.getElementById('resultadosContenido').innerHTML='<div style="text-align:center; padding:20px; color:var(--danger)">Error al cargar datos</div>';}
}

function exportarFechas(inicio,fin,residencial,tipo){
    let url=`?export=csv&f_fecha_ini=${inicio}&f_fecha_fin=${fin}`;
    if(residencial) url+=`&f_residencial=${encodeURIComponent(residencial)}`;
    if(tipo) url+=`&f_tipo=${encodeURIComponent(tipo)}`;
    window.location.href=url;
}

function sugerirTecnicoPorCategoria(categoria,selectId,sugerenciaId){
    const mapeo={'CCTV':['Eduardo','Israel'],'Redes':['Israel','Eduardo'],'Perímetro':['Cristian','Eduardo'],'Alarma':['Cristian'],'Accesos':['Eduardo'],'General':['Eduardo','Cristian','Israel']};
    const sugeridos=mapeo[categoria]||[];
    const select=document.getElementById(selectId);
    const sugerenciaDiv=document.getElementById(sugerenciaId);
    if(sugeridos.length>0&&select){
        for(let i=0;i<select.options.length;i++){const opt=select.options[i];if(sugeridos.includes(opt.value)){opt.style.backgroundColor='var(--accent-l)';opt.style.fontWeight='bold';}else{opt.style.backgroundColor='';opt.style.fontWeight='';}}
        if(sugerenciaDiv){sugerenciaDiv.style.display='block';sugerenciaDiv.innerHTML=`💡 Técnico sugerido: ${sugeridos.join(', ')}`;sugerenciaDiv.style.background='var(--accent-l)';sugerenciaDiv.style.color='var(--accent)';sugerenciaDiv.style.padding='4px 8px';sugerenciaDiv.style.borderRadius='12px';sugerenciaDiv.style.fontSize='10px';sugerenciaDiv.style.marginBottom='8px';}
    }else if(sugerenciaDiv){sugerenciaDiv.style.display='none';}
}

document.querySelectorAll('.reporte-form').forEach(form=>{const id=form.dataset.id;const catSelect=document.getElementById(`catSelect${id}`);const tecSelect=document.getElementById(`tecSelect${id}`);if(catSelect&&tecSelect){const sugerenciaId=`sugerenciaTecnico${id}`;let sugerenciaDiv=document.getElementById(sugerenciaId);if(!sugerenciaDiv){sugerenciaDiv=document.createElement('div');sugerenciaDiv.id=sugerenciaId;sugerenciaDiv.className='sugerencia-badge';sugerenciaDiv.style.display='none';tecSelect.parentNode.insertBefore(sugerenciaDiv,tecSelect);}
catSelect.addEventListener('change',()=>{sugerirTecnicoPorCategoria(catSelect.value,`tecSelect${id}`,sugerenciaId);});
sugerirTecnicoPorCategoria(catSelect.value,`tecSelect${id}`,sugerenciaId);}});

document.querySelectorAll('.modal-edit-form').forEach(form=>{const id=form.dataset.id;const catSelect=document.getElementById(`modalCatSelect${id}`);const tecSelect=document.getElementById(`modalTecSelect${id}`);if(catSelect&&tecSelect){const sugerenciaId=`sugerenciaTecnico${id}`;let sugerenciaDiv=document.getElementById(sugerenciaId);if(!sugerenciaDiv){sugerenciaDiv=document.createElement('div');sugerenciaDiv.id=sugerenciaId;sugerenciaDiv.className='sugerencia-badge';sugerenciaDiv.style.display='none';tecSelect.parentNode.insertBefore(sugerenciaDiv,tecSelect);}
catSelect.addEventListener('change',()=>{sugerirTecnicoPorCategoria(catSelect.value,`modalTecSelect${id}`,sugerenciaId);});
sugerirTecnicoPorCategoria(catSelect.value,`modalTecSelect${id}`,sugerenciaId);}});

document.querySelectorAll('.reporte-form').forEach(form=>{const estadoSelect=form.querySelector('select[name="nuevo_estado"]');const id=form.dataset.id;if(estadoSelect&&estadoSelect.value==='En Proceso'){let start=Date.now();setInterval(()=>{const span=document.getElementById(`crono-tiempo-${id}`);if(span)span.textContent=Math.floor((Date.now()-start)/60000);},60000);}});

<?php if(isset($_COOKIE['alert_done'])): setcookie('alert_done','',time()-1,'/'); ?>toast('✅ Reporte cerrado correctamente');<?php endif; ?>
const urgentes=<?php echo $stats_filtrados['urgente'];?>;if(urgentes>0)document.title=`(${urgentes}) Commune — Gestión Cancún<?php echo $titulo_residencial; ?>`;
if('serviceWorker' in navigator)navigator.serviceWorker.register('sw.js').catch(e=>console.log('SW error:',e));

let lastCount=<?php echo $initial_total; ?>;
setInterval(()=>{fetch('verificar_nuevos_reports.php').then(r=>r.json()).then(data=>{if(data.total>lastCount){const nuevos=data.total-lastCount;toast(`📢 ${nuevos} nuevo(s) reporte(s)`);if('Notification' in window&&Notification.permission==='granted'){new Notification('Commune',{body:`${nuevos} nuevo(s) reporte(s)`,icon:'icons/icon-192.png'});}lastCount=data.total;fetch('?get_urgent_count=1').then(r=>r.json()).then(urg=>{if(urg>0)document.title=`(${urg}) Commune`;else document.title='Commune — Gestión Cancún';});}});},15000);
if('Notification' in window&&Notification.permission!=='granted'&&Notification.permission!=='denied'){setTimeout(()=>Notification.requestPermission(),5000);}

document.addEventListener('change',(e)=>{if(e.target.name==='tecnico_asignado'&&e.target.closest('.modal')){const opt=e.target.options[e.target.selectedIndex];const wa=opt.getAttribute('data-whatsapp');const p=e.target.closest('.mb');if(p){let h=p.querySelector('.whatsapp-hint-modal');if(h){if(wa)h.innerHTML=`📱 WhatsApp: ${wa}`;else h.innerHTML='';}}}});

let tecnicosData=[];
async function cargarTecnicos(){try{const r=await fetch('obtener_tecnicos.php');const d=await r.json();if(!d.error)tecnicosData=d;}catch(e){}}
async function cargarComunicados(){try{const r=await fetch('obtener_comunicados.php?limite=50');const d=await r.json();if(d.error)return;const nl=d.filter(c=>!c.leido).length;const cs=document.getElementById('comunicados-contador');if(cs){cs.innerHTML=`${nl} nuevo${nl!==1?'s':''}`;cs.style.background=nl>0?'var(--danger-l)':'var(--accent-l)';cs.style.color=nl>0?'var(--danger)':'var(--accent)';}const ld=document.getElementById('comunicados-lista');if(!ld)return;if(d.length===0){ld.innerHTML='<div style="text-align:center;padding:40px;color:var(--text3)"><div class="empty-ico">💬</div><div>No hay comunicados</div></div>';return;}let h='<div style="display:flex;flex-direction:column;gap:6px;">';for(const c of d){const nlClass=!c.leido?'style="border-left:3px solid var(--danger);background:var(--danger-l);"':'';h+=`<div class="desc-item" ${nlClass}><div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px;"><strong style="color:var(--accent);font-size:11px;">👤 ${escapeHtml(c.remitente)}</strong><small style="color:var(--text3);font-size:9px;">${c.fecha}</small></div><div style="margin-top:4px;color:var(--text2);font-size:11px;line-height:1.4;">${c.mensaje}</div>${c.tiene_media&&c.foto_url?`<div style="margin-top:4px;"><a href="${c.foto_url}" target="_blank" style="color:var(--accent);font-size:10px;">📷 Ver imagen</a></div>`:''}${!c.leido?`<div style="margin-top:4px;font-size:9px;color:var(--danger);">🔴 Nuevo</div>`:''}</div>`;}h+='</div>';ld.innerHTML=h;}catch(e){}}
async function marcarComunicadosLeidos(){try{await fetch('obtener_comunicados.php?marcar_leidos=1');toast('✅ Comunicados marcados como leídos');cargarComunicados();}catch(e){}}
function escapeHtml(t){if(!t)return '';const d=document.createElement('div');d.textContent=t;return d.innerHTML;}
function iniciarPollingComunicados(){setInterval(()=>{cargarComunicados();},15000);}
document.addEventListener('DOMContentLoaded',()=>{cargarTecnicos();cargarComunicados();iniciarPollingComunicados();});
</script>
</body>
</html>
