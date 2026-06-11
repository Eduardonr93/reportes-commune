const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const axios = require('axios');
const fs = require('fs');
const mysql = require('mysql2/promise');
const { GoogleGenerativeAI } = require("@google/generative-ai");

// ── CONFIGURACIÓN ─────────────────────────────────────────
const GEMINI_API_KEY     = 'AIzaSyDK1I5GqiRXVzSNViGyvpSlASoh0U1MLZ4';
const NOMBRE_GRUPO       = 'Mantenimiento Equipos de Seguridad';
const URL_REPORTES       = 'https://crm.commune.com.mx/reportes/recibir_reporte.php';
const URL_COMUNICADO     = 'https://crm.commune.com.mx/reportes/recibir_comunicado.php';
const URL_DESCARTADO     = 'https://crm.commune.com.mx/reportes/guardar_descartado.php';
const URL_CERRAR_REPORTE = 'https://crm.commune.com.mx/reportes/cerrar_reporte_auto.php';
const INTERVALO_SEGUNDOS = 30;
const DELAY_IA_MS        = 5000;
const MAX_REINTENTOS_IA  = 3;

// ── NÚMEROS ───────────────────────────────────────────────
const EQUIPO_COMMUNE = [
    '5219984251214@c.us',  // Eduardo
    '5219983067953@c.us',  // Edgar
    '5219994467961@c.us'   // Yanet
];
const NUMERO_EDUARDO = '5219984251214@c.us';
const NUMERO_YANET   = '5219994467961@c.us';
const NUMERO_ANGEL  = '5219981576540@c.us';

const TECNICOS_NUMEROS = {
    'Edgar':   '5219983067953@c.us',
    'Martin':  '',
    'Eduardo': '5219984251214@c.us',
};

const RESUMEN_HORA = 9;

// ── DB CONFIG ─────────────────────────────────────────────
const DB_CONFIG = {
    host:     'localhost',
    user:     'commune_reportes',
    password: 'ComuneReportes2026',
    database: 'commune_reportes'
};

// ── Gemini ────────────────────────────────────────────────
const genAI   = new GoogleGenerativeAI(GEMINI_API_KEY);
const modelIA = genAI.getGenerativeModel({ model: "gemini-2.5-flash" });

// ── Plantilla de recordatorio ─────────────────────────────
const PLANTILLA_RECORDATORIO =
`⚠️ *Reporte incompleto* — Falta identificar el equipo específico.

Por favor usa este formato:

🔧 *REPORTE*
📍 Residencial: [RIO / Cumbres / Via Cumbres / Aqua / Palmaris / Arbolada / Altai / Kyra / Monte Athos]
📂 Categoría: [CCTV / Redes / Perímetro / Accesos / Alarma / General]
🔩 Equipo: (ej: Barrera CAME GT4 / Cámara Hikvision DS-2CD / Lector Nedap / Router Ruijie)
🗺️ Ubicación: (ej: Caseta 1 entrada / Zona norte)
📋 Tipo: [Incidencia / Preventivo / Mantenimiento]
🚨 Urgencia: [Nivel 1 - Crítico / Nivel 2 - Moderado / Nivel 3 - Leve]
📝 Descripción: (qué pasó exactamente)
👤 Reporta: (nombre y cargo)

_El campo 🔩 Equipo es obligatorio para registrar el reporte._`;

// ── Timestamp ─────────────────────────────────────────────
const TS_FILE = './ultimo_timestamp.txt';

function cargarTimestamp() {
    try {
        const saved = fs.readFileSync(TS_FILE, 'utf8').trim();
        const ts = parseInt(saved);
        if (!isNaN(ts) && ts > 0) return ts;
    } catch(e) {}
    return Math.floor(Date.now() / 1000) - (48 * 60 * 60);
}

function guardarTimestamp(ts) {
    try { fs.writeFileSync(TS_FILE, String(ts)); } catch(e) {}
}

let ultimoTimestamp = cargarTimestamp();

