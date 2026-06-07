<?php
// ── DB ────────────────────────────────────────────────────
$host = "localhost"; $user = "thenetgu_reportes";
$pass = "thenetgu_reportes"; $db = "thenetgu_reportes";
$conn = new mysqli($host,$user,$pass,$db);
$conn->set_charset("utf8mb4");
if($conn->connect_error) die("Error: ".$conn->connect_error);

// ── Roles (login desactivado — listo para activar) ────────
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
    $conn->query("UPDATE reportes SET estatus='$est',categoria='$cat',prioridad='$prior',tecnico_asignado='$tec' WHERE id=$id");
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}
if(can('close') && isset($_POST['finalizar'])){
    $id  = (int)$_POST['id'];
    $tec = $conn->real_escape_string($_POST['tecnico']);
    $obs = $conn->real_escape_string($_POST['observaciones']);
    $fin = date('Y-m-d H:i:s');
    $ruta = '';
    if(!empty($_FILES['evidencia']['name'])){
        $ext  = pathinfo($_FILES['evidencia']['name'],PATHINFO_EXTENSION);
        $nom  = 'fin_'.time().'.'.$ext;
        $ruta = 'uploads/'.$nom;
        move_uploaded_file($_FILES['evidencia']['tmp_name'],$ruta);
    }
    $stmt = $conn->prepare("UPDATE reportes SET estatus='Terminado',nombre_tecnico=?,observaciones_cierre=?,fecha_terminado=?,evidencia_cierre_url=? WHERE id=?");
    $stmt->bind_param("ssssi",$tec,$obs,$fin,$ruta,$id);
    $stmt->execute();
    setcookie('alert_done','1',time()+10,'/');
    header("Location: index.php"); exit;
}

// ── Filtros ───────────────────────────────────────────────
$f_estado    = $conn->real_escape_string($_GET['f_estado']??'');
$f_cat       = $conn->real_escape_string($_GET['f_cat']??'');
$f_fecha_ini = $conn->real_escape_string($_GET['f_fecha_ini']??'');
$f_fecha_fin_f = $conn->real_escape_string($_GET['f_fecha_fin']??'');
$f_search    = $conn->real_escape_string($_GET['f_search']??'');
$f_prior     = $conn->real_escape_string($_GET['f_prior']??'');

$where = "WHERE 1=1";
if($f_estado)      $where .= " AND estatus='$f_estado'";
if($f_cat)         $where .= " AND categoria='$f_cat'";
if($f_prior)       $where .= " AND prioridad='$f_prior'";
if($f_fecha_ini)   $where .= " AND DATE(fecha)>='$f_fecha_ini'";
if($f_fecha_fin_f) $where .= " AND DATE(fecha)<='$f_fecha_fin_f'";
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

// ── Gráfica 7 días ────────────────────────────────────────
$semana = [];
for($i=6;$i>=0;$i--){
    $dia = date('Y-m-d',strtotime("-$i days"));
    $semana[] = [
        'label' => date('D',strtotime("-$i days")),
        'date'  => $dia,
        'count' => (int)q($conn,"SELECT COUNT(*) c FROM reportes WHERE DATE(fecha)='$dia'")['c']
    ];
}
$counts  = array_column($semana,'count');
$maxVal  = max(max($counts),1);

// ── Residenciales ─────────────────────────────────────────
$residenciales = ['RIO','Cumbres','Via Cumbres','Aqua','Palmaris','Arbolada','Altai','Kyra'];
$cats = ['CCTV'=>'📷','Redes'=>'🌐','Perímetro'=>'⚡','Accesos'=>'🔑','Alarma'=>'🚨','General'=>'📋'];

// ── Carga de trabajo por técnico ──────────────────────────
$carga_tecnicos = [];
$res_tec = $conn->query("SELECT tecnico_asignado, SUM(CASE WHEN estatus!='Terminado' THEN 1 ELSE 0 END) as pendientes, SUM(CASE WHEN prioridad='Urgente' AND estatus!='Terminado' THEN 1 ELSE 0 END) as urgentes, SUM(CASE WHEN estatus='Terminado' THEN 1 ELSE 0 END) as terminados FROM reportes WHERE tecnico_asignado != '' AND tecnico_asignado IS NOT NULL GROUP BY tecnico_asignado ORDER BY pendientes DESC");
if($res_tec) while($r=$res_tec->fetch_assoc()) $carga_tecnicos[] = $r;

// ── Basurero ──────────────────────────────────────────────
$tiene_basurero = false;
$total_descartados = 0;
$check = $conn->query("SHOW TABLES LIKE 'reportes_descartados'");
if($check && $check->num_rows > 0){
    $tiene_basurero = true;
    $total_descartados = q($conn,"SELECT COUNT(*) c FROM reportes_descartados")['c'];
}

// ── Export CSV ────────────────────────────────────────────
if(isset($_GET['export']) && $_GET['export']==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reportes_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['ID','Fecha','Remitente','Descripcion','Categoria','Prioridad','Estatus','Tecnico','Fecha Cierre','Observaciones']);
    $r = $conn->query("SELECT * FROM reportes $where ORDER BY fecha DESC");
    while($row=$r->fetch_assoc())
        fputcsv($out,[$row['id'],$row['fecha'],$row['remitente'],$row['descripcion'],$row['categoria'],$row['prioridad']??'Normal',$row['estatus'],$row['nombre_tecnico']??'',$row['fecha_terminado']??'',$row['observaciones_cierre']??'']);
    fclose($out); exit;
}

$total_fil = q($conn,"SELECT COUNT(*) c FROM reportes $where")['c'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Commune">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icons/icon-192.png">
<title>Commune — Gestión Cancún</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
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
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased}

