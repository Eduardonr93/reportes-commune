<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // En producción no mostrar errores al usuario

$host = "localhost";
$user = "thenetgu_reportes";
$pass = "thenetgu_reportes";
$db   = "thenetgu_reportes";

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) { die("Error de conexión: " . $conn->connect_error); }

// 1. Actualización de estado (Cambio rápido)
if (isset($_POST['actualizar_estado'])) {
    $id           = (int)$_POST['id'];
    $nuevo_estado = $conn->real_escape_string($_POST['nuevo_estado']);
    $cat_manual   = $conn->real_escape_string($_POST['categoria_manual']);
    $conn->query("UPDATE reportes SET estatus='$nuevo_estado', categoria='$cat_manual' WHERE id=$id");
    header("Location: index.php");
    exit;
}

// 2. Cierre de reporte (Documentación final)
if (isset($_POST['finalizar'])) {
    $id        = (int)$_POST['id'];
    $tecnico   = $conn->real_escape_string($_POST['tecnico']);
    $obs       = $conn->real_escape_string($_POST['observaciones']);
    $fecha_fin = date('Y-m-d H:i:s');
    $ruta_evidencia = "";
    if (!empty($_FILES['evidencia']['name'])) {
        $ext = pathinfo($_FILES['evidencia']['name'], PATHINFO_EXTENSION);
        $nombre = "fin_" . time() . "." . $ext;
        $ruta_evidencia = "uploads/" . $nombre;
        move_uploaded_file($_FILES['evidencia']['tmp_name'], $ruta_evidencia);
    }
    $stmt = $conn->prepare("UPDATE reportes SET estatus='Terminado', nombre_tecnico=?, observaciones_cierre=?, fecha_terminado=?, evidencia_cierre_url=? WHERE id=?");
    $stmt->bind_param("ssssi", $tecnico, $obs, $fecha_fin, $ruta_evidencia, $id);
    $stmt->execute();
    header("Location: index.php");
    exit;
}

// 3. NUEVO: Lógica para Reabrir Reporte (Corrección de errores)
if (isset($_POST['reabrir_reporte'])) {
    $id = (int)$_POST['id'];
    // Regresamos el estado a 'En Proceso' y limpiamos los datos de la finalización anterior
    $conn->query("UPDATE reportes SET estatus='En Proceso', nombre_tecnico='', observaciones_cierre='', fecha_terminado=NULL, evidencia_cierre_url='' WHERE id=$id");
    header("Location: index.php");
    exit;
}

// 4. Filtros
$f_estado = isset($_GET['f_estado']) ? $conn->real_escape_string($_GET['f_estado']) : '';
$f_cat    = isset($_GET['f_cat'])    ? $conn->real_escape_string($_GET['f_cat'])    : '';
$f_fecha  = isset($_GET['f_fecha'])  ? $conn->real_escape_string($_GET['f_fecha'])  : '';

$where = "WHERE 1=1";
if ($f_estado !== '') $where .= " AND estatus = '$f_estado'";
if ($f_cat    !== '') $where .= " AND categoria = '$f_cat'";
if ($f_fecha  !== '') $where .= " AND DATE(fecha) = '$f_fecha'";