// ── Procesados en MySQL ───────────────────────────────────
async function esMensajeProcesado(msgId) {
    let db;
    try {
        db = await mysql.createConnection(DB_CONFIG);
        const [rows] = await db.query(
            "SELECT id FROM mensajes_procesados WHERE msg_id=? LIMIT 1", [msgId]
        );
        return rows.length > 0;
    } catch(e) { return false; }
    finally { if (db) await db.end(); }
}

async function marcarMensajeProcesado(msgId) {
    let db;
    try {
        db = await mysql.createConnection(DB_CONFIG);
        await db.query(
            "INSERT IGNORE INTO mensajes_procesados (msg_id, fecha) VALUES (?, NOW())", [msgId]
        );
        await db.query(
            "DELETE FROM mensajes_procesados WHERE id NOT IN (SELECT id FROM (SELECT id FROM mensajes_procesados ORDER BY id DESC LIMIT 2000) t)"
        );
    } catch(e) {}
    finally { if (db) await db.end(); }
}

// ── Cliente WhatsApp ──────────────────────────────────────
const client = new Client({
    authStrategy: new LocalAuth(),
    puppeteer: {
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
        executablePath: '/usr/bin/chromium-browser'
    }
});

const sleep = ms => new Promise(r => setTimeout(r, ms));

function fechaMySQL(timestamp) {
    const d = new Date(timestamp * 1000);
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}:${String(d.getSeconds()).padStart(2,'0')}`;
}

// ── Gemini con prompt mejorado y reintentos ───────────────
async function esReporteTecnicoGemini(texto, intento = 1) {
    const prompt = `Eres un clasificador de mensajes para un sistema de mantenimiento de seguridad en residenciales de Cancún, México.

Responde SOLO "SI" o "NO".

¿Este mensaje describe alguno de los siguientes casos?
- Una falla, avería o problema técnico en equipos de seguridad (cámaras, barreras, plumas, lectores, alarmas, redes, cerco eléctrico)
- Una solicitud de reparación, mantenimiento o revisión de equipos
- Un reporte de incidencia de seguridad en un residencial
- Un reporte usando la plantilla del sistema (aunque esté incompleto)

Ignora: saludos, agradecimientos, mensajes administrativos, conversaciones generales.