/* Topbar Responsiva */
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 15px;min-height:58px;height:auto;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:100;box-shadow:var(--sh);flex-wrap:wrap;padding-top:6px;padding-bottom:6px;}
.logo{width:34px;height:34px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0;box-shadow:0 2px 8px rgba(37,99,235,.3)}
.brand(line-height:1.2)
.brand-name{font-weight:700;font-size:14px;color:var(--text)}
.brand-sub{font-size:10px;color:var(--text3);font-weight:400}
.tb-divider{width:1px;height:22px;background:var(--border)}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end;}
.pill{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:500;border:1px solid}
.pill-live{background:var(--success-l);border-color:#bbf7d0;color:var(--success)}
.pill-clock{background:var(--bg3);border-color:var(--border);color:var(--text2);font-family:'JetBrains Mono',monospace;font-size:11px}
.pill-role-admin{background:var(--accent-l);border-color:#bfdbfe;color:var(--accent-d)}
.pill-role-tecnico{background:var(--success-l);border-color:#bbf7d0;color:var(--success)}
.pill-role-seguridad{background:var(--purple-l);border-color:#e9d5ff;color:var(--purple)}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--success);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}
.tb-btn{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:6px 11px;font-size:12px;color:var(--text2);cursor:pointer;font-family:'Outfit',sans-serif;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:.12s;white-space:nowrap}
.tb-btn:hover{border-color:var(--accent);color:var(--accent);background:#fff}

/* Layout */
.layout{display:grid;grid-template-columns:230px 1fr;min-height:calc(100vh - 58px)}

/* Sidebar */
.sidebar{background:var(--bg2);border-right:1px solid var(--border);padding:14px 0;position:sticky;top:58px;height:calc(100vh - 58px);overflow-y:auto}
.sb-sec{padding:0 10px;margin-bottom:18px}
.sb-lbl{font-size:9px;font-weight:700;color:var(--text3);letter-spacing:.12em;text-transform:uppercase;margin-bottom:5px;padding:0 6px}
.sb-item{display:flex;align-items:center;justify-content:space-between;padding:7px 8px;border-radius:var(--r-sm);cursor:pointer;color:var(--text2);font-size:13px;text-decoration:none;transition:.1s;font-weight:400;gap:6px}
.sb-item:hover{background:var(--bg3);color:var(--text)}
.sb-item.on{background:var(--accent-l);color:var(--accent);font-weight:500}
.sb-item-l{display:flex;align-items:center;gap:7px;min-width:0}
.sb-n{font-size:11px;font-weight:600;padding:2px 6px;border-radius:20px;flex-shrink:0}
.n-r{background:var(--danger-l);color:var(--danger)}
.n-b{background:var(--accent-l);color:var(--accent)}
.n-g{background:var(--success-l);color:var(--success)}
.n-w{background:var(--warn-l);color:var(--warn)}
.n-x{background:var(--bg4);color:var(--text3)}
.sb-div{height:1px;background:var(--border);margin:6px 10px}

/* Main */
.main{padding:20px;min-width:0}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px}
.sc{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:14px 16px;box-shadow:var(--sh);display:flex;align-items:center;justify-content:space-between;gap:8px}
.sc-ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.sc-n{font-family:'JetBrains Mono',monospace;font-size:26px;font-weight:600;line-height:1}
.sc-l{font-size:10px;color:var(--text3);margin-top:3px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.s0 .sc-ico{background:#ede9fe;} .s1 .sc-ico{background:var(--danger-l);}
.s2 .sc-ico{background:var(--warn-l);} .s3 .sc-ico{background:var(--success-l);}
.s4 .sc-ico{background:var(--urgent-l)}

/* Mid */
.mid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px;box-shadow:var(--sh)}
.ph{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.pt{font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.08em}

/* Chart */
.chart{display:flex;align-items:flex-end;gap:5px;height:88px}
.bw{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px}
.bar{width:100%;border-radius:4px 4px 0 0;min-height:3px;transition:height .35s cubic-bezier(.34,1.56,.64,1)}
.bl{font-size:9px;color:var(--text3);font-family:'JetBrains Mono',monospace}
.bv{font-size:9px;font-weight:600;color:var(--text2);font-family:'JetBrains Mono',monospace;min-height:13px}

/* KPI residenciales */
.rg{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}
.rc{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:9px 10px}
.rn{font-size:9px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rnums{display:flex;align-items:center;gap:4px}
.ra{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700;color:var(--danger)}
.rsep{font-size:10px;color:var(--text3)}
.rd{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700;color:var(--success)}

/* Toolbar */
.toolbar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:12px 14px;margin-bottom:14px;box-shadow:var(--sh)}
.trow{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.sw{flex:1;min-width:160px;position:relative}
.sw input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 11px 8px 34px;color:var(--text);font-size:13px;font-family:'Outfit',sans-serif;transition:.12s}
.sw input:focus{outline:none;border-color:var(--accent);background:#fff}
.sw input::placeholder{color:var(--text3)}
.si{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px;pointer-events:none}
.fsel{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 9px;color:var(--text);font-size:13px;font-family:'Outfit',sans-serif;cursor:pointer;transition:.12s}
.fsel:focus{outline:none;border-color:var(--accent)}
.btn{padding:8px 13px;border-radius:var(--r-sm);font-size:13px;font-weight:500;cursor:pointer;border:none;font-family:'Outfit',sans-serif;transition:.12s;display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.btn-p{background:var(--accent);color:#fff}.btn-p:hover{background:var(--accent-d)}
.btn-s{background:var(--bg3);border:1px solid var(--border);color:var(--text2)}.btn-s:hover{border-color:var(--accent);color:var(--accent)}
.rc-info{font-size:11px;color:var(--text3);margin-left:auto;white-space:nowrap}

/* Cards */
.cg{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:12px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden;transition:box-shadow .15s,transform .15s}
.card:hover{box-shadow:var(--sh-md);transform:translateY(-1px)}
.card.urg{border-left:3px solid var(--urgent)}
.ct{padding:11px 13px 7px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.cbadges{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.badge{padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500}
.b-cat{background:var(--bg4);color:var(--text2);border:1px solid var(--border)}
.b-pend{background:var(--danger-l);color:var(--danger)}
.b-proc{background:var(--warn-l);color:var(--warn)}
.b-done{background:var(--success-l);color:var(--success)}
.b-urg{background:var(--urgent-l);color:var(--urgent);font-weight:600}
.ctime{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--text3);white-space:nowrap;flex-shrink:0}
.cb{padding:2px 13px 10px}
.cname{font-size:13px;font-weight:600;color:var(--accent);margin-bottom:3px}
.ctec{font-size:11px;color:var(--text3);margin-bottom:3px}
.cdesc{font-size:12px;color:var(--text2);line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;cursor:pointer;min-height:52px}
.cdesc:hover{color:var(--text)}
.cimg{width:100%;height:135px;object-fit:cover;display:block;cursor:pointer;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.csla{padding:5px 13px;font-size:11px;background:var(--bg3);border-top:1px solid var(--border);font-family:'JetBrains Mono',monospace;display:flex;align-items:center;gap:4px}
.sla-o{color:var(--warn)}.sla-d{color:var(--success)}
.sla-verde{background:#f0fdf4;border-top-color:#bbf7d0}
.sla-naranja{background:#fffbeb;border-top-color:#fde68a}
.sla-rojo{background:#fef2f2;border-top-color:#fecaca;animation:sla-pulse 2s infinite}
@keyframes sla-pulse{0%,100%{opacity:1}50%{opacity:.7}}
.sla-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-left:5px;vertical-align:middle;background:currentColor}
.sla-verde .sla-dot{background:var(--success)}
.sla-naranja .sla-dot{background:var(--warn)}
.sla-rojo .sla-dot{background:var(--danger)}
.cf{padding:10px 13px;border-top:1px solid var(--border)}
.cfr{display:grid;grid-template-columns:1fr auto auto auto;gap:5px;margin-bottom:7px;align-items:center}
.fs{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:6px 7px;color:var(--text);font-size:12px;font-family:'Outfit',sans-serif;width:100%}
.fs:focus{outline:none;border-color:var(--accent)}
.fi{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:7px 10px;color:var(--text);font-size:12px;font-family:'Outfit',sans-serif;margin-bottom:5px}
.fi:focus{outline:none;border-color:var(--accent)}
.fta{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:7px 10px;color:var(--text);font-size:12px;font-family:'Outfit',sans-serif;resize:vertical;min-height:56px;margin-bottom:5px}
.fta:focus{outline:none;border-color:var(--accent)}
.bok{background:var(--accent);color:#fff;border:none;border-radius:6px;padding:6px 11px;font-size:12px;cursor:pointer;font-weight:600;font-family:'Outfit',sans-serif}
.bclose{background:var(--success-l);border:1px solid #bbf7d0;color:var(--success);border-radius:var(--r-sm);padding:7px 11px;font-size:12px;cursor:pointer;width:100%;font-weight:500;font-family:'Outfit',sans-serif;margin-top:3px}
.bclose:hover{background:#dcfce7}
.coll{display:none;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:11px;margin-top:7px}
.coll.open{display:block}
.cbox{background:var(--success-l);border:1px solid #bbf7d0;border-radius:var(--r-sm);padding:8px 11px;font-size:12px;color:var(--success)}

/* Overlay / Modal */
.ov{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:16px}
.ov.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:660px;max-height:90vh;overflow-y:auto;box-shadow:var(--sh-md)}
.mh{padding:15px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--bg2);z-index:1}
.mt{font-size:14px;font-weight:600}
.mx{background:var(--bg3);border:none;color:var(--text2);width:27px;height:27px;border-radius:6px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center}
.mx:hover{background:var(--bg4)}
.mb{padding:18px}
.fl{font-size:10px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px}
.fv{font-size:13px;color:var(--text);background:var(--bg3);border-radius:var(--r-sm);padding:9px 11px;white-space:pre-wrap;margin-bottom:12px;line-height:1.55}
.mig{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px}
.mig img{width:100%;border-radius:var(--r-sm);border:1px solid var(--border);cursor:pointer}

/* Config panel */
.cfg-ov{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(4px);z-index:200;align-items:flex-start;justify-content:flex-end;padding:14px}
.cfg-ov.open{display:flex}
.cfg-p{background:var(--bg2);border:1px solid var(--border);border-radius:16px;width:330px;max-height:calc(100vh - 28px);overflow-y:auto;box-shadow:var(--sh-md)}
.cfg-h{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--bg2)}
.cfg-b{padding:16px}
.cfg-st{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:9px;padding-bottom:5px;border-bottom:1px solid var(--border)}
.cfg-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px}
.cfg-lbl{font-size:13px;color:var(--text2)}
.cfg-sub{font-size:11px;color:var(--text3);margin-top:1px}
.toggle{position:relative;width:38px;height:21px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.ts{position:absolute;inset:0;background:var(--border2);border-radius:11px;cursor:pointer;transition:.18s}
.ts:before{content:'';position:absolute;width:15px;height:15px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.18s;box-shadow:0 1px 3px rgba(0,0,0,.18)}
.toggle input:checked+.ts{background:var(--accent)}
.toggle input:checked+.ts:before{transform:translateX(17px)}
.cfg-inp{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 10px;color:var(--text);font-size:13px;font-family:'Outfit',sans-serif;margin-top:5px}
.cfg-inp:focus{outline:none;border-color:var(--accent)}
.cfg-hint{font-size:11px;color:var(--text3);margin-top:3px;line-height:1.4}
.cfg-save{background:var(--accent);color:#fff;border:none;border-radius:var(--r-sm);padding:10px;font-size:13px;font-weight:600;cursor:pointer;width:100%;font-family:'Outfit',sans-serif;margin-top:6px}
.cfg-save:hover{background:var(--accent-d)}

/* Toast */
.toast{position:fixed;bottom:20px;right:20px;background:var(--text);color:#fff;border-radius:10px;padding:11px 16px;font-size:13px;z-index:300;opacity:0;transform:translateY(6px);transition:.2s;pointer-events:none;max-width:300px;box-shadow:var(--sh-md)}
.toast.on{opacity:1;transform:translateY(0)}

/* Empty */
.empty{grid-column:1/-1;text-align:center;padding:56px 20px;color:var(--text3)}
.empty-ico{font-size:44px;margin-bottom:12px}

/* Responsive Rules */
@media(max-width:960px){.layout{grid-template-columns:1fr}.sidebar{display:none}.stats-row{grid-template-columns:repeat(3,1fr)}.mid{grid-template-columns:1fr}.rg{grid-template-columns:repeat(4,1fr)}}
@media(max-width:580px){.stats-row{grid-template-columns:repeat(2,1fr)}.stats-row .sc:last-child{grid-column:span 2}.pill-clock{display:none}.brand-sub{display:none}.main{padding:12px}.topbar{padding:0 12px}.rg{grid-template-columns:repeat(2,1fr)}.tb-right{width:100%;justify-content:flex-start;margin-top:4px;gap:4px;}.tb-btn span{display:none;}}
</style>
</head>
<body>

<header class="topbar">
    <div class="logo">C</div>
    <div class="brand"><div class="brand-name">Commune</div><div class="brand-sub">Gestión Cancún</div></div>
    <div class="tb-divider"></div>
    <div class="tb-right">
        <span class="pill pill-live"><span class="live-dot"></span>En vivo</span>
        <span class="pill pill-clock" id="clock">--:--</span>
        <span class="pill pill-role-<?php echo $rol_activo;?>"><?php echo $roles[$rol_activo]['label'];?></span>
        <?php if(can('config')): ?>
        <button class="tb-btn" onclick="openCfg()">⚙ Alertas</button>
        <?php endif;?>
        <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'csv']));?>" class="tb-btn">⬇ <span>CSV</span></a>
    </div>
</header>

<div class="layout">
<aside class="sidebar">
    <div class="sb-sec">
        <div class="sb-lbl">Estado</div>
        <a href="index.php" class="sb-item <?php if(!$f_estado&&!$f_prior) echo 'on';?>">
            <span class="sb-item-l">📋 Todos</span>
            <span class="sb-n n-b"><?php echo $stats['total'];?></span>
        </a>
        <a href="?f_estado=Pendiente" class="sb-item <?php if($f_estado==='Pendiente') echo 'on';?>">
            <span class="sb-item-l">🔴 Pendientes</span>
            <span class="sb-n n-r"><?php echo $stats['pendiente'];?></span>
        </a>
        <a href="?f_estado=En Proceso" class="sb-item <?php if($f_estado==='En Proceso') echo 'on';?>">
            <span class="sb-item-l">🟡 En proceso</span>
            <span class="sb-n n-w"><?php echo $stats['proceso'];?></span>
        </a>
        <a href="?f_estado=Terminado" class="sb-item <?php if($f_estado==='Terminado') echo 'on';?>">
            <span class="sb-item-l">🟢 Terminados</span>
            <span class="sb-n n-g"><?php echo $stats['terminado'];?></span>
        </a>
        <a href="?f_prior=Urgente" class="sb-item <?php if($f_prior==='Urgente') echo 'on';?>">
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
        <a href="?f_cat=<?php echo urlencode($c);?>" class="sb-item <?php if($f_cat===$c) echo 'on';?>">
            <span class="sb-item-l"><?php echo $ico.' '.$c;?></span>
            <?php if($cnt>0): ?><span class="sb-n n-x"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
    <div class="sb-div"></div>
    <div class="sb-sec">
        <div class="sb-lbl">Residencial</div>
        <?php foreach($residenciales as $res):
            $r=$conn->real_escape_string($res);
            $cnt=q($conn, "SELECT COUNT(*) c FROM reportes WHERE descripcion LIKE '%$r%' AND estatus!='Terminado'")['c'];
        ?>
        <a href="?f_search=<?php echo urlencode($res);?>" class="sb-item <?php if($f_search===$res) echo 'on';?>" style="font-size:12px">
            <span class="sb-item-l"><?php echo $res;?></span>
            <?php if($cnt>0): ?><span class="sb-n n-x"><?php echo $cnt;?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </div>
</aside>

<main class="main">

<div class="stats-row">
    <?php
    $sdata=[
        ['n'=>$stats['total'],   'l'=>'Total',      'i'=>'📊','cls'=>'s0'],
        ['n'=>$stats['pendiente'],'l'=>'Pendientes', 'i'=>'⏳','cls'=>'s1'],
        ['n'=>$stats['proceso'], 'l'=>'En proceso',  'i'=>'🔧','cls'=>'s2'],
        ['n'=>$stats['terminado'],'l'=>'Terminados', 'i'=>'✅','cls'=>'s3'],
        ['n'=>$stats['urgente'], 'l'=>'Urgentes',    'i'=>'🚨','cls'=>'s4'],
    ];
    foreach($sdata as $s): ?>
    <div class="sc <?php echo $s['cls'];?>">
        <div><div class="sc-n"><?php echo $s['n'];?></div><div class="sc-l"><?php echo $s['l'];?></div></div>
        <div class="sc-ico"><?php echo $s['i'];?></div>
    </div>
    <?php endforeach;?>
</div>

<div class="mid">
    <div class="panel">
        <div class="ph"><div class="pt">Actividad — últimos 7 días</div></div>
        <div class="chart">
            <?php foreach($semana as $d):
                $h=$d['count']>0?max(6,round(($d['count']/$maxVal)*82)):3;
                $hoy=$d['date']===date('Y-m-d');
            ?>
            <div class="bw">
                <div class="bv"><?php echo $d['count']>0?$d['count']:'';?></div>
                <div class="bar" style="height:<?php echo $h;?>px;background:<?php echo $hoy?'var(--accent)':($d['count']>0?'#93c5fd':'var(--bg4)');?>"></div>
                <div class="bl" style="<?php echo $hoy?'color:var(--accent);font-weight:700':'';?>"><?php echo $d['label'];?></div>
            </div>
            <?php endforeach;?>
        </div>
    </div>
    <div class="panel">
        <div class="ph"><div class="pt">Por residencial</div><span style="font-size:10px;color:var(--text3)">Activos / Resueltos</span></div>
        <div class="rg">
            <?php foreach($residenciales as $res):
                $r=$conn->real_escape_string($res);
                $act=q($conn, "SELECT COUNT(*) c FROM reportes WHERE descripcion LIKE '%$r%' AND estatus!='Terminado'")['c'];
                $done=q($conn, "SELECT COUNT(*) c FROM reportes WHERE descripcion LIKE '%$r%' AND estatus='Terminado'")['c'];
            ?>
            <div class="rc">
                <div class="rn"><?php echo $res;?></div>
                <div class="rnums"><span class="ra"><?php echo $act;?></span><span class="rsep">/</span><span class="rd"><?php echo $done;?></span></div>
            </div>
            <?php endforeach;?>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr<?php echo $tiene_basurero?' 1fr':'';?>;gap:14px;margin-bottom:18px">

<?php if(count($carga_tecnicos) > 0): ?>
<div class="panel">
    <div class="ph">
        <div class="pt">👷 Carga de trabajo</div>
        <span style="font-size:10px;color:var(--text3)">Pendientes / Urgentes</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach($carga_tecnicos as $tec):
            $pct = $stats['pendiente'] > 0 ? round(($tec['pendientes']/$stats['pendiente'])*100) : 0;
            $color = $tec['urgentes'] > 0 ? 'var(--danger)' : ($tec['pendientes'] > 5 ? 'var(--warn)' : 'var(--success)');
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--bg3);border-radius:8px;border:1px solid var(--border)">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--accent-l);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">👷</div>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($tec['tecnico_asignado']);?></div>
                <div style="height:4px;background:var(--border);border-radius:2px;margin-top:4px">
                    <div style="height:4px;width:<?php echo $pct;?>%;background:<?php echo $color;?>;border-radius:2px;transition:.3s"></div>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;align-items:center">
                <span style="font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:700;color:var(--danger)"><?php echo $tec['pendientes'];?></span>
                <span style="font-size:10px;color:var(--text3)">pend</span>
                <?php if($tec['urgentes'] > 0): ?>
                <span style="background:var(--urgent-l);color:var(--urgent);font-size:11px;font-weight:600;padding:2px 6px;border-radius:20px">🚨<?php echo $tec['urgentes'];?></span>
                <?php endif;?>
                <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--success)">✓<?php echo $tec['terminados'];?></span>
            </div>
        </div>
        <?php endforeach;?>
    </div>
</div>
<?php else: ?>
<div class="panel" style="display:flex;align-items:center;justify-content:center;color:var(--text3);flex-direction:column;gap:8px">
    <div style="font-size:28px">👷</div>
    <div style="font-size:12px">Sin técnicos asignados aún</div>
</div>
<?php endif;?>

<?php if($tiene_basurero): ?>
<div class="panel">
    <div class="ph">
        <div class="pt">🗑️ Basurero</div>
        <a href="basurero.php" style="font-size:11px;color:var(--accent);text-decoration:none;background:var(--accent-l);padding:3px 9px;border-radius:20px;font-weight:500">Ver todos →</a>
    </div>
    <div style="text-align:center;padding:10px 0">
        <div style="font-family:'JetBrains Mono',monospace;font-size:32px;font-weight:700;color:var(--text2)"><?php echo $total_descartados;?></div>
        <div style="font-size:11px;color:var(--text3);margin-top:4px">Mensajes descartados por la IA</div>
        <div style="font-size:11px;color:var(--text3);margin-top:2px">Revisa para auditar el filtro</div>
    </div>
    <?php
    $ult = $conn->query("SELECT texto, remitente, motivo, fecha FROM reportes_descartados ORDER BY id DESC LIMIT 3");
    if($ult && $ult->num_rows > 0): ?>
    <div style="border-top:1px solid var(--border);padding-top:10px;margin-top:4px">
        <div style="font-size:10px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:7px">Últimos descartados</div>
        <?php while($d=$ult->fetch_assoc()): ?>
        <div style="padding:6px 8px;background:var(--bg3);border-radius:6px;margin-bottom:5px;border:1px solid var(--border)">
            <div style="font-size:11px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($d['texto'] ?? '');?></div>
            <div style="font-size:10px;color:var(--text3);margin-top:2px;display:flex;gap:6px">
                <span><?php echo htmlspecialchars($d['remitente']??'');?></span>
                <span style="background:var(--bg4);padding:1px 5px;border-radius:4px"><?php echo $d['motivo'];?></span>
            </div>
        </div>
        <?php endwhile;?>
    </div>
    <?php endif;?>
</div>
<?php endif;?>

</div>

<form method="GET" id="ff">
<div class="toolbar">
    <div class="trow">
        <div class="sw">
            <span class="si">🔍</span>
            <input type="text" name="f_search" placeholder="Buscar descripción o remitente..." value="<?php echo htmlspecialchars($f_search);?>">
        </div>
        <select name="f_estado" class="fsel" onchange="document.getElementById('ff').submit()">
            <option value="">Todos los estados</option>
            <option value="Pendiente"  <?php if($f_estado==='Pendiente')  echo 'selected';?>>🔴 Pendiente</option>
            <option value="En Proceso" <?php if($f_estado==='En Proceso') echo 'selected';?>>🟡 En proceso</option>
            <option value="Terminado"  <?php if($f_estado==='Terminado')  echo 'selected';?>>🟢 Terminado</option>
        </select>
        <select name="f_cat" class="fsel" onchange="document.getElementById('ff').submit()">
            <option value="">Categoría</option>
            <?php foreach($cats as $c=>$ico): ?>
            <option value="<?php echo $c;?>" <?php if($f_cat===$c) echo 'selected';?>><?php echo $ico.' '.$c;?></option>
            <?php endforeach;?>
        </select>
        <input type="date" name="f_fecha_ini" class="fsel" value="<?php echo $f_fecha_ini;?>" title="Desde" onchange="document.getElementById('ff').submit()">
        <input type="date" name="f_fecha_fin" class="fsel" value="<?php echo $f_fecha_fin_f;?>" title="Hasta" onchange="document.getElementById('ff').submit()">
        <button type="submit" class="btn btn-p">Buscar</button>
        <a href="index.php" class="btn btn-s">Limpiar</a>
        <span class="rc-info"><?php echo $total_fil;?> resultado(s)</span>
    </div>
</div>
</form>

<div class="cg">
<?php
$sql="SELECT * FROM reportes $where ORDER BY
      CASE prioridad WHEN 'Urgente' THEN 0 ELSE 1 END,
      CASE estatus WHEN 'Pendiente' THEN 0 WHEN 'En Proceso' THEN 1 ELSE 2 END,
      fecha DESC";
$res=$conn->query($sql);
if(!$res){ echo '<div class="empty"><div class="empty-ico">⚠️</div>Error SQL: '.$conn->error.'</div>'; }
elseif($res->num_rows===0){ echo '<div class="empty"><div class="empty-ico">📭</div><p>Sin resultados con estos filtros</p></div>'; }
else { while($row=$res->fetch_assoc()):
    $id=(int)$row['id'];
    $urg=($row['prioridad']??'Normal')==='Urgente';
    $ec=$row['estatus']==='Pendiente'?'b-pend':($row['estatus']==='En Proceso'?'b-proc':'b-done');
    $sla=''; $sc='sla-o'; $sla_color='';
    if($row['fecha']){
        $fin=$row['fecha_terminado']??date('Y-m-d H:i:s');
        $h=round((strtotime($fin)-strtotime($row['fecha']))/3600,1);
        if($row['estatus']==='Terminado'){
            $sla="✓ Resuelto en {$h}h"; $sc='sla-d';
        } else {
            $sla="Abierto hace {$h}h";
            $es_urg=($row['prioridad']??'Normal')==='Urgente';
            if($es_urg){
                if($h < 2)       $sla_color='sla-verde';
                elseif($h < 4)   $sla_color='sla-naranja';
                else             $sla_color='sla-rojo';
            } else {
                if($h < 4)       $sla_color='sla-verde';
                elseif($h < 12)  $sla_color='sla-naranja';
                else             $sla_color='sla-rojo';
            }
        }
    }
?>
<div class="card <?php echo $urg?'urg':'';?>" id="c<?php echo $id;?>">
    <div class="ct">
        <div class="cbadges">
            <span class="badge b-cat"><?php echo htmlspecialchars($row['categoria']);?></span>
            <span class="badge <?php echo $ec;?>"><?php echo $row['estatus'];?></span>
            <?php if($urg): ?><span class="badge b-urg">🚨 Urgente</span><?php endif;?>
        </div>
        <div class="ctime"><?php echo $row['fecha']?date('d/m H:i',strtotime($row['fecha'])):'—';?></div>
    </div>
    <div class="cb">
        <div class="cname"><?php echo htmlspecialchars($row['remitente']?:'Sin remitente');?></div>
        <?php if(!empty($row['tecnico_asignado'])): ?>
        <div class="ctec">👷 <?php echo htmlspecialchars($row['tecnico_asignado']);?></div>
        <?php endif;?>
        <div class="cdesc" onclick="openM(<?php echo $id;?>)">
            <?php echo nl2br(htmlspecialchars($row['descripcion']?:'(sin texto)'));?>
        </div>
    </div>
    <?php if(!empty($row['foto_url'])): ?>
    <img src="<?php echo htmlspecialchars($row['foto_url']);?>" class="cimg"
         onclick="openM(<?php echo $id;?>)" onerror="this.style.display='none'" loading="lazy">
    <?php endif;?>
    <?php if($sla): ?>
    <div class="csla <?php echo $sla_color;?>"><span class="<?php echo $sc;?>"><?php echo $sla;?></span><?php if($sla_color): ?><span class="sla-dot"></span><?php endif;?></div>
    <?php endif;?>
    <?php if(can('edit')): ?>
    <div class="cf">
        <form method="POST">
            <input type="hidden" name="id" value="<?php echo $id;?>">
            <div class="cfr">
                <select name="nuevo_estado" class="fs">
                    <option value="Pendiente"  <?php if($row['estatus']==='Pendiente')  echo 'selected';?>>🔴 Pendiente</option>
                    <option value="En Proceso" <?php if($row['estatus']==='En Proceso') echo 'selected';?>>🟡 En proceso</option>
                    <option value="Terminado"  <?php if($row['estatus']==='Terminado')  echo 'selected';?>>🟢 Terminado</option>
                </select>
                <select name="categoria_manual" class="fs" style="width:auto">
                    <?php foreach($cats as $c=>$ico): ?>
                    <option value="<?php echo $c;?>" <?php if($row['categoria']===$c) echo 'selected';?>><?php echo $c;?></option>
                    <?php endforeach;?>
                </select>
                <select name="prioridad" class="fs" style="width:auto">
                    <option value="Normal"  <?php if(($row['prioridad']??'Normal')==='Normal')  echo 'selected';?>>Normal</option>
                    <option value="Urgente" <?php if(($row['prioridad']??'Normal')==='Urgente') echo 'selected';?>>🚨 Urgente</option>
                </select>
                <button type="submit" name="actualizar_estado" class="bok">OK</button>
            </div>
            <input type="text" name="tecnico_asignado" class="fi" placeholder="Asignar técnico..."
                   value="<?php echo htmlspecialchars($row['tecnico_asignado']??'');?>">
        </form>
        <?php if(can('close') && $row['estatus']!=='Terminado'): ?>
        <button class="bclose" onclick="toggleC(<?php echo $id;?>)">✅ Documentar cierre</button>
        <div class="coll" id="col<?php echo $id;?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?php echo $id;?>">
                <input type="text"  name="tecnico"       class="fi"  placeholder="Técnico responsable" required>
                <textarea           name="observaciones" class="fta" placeholder="Trabajos realizados..."></textarea>
                <input type="file"  name="evidencia"     class="fi"  accept="image/*">
                <button type="submit" name="finalizar" class="bok" style="width:100%;padding:8px">Cerrar reporte</button>
            </form>
        </div>
        <?php elseif($row['estatus']==='Terminado'): ?>
        <div class="cbox">✅ <strong><?php echo htmlspecialchars($row['nombre_tecnico']??'—');?></strong>
        <?php if(!empty($row['observaciones_cierre'])): ?> — <?php echo htmlspecialchars($row['observaciones_cierre']);?><?php endif;?></div>
        <?php endif;?>
    </div>
    <?php endif;?>
</div>

<div class="ov" id="m<?php echo $id;?>" onclick="if(event.target===this)closeM(<?php echo $id;?>)">
    <div class="modal">
        <div class="mh">
            <div class="mt">Reporte #<?php echo $id;?> — <?php echo htmlspecialchars($row['categoria']);?></div>
            <button class="mx" onclick="closeM(<?php echo $id;?>)">✕</button>
        </div>
        <div class="mb">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:4px">
                <div><div class="fl">Remitente</div><div class="fv"><?php echo htmlspecialchars($row['remitente']??'—');?></div></div>
                <div><div class="fl">Fecha</div><div class="fv"><?php echo $row['fecha']?date('d/m/Y H:i',strtotime($row['fecha'])):'—';?></div></div>
            </div>
            <div class="fl">Descripción</div>
            <div class="fv"><?php echo htmlspecialchars($row['descripcion']??'(sin texto)');?></div>
            <?php if($row['estatus']==='Terminado'): ?>
            <div class="fl">Cierre — <?php echo htmlspecialchars($row['nombre_tecnico']??'');?></div>
            <div class="fv" style="background:var(--success-l);color:var(--success)"><?php echo htmlspecialchars($row['observaciones_cierre']??'—');?></div>
            <?php endif;?>
            <?php if(!empty($row['foto_url'])||!empty($row['evidencia_cierre_url'])): ?>
            <div class="mig">
                <?php if(!empty($row['foto_url'])): ?>
                <div><div class="fl" style="margin-bottom:5px">Evidencia inicial</div>
                <a href="<?php echo htmlspecialchars($row['foto_url']);?>" target="_blank">
                    <img src="<?php echo htmlspecialchars($row['foto_url']);?>" onerror="this.parentElement.style.display='none'">
                </a></div>
                <?php endif;?>
                <?php if(!empty($row['evidencia_cierre_url'])): ?>
                <div><div class="fl" style="margin-bottom:5px;color:var(--success)">Evidencia de cierre</div>
                <a href="<?php echo htmlspecialchars($row['evidencia_cierre_url']);?>" target="_blank">
                    <img src="<?php echo htmlspecialchars($row['evidencia_cierre_url']);?>" onerror="this.parentElement.style.display='none'">
                </a></div>
                <?php endif;?>
            </div>
            <?php endif;?>
        </div>
    </div>
</div>
<?php endwhile; } ?>
</div>
</main>
</div>

<div class="cfg-ov" id="cfg-ov" onclick="if(event.target===this)closeCfg()">
<div class="cfg-p">
    <div class="cfg-h">
        <span style="font-size:14px;font-weight:600">⚙ Configuración de alertas</span>
        <button class="mx" onclick="closeCfg()">✕</button>
    </div>
    <div class="cfg-b">
        <div style="margin-bottom:16px">
            <div class="cfg-st">Tipos de alerta</div>
            <div class="cfg-row"><div><div class="cfg-lbl">Nuevo reporte</div><div class="cfg-sub">Al recibir un reporte del grupo</div></div>
                <label class="toggle"><input type="checkbox" id="cn" <?php echo $cfg['alert_nuevo']?'checked':'';?>><span class="ts"></span></label></div>
            <div class="cfg-row"><div><div class="cfg-lbl">Reporte terminado</div><div class="cfg-sub">Al cerrar un reporte</div></div>
                <label class="toggle"><input type="checkbox" id="ct2" <?php echo $cfg['alert_terminado']?'checked':'';?>><span class="ts"></span></label></div>
            <div class="cfg-row"><div><div class="cfg-lbl">Solo urgentes</div><div class="cfg-sub">Alertar solo prioridad urgente</div></div>
                <label class="toggle"><input type="checkbox" id="cu" <?php echo $cfg['alert_urgente']?'checked':'';?>><span class="ts"></span></label></div>
        </div>
        <div style="margin-bottom:16px">
            <div class="cfg-st">Push en navegador</div>
            <div class="cfg-row"><div><div class="cfg-lbl">Notificaciones push</div><div class="cfg-sub">Requiere permiso del navegador</div></div>
                <label class="toggle"><input type="checkbox" id="cp" <?php echo $cfg['push_enabled']?'checked':'';?> onchange="reqPush(this)"><span class="ts"></span></label></div>
        </div>
        <div style="margin-bottom:16px">
            <div class="cfg-st">WhatsApp</div>
            <div class="cfg-lbl" style="margin-bottom:4px">Número para alertas</div>
            <input type="text" id="cwa" class="cfg-inp" placeholder="521998XXXXXXX (con código país)" value="<?php echo htmlspecialchars($cfg['whatsapp_number']);?>">
            <div class="cfg-hint">Sin + al inicio. Deja vacío para desactivar.</div>
        </div>
        <div style="margin-bottom:16px">
            <div class="cfg-st">Email</div>
            <div class="cfg-lbl" style="margin-bottom:4px">Correo para alertas</div>
            <input type="email" id="cem" class="cfg-inp" placeholder="jefe@ejemplo.com" value="<?php echo htmlspecialchars($cfg['email']);?>">
            <div class="cfg-hint">Deja vacío para desactivar.</div>
        </div>
        <button class="cfg-save" onclick="saveCfg()">Guardar configuración</button>
    </div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
const CFG = <?php echo json_encode($cfg);?>;

// Reloj
function tick(){ document.getElementById('clock').textContent=new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}); }
setInterval(tick,1000); tick();

// Auto-refresh 30s
setInterval(()=>{ if(!document.querySelector('.ov.open')&&!document.getElementById('cfg-ov').classList.contains('open')) location.reload(); },30000);

// Modales
function openM(id){ document.getElementById('m'+id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeM(id){ document.getElementById('m'+id).classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ document.querySelectorAll('.ov.open,.cfg-ov.open').forEach(el=>el.classList.remove('open')); document.body.style.overflow=''; }});

// Cierre
function toggleC(id){ document.getElementById('col'+id).classList.toggle('open'); }

// Toast
function toast(msg,dur=3200){ const t=document.getElementById('toast'); t.textContent=msg; t.classList.add('on'); setTimeout(()=>t.classList.remove('on'),dur); }

// Config
function openCfg(){ document.getElementById('cfg-ov').classList.add('open'); }
function closeCfg(){ document.getElementById('cfg-ov').classList.remove('open'); }

// Guardar
function saveCfg(){
    const d={
        alert_nuevo:     document.getElementById('cn').checked,
        alert_terminado: document.getElementById('ct2').checked,
        alert_urgente:   document.getElementById('cu').checked,
        push_enabled:    document.getElementById('cp').checked,
        whatsapp_number: document.getElementById('cwa').value.trim(),
        email:           document.getElementById('cem').value.trim(),
    };
    fetch('save_config.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)})
        .then(r=>r.json()).then(r=>{ toast(r.ok?'✅ Configuración guardada':'⚠️ Error al guardar'); if(r.ok) closeCfg(); })
        .catch(()=>{ toast('✅ Guardado'); closeCfg(); });
}

function reqPush(el){
    if(el.checked && 'Notification' in window){
        Notification.requestPermission().then(p=>{
            if(p!=='granted'){ el.checked=false; toast('⚠️ Permiso de notificaciones denegado'); }
            else toast('✅ Notificaciones activadas');
        });
    }
}

function pushNotif(title,body){
    if(!CFG.push_enabled) return;
    if(!('Notification' in window)||Notification.permission!=='granted') return;
    try{ new Notification(title,{body,icon:'icons/icon-192.png'}); }catch(e){}
}

<?php if(isset($_COOKIE['alert_done'])): setcookie('alert_done','',time()-1,'/'); ?>
pushNotif('✅ Reporte cerrado','Un reporte fue marcado como terminado');
toast('✅ Reporte cerrado correctamente');
<?php endif;?>

const urg = <?php echo $stats['urgente'];?>;
if(urg>0) document.title=`(${urg}) Commune — Gestión Cancún`;

if('serviceWorker' in navigator){
    navigator.serviceWorker.register('sw.js')
        .then(()=>console.log('SW registrado'))
        .catch(e=>console.log('SW error:',e));
}

document.querySelector('input[name="f_search"]').addEventListener('keydown',e=>{ if(e.key==='Enter') document.getElementById('ff').submit(); });
</script>
</body>
</html>