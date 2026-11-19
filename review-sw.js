// The Reviews app's service worker (David, 2026-11-19: "the install button needs to do
// the actual install, not just tell the user how to do it").
//
// It exists for one reason: Android browsers only open their own "Install app" window
// (the beforeinstallprompt event review.php waits for) for a site that has a service
// worker with a fetch handler. Samsung Internet insists on it, and Chrome has asked for
// it for years.
//
// ⚠ IT DOES NOTHING ELSE, ON PURPOSE. No cache, no offline copy: the fetch handler
// never calls respondWith(), so every request goes to the server exactly as it would
// without this file, and nobody can ever be shown a stale page or an old version.
// review.php registers it only on a phone.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* straight to the network */ });