Mensaje: "${texto}"`;
    try {
        const r    = await modelIA.generateContent(prompt);
        const resp = r.response.text().trim().toUpperCase();
        return resp.includes('SI');
    } catch(e) {
        if (intento < MAX_REINTENTOS_IA) {
            console.log(`⚠️ Gemini error (intento ${intento}), reintentando en ${DELAY_IA_MS}ms...`);
            await sleep(DELAY_IA_MS);
            return esReporteTecnicoGemini(texto, intento + 1);
        }
        console.error('Gemini error final:', e.message);
        return true;
    }
}

function esClaramenteBasura(texto) {
    if (!texto || texto.trim() === '') return true;
    const t = texto.toLowerCase().trim();
    const importantes = [
        'reporte','avería','averia','falla','error','no funciona','urgente',
        'roto','caído','caido','sin imagen','sin luz','no prende','cámara','camara',
        'pluma','barrera','alarma','lectora','lector','tag','equipo','caseta',
        'residencial','categoría','categoria','descripción','descripcion','ubicación','ubicacion'
    ];
    for (const imp of importantes) {
        if (t.includes(imp)) return false;
    }
    const basura = ['buenos dias','buenas tardes','buenas noches','enterado',
        'gracias','ok','vale','listo','perfecto','excelente','genial','saludos'];
    if (basura.includes(t)) return true;
    if (t.length < 10) return true;
    return false;
}

async function clasificarMensaje(texto, tieneMedia) {
    if (tieneMedia) return { decision: true, motivo: 'media' };
    if (!texto || texto.trim() === '') return { decision: false, motivo: 'vacio' };
    if (esClaramenteBasura(texto)) return { decision: false, motivo: 'prefiltro' };
    const ok = await esReporteTecnicoGemini(texto);
    return { decision: ok, motivo: ok ? 'gemini-si' : 'gemini-no' };
}

async function obtenerNombre(msg) {
    try {
        const c = await msg.getContact();
        if (c.pushname?.trim()) return c.pushname.trim();
        if (c.name?.trim()) return c.name.trim();
    } catch(e) {}
    return msg.author?.split('@')[0] || msg.from?.split('@')[0] || 'Desconocido';
}

// ── DETECTAR CIERRE ───────────────────────────────────────
function detectarCierreReporte(texto) {
    if (!texto) return null;
    const t = texto.toLowerCase();
    const palabrasCierre = ['solucionado','resuelto','terminado','cerrado','listo','ya quedó','ya quedo','funciona'];
    if (!palabrasCierre.some(p => t.includes(p))) return null;
    let reporteId = null;
    const hashMatch    = t.match(/#(\d+)/);
    if (hashMatch) reporteId = parseInt(hashMatch[1]);
    const reporteMatch = t.match(/reporte\s*(\d+)/i);
    if (!reporteId && reporteMatch) reporteId = parseInt(reporteMatch[1]);
    const idMatch      = t.match(/id\s*(\d+)/i);
    if (!reporteId && idMatch) reporteId = parseInt(idMatch[1]);
    const elMatch      = t.match(/[eE]l\s*(\d+)/);
    if (!reporteId && elMatch) reporteId = parseInt(elMatch[1]);
    return reporteId;
}

// ── COMANDO !estado ───────────────────────────────────────
async function manejarComandoEstado(msg, idReporte) {
    let db;
    try {
        db = await mysql.createConnection(DB_CONFIG);
        const [rows] = await db.query(
            "SELECT id, estatus, categoria, equipo, ubicacion_especifica, residencial, prioridad, tecnico_asignado, fecha, fecha_terminado FROM reportes WHERE id=? LIMIT 1",
            [idReporte]
        );
        if (rows.length === 0) {
            await msg.reply(`❌ Reporte #${idReporte} no encontrado.`);
            return;
        }
        const r = rows[0];
        const respuesta =
            `📋 *REPORTE #${r.id}*\n` +
            `📊 Estado: ${r.estatus}\n` +
            `📂 Categoría: ${r.categoria || '—'}\n` +
            `🔩 Equipo: ${r.equipo || '—'}\n` +
            `🏘️ Residencial: ${r.residencial || '—'}\n` +
            `🗺️ Ubicación: ${r.ubicacion_especifica || '—'}\n` +
            `⚠️ Prioridad: ${r.prioridad || 'Normal'}\n` +
            `👷 Técnico: ${r.tecnico_asignado || 'Sin asignar'}\n` +
            `📅 Abierto: ${r.fecha ? new Date(r.fecha).toLocaleString('es-MX') : '—'}` +
            (r.fecha_terminado ? `\n✅ Cerrado: ${new Date(r.fecha_terminado).toLocaleString('es-MX')}` : '');
        await msg.reply(respuesta);
    } catch(e) {
        console.error('Error comando estado:', e.message);
    } finally {
        if (db) await db.end();
    }
}

// ── NOTIFICAR TÉCNICO ─────────────────────────────────────
async function notificarTecnicoAsignado(tecnicoNombre, reporteId, residencial, categoria, equipo, descripcion) {
    const numero = TECNICOS_NUMEROS[tecnicoNombre];
    if (!numero) {
        console.log(`⚠️ Sin número registrado para: ${tecnicoNombre}`);
        return;
    }
    try {
        const mensaje =
            `🔧 *REPORTE ASIGNADO #${reporteId}*\n` +
            `👷 Técnico: ${tecnicoNombre}\n` +
            `🏘️ Residencial: ${residencial || '—'}\n` +
            `📂 Categoría: ${categoria || '—'}\n` +
            `🔩 Equipo: ${equipo || '—'}\n` +
            `📝 ${(descripcion || '').slice(0, 100)}\n` +
            `🔗 https://crm.commune.com.mx/reportes/`;
        await client.sendMessage(numero, mensaje);
        console.log(`📲 Notificación enviada a ${tecnicoNombre}`);
    } catch(e) {
        console.error(`Error notificando técnico ${tecnicoNombre}:`, e.message);
    }
}