// Conteos globales
$total_pendiente  = $conn->query("SELECT COUNT(*) as c FROM reportes WHERE estatus='Pendiente'")->fetch_assoc()['c'];
$total_proceso    = $conn->query("SELECT COUNT(*) as c FROM reportes WHERE estatus='En Proceso'")->fetch_assoc()['c'];
$total_terminado  = $conn->query("SELECT COUNT(*) as c FROM reportes WHERE estatus='Terminado'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commune — Gestión Cancún</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .navbar { background: #121212; border-bottom: 3px solid #0d6efd; }
        .card-stats { border-radius: 12px; border-left: 4px solid #0d6efd !important; transition: 0.2s; cursor: help; position: relative; overflow: visible; }
        .card-stats:hover { background-color: #f1f3f5; z-index: 100; }
        .cat-detail { display: none; position: absolute; top: 100%; left: 0; z-index: 200; min-width: 220px; background: white; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); padding: 14px; }
        .card-stats:hover .cat-detail { display: block; }
        .card-reporte { border-radius: 14px; border: none; height: 100%; transition: box-shadow .2s; }
        .card-reporte:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.12) !important; }
        .bg-pendiente { border-top: 5px solid #dc3545; }
        .bg-proceso   { border-top: 5px solid #ffc107; }
        .bg-terminado { border-top: 5px solid #198754; opacity: .85; }
        .img-card { width:100%; height:160px; object-fit:cover; cursor:pointer; border-radius:8px; transition: opacity .2s; }
        .img-card:hover { opacity: .85; }
        .text-truncate-3 { display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        .badge-estado { font-size: .7rem; padding: 4px 8px; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark shadow mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand fw-bold text-uppercase mb-0">
            Commune <small class="fw-light opacity-75">| Gestión Cancún</small>
        </span>
        <div class="d-flex gap-2">
            <span class="badge bg-danger fs-6"><?php echo $total_pendiente; ?> Pendientes</span>
            <span class="badge bg-warning text-dark fs-6"><?php echo $total_proceso; ?> En Proceso</span>
            <span class="badge bg-success fs-6"><?php echo $total_terminado; ?> Terminados</span>
        </div>
    </div>
</nav>

<div class="container mb-4">
    <div class="row g-3">
        <?php
        $residenciales = ['RIO','Cumbres','Via Cumbres','Aqua','Palmaris','Arbolada','Altai','Kyra'];
        $iconos = ['CCTV'=>'📷','Redes'=>'🌐','Perímetro'=>'⚡','Accesos'=>'🔑','Alarma'=>'🚨','General'=>'📋'];

        foreach ($residenciales as $res):
            $r = $conn->real_escape_string($res);
            $np = $conn->query("SELECT COUNT(*) as c FROM reportes WHERE descripcion LIKE '%$r%' AND estatus != 'Terminado'")->fetch_assoc()['c'];
            $nt = $conn->query("SELECT COUNT(*) as c FROM reportes WHERE descripcion LIKE '%$r%' AND estatus = 'Terminado'")->fetch_assoc()['c'];
        ?>
        <div class="col-xl-3 col-md-4 col-sm-6">
            <div class="card card-stats shadow-sm border-0 bg-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark text-uppercase small"><?php echo $res; ?></span>
                    <div class="d-flex gap-1">
                        <span class="badge bg-primary rounded-pill"><?php echo $np; ?></span>
                        <span class="badge bg-success rounded-pill"><?php echo $nt; ?></span>
                    </div>
                </div>
                <div class="cat-detail border">
                    <div class="row g-0">
                        <div class="col-6 border-end pe-2">
                            <p class="small fw-bold border-bottom mb-2 pb-1 text-center text-danger">Activos</p>
                            <?php
                            $hay = false;
                            foreach ($iconos as $cat => $ico):
                                $c = $conn->real_escape_string($cat);
                                $cnt = $conn->query("SELECT COUNT(*) as c FROM reportes WHERE descripcion LIKE '%$r%' AND categoria='$c' AND estatus!='Terminado'")->fetch_assoc()['c'];
                                if ($cnt > 0): $hay = true; ?>
                                <div class="d-flex justify-content-between small px-1 mb-1">
                                    <span><?php echo $ico; ?> <?php echo $cat; ?></span>
                                    <b><?php echo $cnt; ?></b>
                                </div>
                            <?php endif; endforeach;
                            if (!$hay) echo '<p class="small text-muted text-center">—</p>'; ?>
                        </div>
                        <div class="col-6 ps-2">
                            <p class="small fw-bold border-bottom mb-2 pb-1 text-center text-success">Terminados</p>
                            <?php
                            $hay = false;
                            foreach ($iconos as $cat => $ico):
                                $c = $conn->real_escape_string($cat);
                                $cnt = $conn->query("SELECT COUNT(*) as c FROM reportes WHERE descripcion LIKE '%$r%' AND categoria='$c' AND estatus='Terminado'")->fetch_assoc()['c'];
                                if ($cnt > 0): $hay = true; ?>
                                <div class="d-flex justify-content-between small px-1 mb-1">
                                    <span><?php echo $ico; ?> <?php echo $cat; ?></span>
                                    <b><?php echo $cnt; ?></b>
                                </div>
                            <?php endif; endforeach;
                            if (!$hay) echo '<p class="small text-muted text-center">—</p>'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="container">
    <form class="row g-3 mb-4 bg-white p-3 rounded shadow-sm border align-items-end" method="GET">
        <div class="col-md-3">
            <label class="form-label small fw-bold text-uppercase">Estado</label>
            <select name="f_estado" class="form-select" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="Pendiente"  <?php if($f_estado=='Pendiente')  echo 'selected'; ?>>🔴 Pendientes</option>
                <option value="En Proceso" <?php if($f_estado=='En Proceso') echo 'selected'; ?>>🟡 En Proceso</option>
                <option value="Terminado"  <?php if($f_estado=='Terminado')  echo 'selected'; ?>>🟢 Terminados</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-uppercase">Categoría</label>
            <select name="f_cat" class="form-select" onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="CCTV"      <?php if($f_cat=='CCTV')      echo 'selected'; ?>>📷 CCTV</option>
                <option value="Redes"     <?php if($f_cat=='Redes')     echo 'selected'; ?>>🌐 Redes</option>
                <option value="Perímetro" <?php if($f_cat=='Perímetro') echo 'selected'; ?>>⚡ Perímetro</option>
                <option value="Accesos"   <?php if($f_cat=='Accesos')   echo 'selected'; ?>>🔑 Accesos</option>
                <option value="Alarma"    <?php if($f_cat=='Alarma')    echo 'selected'; ?>>🚨 Alarma</option>
                <option value="General"   <?php if($f_cat=='General')   echo 'selected'; ?>>📋 General</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-uppercase">Fecha</label>
            <input type="date" name="f_fecha" class="form-control"
                   value="<?php echo htmlspecialchars($f_fecha); ?>" onchange="this.form.submit()">
        </div>
        <div class="col-md-3">
            <a href="index.php" class="btn btn-secondary w-100 fw-bold text-uppercase">Limpiar filtros</a>
        </div>
    </form>

    <div class="row" id="reportes-grid">
        <?php
        $sql = "SELECT * FROM reportes $where ORDER BY
                CASE estatus WHEN 'Pendiente' THEN 1 WHEN 'En Proceso' THEN 2 ELSE 3 END,
                fecha DESC";
        $res = $conn->query($sql);

        if ($res->num_rows === 0) {
            echo '<div class="col-12"><div class="alert alert-info text-center">No hay reportes.</div></div>';
        } else {
            while ($row = $res->fetch_assoc()):
                $clase = $row['estatus'] === 'Pendiente' ? 'bg-pendiente' : ($row['estatus'] === 'En Proceso' ? 'bg-proceso' : 'bg-terminado');
                $badge_color = $row['estatus'] === 'Pendiente' ? 'danger' : ($row['estatus'] === 'En Proceso' ? 'warning text-dark' : 'success');
                $id = (int)$row['id'];
        ?>
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card card-reporte <?php echo $clase; ?> shadow-sm">
                <div class="card-body d-flex flex-column p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-dark text-uppercase"><?php echo htmlspecialchars($row['categoria']); ?></span>
                        <div class="text-end">
                            <span class="badge bg-<?php echo $badge_color; ?> badge-estado"><?php echo $row['estatus']; ?></span><br>
                            <small class="text-muted"><?php echo $row['fecha'] ? date('d/m/y H:i', strtotime($row['fecha'])) : '—'; ?></small>
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary mb-1"><?php echo htmlspecialchars($row['remitente'] ?: 'Sin remitente'); ?></h6>
                    <p class="small text-dark mb-2 text-truncate-3" style="cursor:pointer; min-height:60px;" data-bs-toggle="modal" data-bs-target="#modal<?php echo $id; ?>">
                        <?php echo nl2br(htmlspecialchars($row['descripcion'])); ?>
                    </p>

                    <?php if (!empty($row['foto_url'])): ?>
                    <img src="<?php echo htmlspecialchars($row['foto_url']); ?>" class="img-card mb-3 border shadow-sm" data-bs-toggle="modal" data-bs-target="#modal<?php echo $id; ?>">
                    <?php endif; ?>

                    <div class="mt-auto">
                        <form method="POST" class="row g-1 mb-2">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <div class="col-7">
                                <select name="nuevo_estado" class="form-select form-select-sm border-primary">
                                    <option value="Pendiente"  <?php if($row['estatus']=='Pendiente')  echo 'selected'; ?>>🔴 Pendiente</option>
                                    <option value="En Proceso" <?php if($row['estatus']=='En Proceso') echo 'selected'; ?>>🟡 En Proceso</option>
                                    <option value="Terminado"  <?php if($row['estatus']=='Terminado')  echo 'selected'; ?>>🟢 Terminado</option>
                                </select>
                            </div>
                            <div class="col-3">
                                <select name="categoria_manual" class="form-select form-select-sm border-primary">
                                    <option value="CCTV" <?php if($row['categoria']=='CCTV') echo 'selected'; ?>>CCTV</option>
                                    <option value="Redes" <?php if($row['categoria']=='Redes') echo 'selected'; ?>>Redes</option>
                                    <option value="Perímetro" <?php if($row['categoria']=='Perímetro') echo 'selected'; ?>>Cerco</option>
                                    <option value="Accesos" <?php if($row['categoria']=='Accesos') echo 'selected'; ?>>Acceso</option>
                                    <option value="Alarma" <?php if($row['categoria']=='Alarma') echo 'selected'; ?>>Alarma</option>
                                    <option value="General" <?php if($row['categoria']=='General') echo 'selected'; ?>>General</option>
                                </select>
                            </div>
                            <div class="col-2"><button type="submit" name="actualizar_estado" class="btn btn-sm btn-primary w-100">OK</button></div>
                        </form>

                        <?php if ($row['estatus'] !== 'Terminado'): ?>
                        <button class="btn btn-success btn-sm w-100 fw-bold" data-bs-toggle="collapse" data-bs-target="#cierre<?php echo $id; ?>">✅ DOCUMENTAR CIERRE</button>
                        <div class="collapse mt-2" id="cierre<?php echo $id; ?>">
                            <form method="POST" enctype="multipart/form-data" class="p-2 border rounded bg-light">
                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <input type="text" name="tecnico" class="form-control form-control-sm mb-1" placeholder="Nombre técnico" required>
                                <textarea name="observaciones" class="form-control form-control-sm mb-1" rows="2" placeholder="Trabajos..."></textarea>
                                <input type="file" name="evidencia" class="form-control form-control-sm mb-2" accept="image/*">
                                <button type="submit" name="finalizar" class="btn btn-sm btn-success w-100">Cerrar Reporte</button>
                            </form>
                        </div>
                        <?php else: ?>
                        <!-- Botón para Reabrir en caso de error -->
                        <div class="alert alert-light border p-2 mb-2 small text-center">
                            <strong>✅ Cerrado por:</strong> <?php echo htmlspecialchars($row['nombre_tecnico']); ?>
                        </div>
                        <form method="POST" onsubmit="return confirm('¿Seguro que quieres REABRIR este reporte? Se borrarán los datos de cierre.');">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button type="submit" name="reabrir_reporte" class="btn btn-outline-danger btn-sm w-100 fw-bold">⚠️ REABRIR / CORREGIR</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Modal detalle -->
            <div class="modal fade" id="modal<?php echo $id; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content border-0 shadow-lg">
                        <div class="modal-header bg-dark text-white">
                            <h5 class="modal-title">REPORTE #<?php echo $id; ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-7 border-end">
                                    <p><strong>Remitente:</strong> <?php echo htmlspecialchars($row['remitente'] ?: '—'); ?></p>
                                    <p><strong>Fecha:</strong> <?php echo date('d/m/Y H:i', strtotime($row['fecha'])); ?></p>
                                    <h6 class="fw-bold border-bottom pb-2 text-uppercase small">Descripción</h6>
                                    <p class="p-3 bg-light rounded" style="white-space:pre-wrap;"><?php echo htmlspecialchars($row['descripcion']); ?></p>
                                    <?php if ($row['estatus'] === 'Terminado'): ?>
                                    <h6 class="fw-bold text-success border-bottom pb-2 mt-4 text-uppercase small">Cierre</h6>
                                    <p><strong>Técnico:</strong> <?php echo htmlspecialchars($row['nombre_tecnico']); ?></p>
                                    <p><strong>Notas:</strong><br><?php echo nl2br(htmlspecialchars($row['observaciones_cierre'])); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-5 text-center">
                                    <?php if (!empty($row['foto_url'])): ?>
                                    <p class="small fw-bold text-uppercase mb-1">Evidencia Inicial</p>
                                    <a href="<?php echo htmlspecialchars($row['foto_url']); ?>" target="_blank">
                                        <img src="<?php echo htmlspecialchars($row['foto_url']); ?>" class="img-fluid rounded border mb-3 shadow-sm">
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($row['evidencia_cierre_url'])): ?>
                                    <p class="small fw-bold text-success text-uppercase mb-1">Evidencia Cierre</p>
                                    <a href="<?php echo htmlspecialchars($row['evidencia_cierre_url']); ?>" target="_blank">
                                        <img src="<?php echo htmlspecialchars($row['evidencia_cierre_url']); ?>" class="img-fluid rounded border border-success shadow-sm">
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; } ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>