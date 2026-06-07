// sw.js — Service Worker Commune Gestión Cancún
const CACHE_NAME = 'commune-v1';
const STATIC_ASSETS = [
  '/index.php',
  '/manifest.json',
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap'
];

// ── Instalación ───────────────────────────────────────────
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      // Solo cachear assets estáticos, no el PHP dinámico
      return cache.addAll(STATIC_ASSETS).catch(() => {});
    })
  );
  self.skipWaiting();
});

// ── Activación ────────────────────────────────────────────
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// ── Fetch — Network first para PHP, Cache first para assets ──
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // PHP siempre desde red (contenido dinámico)
  if (url.pathname.endsWith('.php') || url.pathname === '/') {
    e.respondWith(
      fetch(e.request).catch(() => {
        // Offline fallback
        return new Response(
          `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <title>Sin conexión</title>
          <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f4f6fb;margin:0}
          .box{text-align:center;padding:40px;background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08)}
          h2{color:#0f172a;margin-bottom:8px}p{color:#64748b;margin-bottom:20px}
          button{background:#2563eb;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-size:14px;cursor:pointer}</style>
          </head><body><div class="box">
          <div style="font-size:48px;margin-bottom:16px">📡</div>
          <h2>Sin conexión</h2>
          <p>Verifica tu conexión a internet e intenta de nuevo.</p>
          <button onclick="location.reload()">Reintentar</button>
          </div></body></html>`,
          { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
      })
    );
    return;
  }

  // Assets estáticos — cache first
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
        }
        return response;
      }).catch(() => cached);
    })
  );
});

// ── Push notifications ────────────────────────────────────
self.addEventListener('push', e => {
  if (!e.data) return;
  let data = {};
  try { data = e.data.json(); } catch { data = { title: 'Commune', body: e.data.text() }; }

  e.waitUntil(
    self.registration.showNotification(data.title || 'Commune', {
      body:    data.body || 'Nuevo evento en el panel',
      icon:    '/icons/icon-192.png',
      badge:   '/icons/icon-192.png',
      tag:     'commune-notif',
      renotify: true,
      actions: [{ action: 'open', title: 'Ver panel' }],
      data:    { url: data.url || '/index.php' }
    })
  );
});

// ── Click en notificación ─────────────────────────────────
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const target = e.notification.data?.url || '/index.php';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => {
      const match = cs.find(c => c.url.includes(target) && 'focus' in c);
      if (match) return match.focus();
      return clients.openWindow(target);
    })
  );
});