// ── RESUMEN DIARIO ────────────────────────────────────────
let resumenEnviadoHoy = false;

async function enviarResumenDiario() {
    const ahora = new Date();
    const hora  = ahora.getHours();
    if (hora === 0) resumenEnviadoHoy = false;
    if (hora !== RESUMEN_HORA || resumenEnviadoHoy) return;
    resumenEnviadoHoy = true;

    let db;
    try {
        db = await mysql.createConnection(DB_CONFIG);

        const [rows] = await db.query(`
            SELECT residencial, categoria, COUNT(*) as total
            FROM reportes
            WHERE estatus IN ('Pendiente', 'En Proceso')
            GROUP BY residencial, categoria
            ORDER BY residencial, total DESC
        `);

        const [urgentes] = await db.query(`
            SELECT COUNT(*) as total FROM reportes
            WHERE estatus IN ('Pendiente', 'En Proceso') AND prioridad='Urgente'
        `);

        const ayer    = new Date(); ayer.setDate(ayer.getDate() - 1);
        const ayerStr = ayer.toISOString().split('T')[0];
        const [cerradosAyer] = await db.query(
            "SELECT COUNT(*) as total FROM reportes WHERE DATE(fecha_terminado)=?", [ayerStr]
        );

        // Top equipos con más fallas este mes
        const mesIni = new Date(); mesIni.setDate(1);
        const mesIniStr = mesIni.toISOString().split('T')[0];
        const [topEquipos] = await db.query(`
            SELECT equipo, COUNT(*) as fallas
            FROM reportes
            WHERE DATE(fecha) >= ? AND equipo != '' AND equipo IS NOT NULL
            GROUP BY equipo
            ORDER BY fallas DESC
            LIMIT 3
        `, [mesIniStr]);

        if (rows.length === 0 && urgentes[0].total === 0) {
            const msg = `☀️ *RESUMEN DIARIO — ${ahora.toLocaleDateString('es-MX')}*\n\n✅ Sin reportes pendientes. Todo al día.`;
            await client.sendMessage(NUMERO_EDUARDO, msg);
            await client.sendMessage(NUMERO_YANET, msg);
	    await client.sendMessage(NUMERO_ANGEL, msg);
            return;
        }

        const porResidencial = {};
        for (const row of rows) {
            const res = row.residencial || 'Sin residencial';
            if (!porResidencial[res]) porResidencial[res] = [];
            porResidencial[res].push(`  • ${row.categoria || 'General'}: ${row.total}`);
        }

        let resumen = `☀️ *RESUMEN DIARIO — ${ahora.toLocaleDateString('es-MX')}*\n\n`;
        resumen += `📊 *Reportes pendientes por comunidad:*\n`;

        for (const [res, cats] of Object.entries(porResidencial)) {
            resumen += `\n🏘️ *${res}*\n${cats.join('\n')}\n`;
        }

        resumen += `\n🚨 Urgentes activos: ${urgentes[0].total}`;
        resumen += `\n✅ Cerrados ayer: ${cerradosAyer[0].total}`;

        if (topEquipos.length > 0) {
            resumen += `\n\n🔩 *Equipos con más fallas este mes:*`;
            topEquipos.forEach((e, i) => {
                resumen += `\n${i+1}. ${e.equipo} (${e.fallas} reportes)`;
            });
        }

        resumen += `\n\n🔗 https://crm.commune.com.mx/reportes/dashboard.php`;

        await client.sendMessage(NUMERO_EDUARDO, resumen);
        await client.sendMessage(NUMERO_YANET, resumen);
	await client.sendMessage(NUMERO_ANGEL, resumen);
        console.log('📊 Resumen diario enviado a Eduardo, Yanet y Angel');

    } catch(e) {
        console.error('Error resumen diario:', e.message);
        resumenEnviadoHoy = false;
    } finally {
        if (db) await db.end();
    }
}

