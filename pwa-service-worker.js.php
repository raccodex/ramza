<?php
require_once('assets/init.php');

header('Content-Type: application/javascript; charset=utf-8');

$siteUrl = rtrim($wo['config']['site_url'], '/');
$sitePath = parse_url($siteUrl, PHP_URL_PATH);
$basePath = rtrim((string)$sitePath, '/');
if ($basePath === '/') {
    $basePath = '';
}
header('Service-Worker-Allowed: ' . ($basePath === '' ? '/' : $basePath . '/'));

$themeIconPath = parse_url($wo['config']['theme_url'] . '/img/icon.png', PHP_URL_PATH);
if (empty($themeIconPath)) {
    $themeIconPath = $basePath . '/themes/' . $wo['config']['theme'] . '/img/icon.png';
}

$cacheVersion = md5(implode('|', array(
    $wo['config']['version'] ?? '1.0',
    $wo['config']['pwa_icon_192'] ?? '',
    $wo['config']['pwa_icon_512'] ?? '',
    $wo['config']['pwa_cache_strategy'] ?? 'network_first'
)));

$cachePages = ((string)($wo['config']['pwa_cache_pages'] ?? '1') === '1');
$strategy = (string)($wo['config']['pwa_cache_strategy'] ?? 'network_first');
if (!in_array($strategy, array('network_first', 'cache_first'), true)) {
    $strategy = 'network_first';
}

$assetPaths = array_values(array_unique(array_filter(array(
    ($basePath === '' ? '/' : $basePath . '/'),
    $basePath . '/pwa-offline.php',
    $themeIconPath
))));
?>
const RAMZA_PWA_CACHE = <?php echo json_encode('ramza-pwa-' . $cacheVersion); ?>;
const RAMZA_PWA_ASSETS = <?php echo json_encode($assetPaths, JSON_UNESCAPED_SLASHES); ?>;
const RAMZA_PWA_OFFLINE = <?php echo json_encode($basePath . '/pwa-offline.php', JSON_UNESCAPED_SLASHES); ?>;
const RAMZA_PWA_CACHE_PAGES = <?php echo $cachePages ? 'true' : 'false'; ?>;
const RAMZA_PWA_STRATEGY = <?php echo json_encode($strategy); ?>;

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(RAMZA_PWA_CACHE)
      .then(cache => cache.addAll(RAMZA_PWA_CACHE_PAGES ? RAMZA_PWA_ASSETS : [RAMZA_PWA_OFFLINE]))
      .catch(() => null)
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys
        .filter(key => key.indexOf('ramza-pwa-') === 0 && key !== RAMZA_PWA_CACHE)
        .map(key => caches.delete(key))
    ))
  );
  self.clients.claim();
});

function ramzaShouldBypass(url) {
  return url.pathname.indexOf('/admin-cp') !== -1 ||
    url.pathname.indexOf('/requests.php') !== -1 ||
    url.pathname.indexOf('/xhr/') !== -1 ||
    url.pathname.indexOf('/api/') !== -1 ||
    url.pathname.indexOf('/nodejs/') !== -1 ||
    url.search.indexOf('f=') !== -1;
}

function ramzaIsStaticAsset(url) {
  return /\.(?:css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|eot)$/i.test(url.pathname);
}

function ramzaCacheResponse(request, response) {
  if (!RAMZA_PWA_CACHE_PAGES || !response || response.status !== 200 || response.type === 'opaque') {
    return response;
  }
  const copy = response.clone();
  caches.open(RAMZA_PWA_CACHE).then(cache => cache.put(request, copy)).catch(() => null);
  return response;
}

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || ramzaShouldBypass(url)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(response => ramzaCacheResponse(request, response))
        .catch(() => caches.match(request).then(cached => cached || caches.match(RAMZA_PWA_OFFLINE)))
    );
    return;
  }

  if (ramzaIsStaticAsset(url)) {
    if (RAMZA_PWA_STRATEGY === 'cache_first') {
      event.respondWith(
        caches.match(request).then(cached => cached || fetch(request).then(response => ramzaCacheResponse(request, response)))
      );
      return;
    }

    event.respondWith(
      fetch(request)
        .then(response => ramzaCacheResponse(request, response))
        .catch(() => caches.match(request))
    );
  }
});
