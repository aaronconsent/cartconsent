/* Consent Resolve for WooCommerce — push service worker. */
self.addEventListener('push', function (event) {
	var d = {};
	try { d = event.data ? event.data.json() : {}; } catch (e) {}
	var title = d.title || 'Your cart is waiting';
	event.waitUntil(
		self.registration.showNotification(title, {
			body: d.body || '',
			data: { url: d.url || '/' },
			tag: 'crw-cart',
			renotify: false
		})
	);
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	var url = (event.notification.data && event.notification.data.url) || '/';
	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
			for (var i = 0; i < list.length; i++) {
				if (list[i].url === url && 'focus' in list[i]) { return list[i].focus(); }
			}
			if (self.clients.openWindow) { return self.clients.openWindow(url); }
		})
	);
});