// ── NOTIFICACIONES PENDIENTES ─────────────────────────────
async function enviarNotificacionesPendientes() {
    let db;
    try {
        db = await mysql.createConnection(DB_CONFIG);
        const [rows] = await db.query(
            "SELECT * FROM notificaciones_pendientes WHERE enviado=0 ORDER BY fecha ASC LIMIT 10"
        );
        if (rows.length === 0) return;

        const chats = await client.getChats();
        const grupo  = chats.find(c => c.name === NOMBRE_GRUPO);
        if (!grupo) return;

        for (const notif of rows) {
            try {
                const mensaje = notif.mensaje.replace(
                    /https:\/\/reportes\.thenetguru\.com\/index\.php/g,
                    'https://crm.commune.com.mx/reportes/'
                );
                await grupo.sendMessage(mensaje);
                await db.query("UPDATE notificaciones_pendientes SET enviado=1 WHERE id=?", [notif.id]);
                console.log(`📤 Notificación #${notif.id} enviada al grupo`);

                if (notif.tipo === 'asignacion' && notif.reporte_id) {
                    const [rep] = await db.query(
                        "SELECT tecnico_asignado, residencial, categoria, equipo, descripcion FROM reportes WHERE id=? LIMIT 1",
                        [notif.reporte_id]
                    );
                    if (rep.length > 0 && rep[0].tecnico_asignado) {
                        await notificarTecnicoAsignado(
                            rep[0].tecnico_asignado,
                            notif.reporte_id,
                            rep[0].residencial,
                            rep[0].categoria,
                            rep[0].equipo,
                            rep[0].descripcion
                        );
                    }
                }
                await sleep(2000);
            } catch(e) {
                console.error(`Error enviando notif #${notif.id}:`, e.message);
            }
        }
    } catch(e) {
        console.error('Error notificaciones:', e.message);
    } finally {
        if (db) await db.end();
    }
}

// ── AUTO-RECONEXIÓN ───────────────────────────────────────
let reconectando = false;

async function intentarReconexion() {
    if (reconectando) return;
    reconectando = true;
    console.log('🔄 Intentando reconexión en 30s...');
    try {
        await client.sendMessage(NUMERO_EDUARDO,
            '⚠️ *Bot Commune desconectado*\nIntentando reconexión automática...'
        );
    } catch(e) {}
    await sleep(30000);
    try {
        await client.initialize();
        console.log('✅ Reconexión exitosa');
    } catch(e) {
        console.error('❌ Error en reconexión:', e.message);
        reconectando = false;
        setTimeout(intentarReconexion, 60000);
    }
    reconectando = false;
}

// ── FUNCIONES AUXILIARES ──────────────────────────────────
async function enviarCierreReporte(reporteId, tecnico, observaciones, msgOriginal) {
    try {
        const params = new URLSearchParams();
        params.append('id', reporteId);
        params.append('tecnico', tecnico);
        params.append('observaciones', observaciones);
        params.append('mensaje_original', msgOriginal);
        params.append('fecha', fechaMySQL(Math.floor(Date.now() / 1000)));
        const res = await axios.post(URL_CERRAR_REPORTE, params, { timeout: 20000 });
        return res.data;
    } catch(e) {
        console.error('Error cerrando reporte:', e.message);
        return null;
    }
}

