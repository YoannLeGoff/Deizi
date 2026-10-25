// Service worker of the installable application. It stores no page of the application (they hold
// personal data and change all the time): pages always come from the network; only when the
// network is missing does a page show the "offline" message kept here. It also shows the push
// notifications (new or changed date) and opens the date when the member touches one.
const CACHE = "deizi-offline-v1";
const OFFLINE_PAGE = "offline.html";

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.add(OFFLINE_PAGE)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  if (event.request.mode !== "navigate") return;
  event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_PAGE)));
});

self.addEventListener("push", (event) => {
  let message = {};
  try {
    message = event.data ? event.data.json() : {};
  } catch (error) {
    message = {};
  }
  const shown = self.registration.showNotification(message.title || "Deizi", {
    body: message.body || "",
    tag: message.tag || undefined,
    renotify: Boolean(message.tag),
    icon: "icon.php?size=192",
    badge: "icon.php?size=192",
    data: { url: message.url || "index.php" },
  });
  const badge = "setAppBadge" in self.navigator && Number.isInteger(message.badge) ? self.navigator.setAppBadge(message.badge).catch(() => {}) : null;
  event.waitUntil(Promise.all([shown, badge]));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : "index.php", self.registration.scope).href;
  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((windows) => {
      const open = windows.find((client) => "navigate" in client && new URL(client.url).origin === self.location.origin);
      return open ? open.navigate(url).then((client) => (client || open).focus()) : self.clients.openWindow(url);
    })
  );
});
