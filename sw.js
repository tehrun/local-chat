const CACHE_NAME = 'local-chat-v2';
const APP_SHELL = [
  './',
  'manifest.json',
  'icons/icon.svg',
];

function shouldHandleRequest(request) {
  if (request.method !== 'GET') {
    return false;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return false;
  }

  if (url.pathname.endsWith('.php') || url.search) {
    return false;
  }

  return request.mode === 'navigate'
    || ['document', 'image', 'style', 'script', 'font', 'manifest'].includes(request.destination);
}

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (!shouldHandleRequest(event.request)) {
    return;
  }

  const request = event.request;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('./')))
    );

    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(request).then((response) => {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }

        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        return response;
      });
    })
  );
});

async function fetchPushNotificationPayload() {
  const response = await fetch('./home_api.php?action=push_notifications', {
    credentials: 'same-origin',
    cache: 'no-store',
  });

  if (!response.ok) {
    throw new Error('Could not load push notification payload.');
  }

  const data = await response.json();
  return data?.payload || { chat_users: [] };
}

async function showBackgroundMessageNotifications() {
  const clientList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
  if (clientList.length > 0) {
    return;
  }

  try {
    const payload = await fetchPushNotificationPayload();
    const chatUsers = Array.isArray(payload?.chat_users) ? payload.chat_users : [];

    if (chatUsers.length === 0) {
      await self.registration.showNotification('New message', {
        body: 'You have a new message in Local Chat.',
        icon: 'icons/icon.svg',
        tag: 'chat-message-generic',
        renotify: true,
        data: { url: './' },
      });
      return;
    }

    await Promise.all(chatUsers.map((chatUser) => {
      const userId = Number(chatUser?.id || 0);
      const username = String(chatUser?.username || 'Someone');
      const unseenCount = Number(chatUser?.unseen_count || 0);
      const body = unseenCount === 1
        ? `${username} sent you a new message.`
        : `${username} sent you ${unseenCount} new messages.`;

      return self.registration.showNotification('New message', {
        body,
        icon: 'icons/icon.svg',
        tag: userId > 0 ? `chat-message-${userId}` : 'chat-message-generic',
        renotify: true,
        data: { url: userId > 0 ? `chat.php?user=${userId}` : './' },
      });
    }));
  } catch (error) {
    await self.registration.showNotification('New message', {
      body: 'Open Local Chat to view your latest messages.',
      icon: 'icons/icon.svg',
      tag: 'chat-message-generic',
      renotify: true,
      data: { url: './' },
    });
  }
}

self.addEventListener('push', (event) => {
  event.waitUntil(showBackgroundMessageNotifications());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data?.url || './';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client && client.url.includes(targetUrl)) {
          return client.focus();
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }

      return undefined;
    })
  );
});