async function enviarComunicadoInterno(msg, remitente, numero) {
    try {
        let fotoBase64 = '';
        if (msg.hasMedia && msg.type !== "video") {
            try {
                const media = await msg.downloadMedia();
                if (media?.data) fotoBase64 = media.data;
            } catch(e) {}
        }
        const params = new URLSearchParams();
        params.append('texto', msg.body || '');
        params.append('usuario', remitente);
        params.append('numero', numero);
        params.append('fecha', fechaMySQL(msg.timestamp));
        params.append('imagen_base64', fotoBase64);
        await axios.post(URL_COMUNICADO, params, { timeout: 20000 });
        console.log(`📝 Comunicado interno de ${remitente}`);
    } catch(e) {
        console.error('Error comunicado:', e.message);
    }
}

async function enviarReporteNormal(msg, remitente, grupo) {
    try {
        let fotoBase64 = '';
        if (msg.hasMedia && msg.type !== "video") {
            try {
                const media = await msg.downloadMedia();
                if (media?.data) fotoBase64 = media.data;
            } catch(e) {}
        }
        const params = new URLSearchParams();
        params.append('texto', msg.body || '');
        params.append('usuario', remitente);
        params.append('fecha', fechaMySQL(msg.timestamp));
        params.append('imagen_base64', fotoBase64);
        const res = await axios.post(URL_REPORTES, params, { timeout: 20000 });
        const respuesta = res.data?.trim() || '';

        if (respuesta === 'SIN_EQUIPO') {
            console.log(`⚠️ Reporte rechazado — falta campo Equipo`);
            await msg.reply(PLANTILLA_RECORDATORIO);
        } else if (respuesta.startsWith('OK:')) {
            const id = respuesta.split(':')[1];
            console.log(`📋 Reporte guardado: #${id}`);
            await msg.reply(`✅ *Reporte #${id} registrado correctamente.*\nEn breve será asignado a un técnico.\n🔗 https://crm.commune.com.mx/reportes/`);
        } else if (respuesta === 'DUPLICADO') {
            console.log(`⏭️ Reporte duplicado ignorado`);
        } else {
            console.log(`📋 Reporte guardado: ${respuesta.slice(0,50)}`);
        }
    } catch(e) {
        console.error('Error reporte:', e.message);
    }
}

async function guardarDescartado(msg, remitente, motivo) {
    try {
        const params = new URLSearchParams();
        params.append('remitente', remitente);
        params.append('mensaje', msg.body || '');
        params.append('motivo', motivo);
        params.append('tiene_media', msg.hasMedia ? '1' : '0');
        params.append('fecha', fechaMySQL(msg.timestamp));
        await axios.post(URL_DESCARTADO, params, { timeout: 10000 });
    } catch(e) {}
}

// ── REVISAR MENSAJES ──────────────────────────────────────
async function revisarMensajesNuevos() {
    try {
        const chats = await client.getChats();
        const grupo  = chats.find(c => c.name === NOMBRE_GRUPO);
        if (!grupo) { console.log('⚠️ Grupo no encontrado'); return; }

        const mensajes = await grupo.fetchMessages({ limit: 100 });
        const procesadosCheck = await Promise.all(
            mensajes.map(async m => ({
                msg: m,
                procesado: await esMensajeProcesado(m.id.id)
            }))
        );

        const nuevos = procesadosCheck
            .filter(({ msg, procesado }) =>
                msg.timestamp > ultimoTimestamp &&
                !msg.fromMe &&
                !procesado
            )
            .map(({ msg }) => msg);

        if (nuevos.length === 0) {
            process.stdout.write('.');
            return;
        }

        console.log(`\n📨 ${nuevos.length} mensaje(s) nuevo(s)`);
        let nuevoTsMaximo = ultimoTimestamp;

        for (const msg of nuevos) {
            const remitenteNumero = msg.author || msg.from;
            const remitenteNombre = await obtenerNombre(msg);

            await marcarMensajeProcesado(msg.id.id);

            console.log(`\n👤 ${remitenteNombre} (${remitenteNumero})`);
            console.log(`💬 ${(msg.body||'').slice(0,70)}`);
            console.log(`📷 Media: ${msg.hasMedia ? 'Sí' : 'No'}`);

            // ── COMANDO !estado ──
            if (msg.body?.trim().startsWith('!estado')) {
                const match = msg.body.match(/!estado\s*#?(\d+)/i);
                if (match) {
                    await manejarComandoEstado(msg, parseInt(match[1]));
                } else {
                    await msg.reply('❓ Uso: *!estado #123* (reemplaza 123 por el ID del reporte)');
                }
                if (msg.timestamp > nuevoTsMaximo) nuevoTsMaximo = msg.timestamp;
                continue;
            }

            // ── YANET ──
            const esYanet = remitenteNumero.includes('5219994467961') ||
                            remitenteNombre.toLowerCase().includes('yanet');

            if (esYanet) {
                const reporteId = detectarCierreReporte(msg.body);
                if (reporteId) {
                    const resultado = await enviarCierreReporte(reporteId, 'Yanet', msg.body, msg.body);
                    if (resultado?.includes('OK')) {
                        await msg.reply(`✅ Reporte #${reporteId} cerrado por ${remitenteNombre}`);
                    }
                } else {
                    console.log(`⏭️ Mensaje de Yanet ignorado`);
                }
                if (msg.timestamp > nuevoTsMaximo) nuevoTsMaximo = msg.timestamp;
                continue;
            }

            // ── EQUIPO COMMUNE ──
            if (EQUIPO_COMMUNE.includes(remitenteNumero)) {
                console.log(`📝 COMUNICADO INTERNO (${remitenteNombre})`);
                await enviarComunicadoInterno(msg, remitenteNombre, remitenteNumero);
            } else {
                // ── REPORTE EXTERNO ──
                const { decision, motivo } = await clasificarMensaje(msg.body, msg.hasMedia);
                if (decision) {
                    console.log(`✅ REPORTE APROBADO [${motivo}]`);
                    await enviarReporteNormal(msg, remitenteNombre, grupo);
                } else {
                    console.log(`❌ DESCARTADO [${motivo}]`);
                    if (motivo !== 'vacio') await guardarDescartado(msg, remitenteNombre, motivo);
                }
                if (motivo === 'gemini-si' || motivo === 'gemini-no') await sleep(DELAY_IA_MS);
            }

            if (msg.timestamp > nuevoTsMaximo) nuevoTsMaximo = msg.timestamp;
        }

        if (nuevoTsMaximo > ultimoTimestamp) {
            ultimoTimestamp = nuevoTsMaximo;
            guardarTimestamp(nuevoTsMaximo);
        }

        console.log(`\n⏱️ Próxima revisión en ${INTERVALO_SEGUNDOS}s`);

    } catch(e) {
        console.error('Error:', e.message);
    }
}

// ── EVENTOS ───────────────────────────────────────────────
client.on('qr', qr => {
    console.log('⚡ ESCANEA ESTE QR CON WHATSAPP:');
    qrcode.generate(qr, { small: true });
});

client.on('ready', async () => {
    console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    console.log('✅ BOT COMMUNE v3 activo');
    console.log(`📋 Grupo: ${NOMBRE_GRUPO}`);
    console.log(`👥 Equipo Commune: ${EQUIPO_COMMUNE.length} números`);
    console.log(`🔄 Intervalo: ${INTERVALO_SEGUNDOS}s`);
    console.log(`📊 Resumen diario: ${RESUMEN_HORA}:00 AM → Eduardo + Yanet`);
    console.log(`🔩 Plantilla obligatoria: campo Equipo requerido`);
    console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n');

    await revisarMensajesNuevos();
    setInterval(revisarMensajesNuevos, INTERVALO_SEGUNDOS * 1000);

    await enviarNotificacionesPendientes();
    setInterval(enviarNotificacionesPendientes, INTERVALO_SEGUNDOS * 1000);

    setInterval(enviarResumenDiario, 60 * 1000);
});

client.on('disconnected', async (reason) => {
    console.log('⚠️ Desconectado:', reason);
    await intentarReconexion();
});

client.initialize();
