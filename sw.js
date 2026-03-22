const CACHE_NAME = 'local-chat-v2';
const APP_SHELL = [
  './',
  'manifest.json',
  'icons/icon.svg',
];
const PUSH_CONFIG_URL = './__push_config__';

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

function base64UrlToUint8Array(value) {
  if (!value) {
    return new Uint8Array();
  }

  const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
  const raw = atob(padded);

  return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

async function persistPushConfig(config) {
  const cache = await caches.open(CACHE_NAME);
  await cache.put(PUSH_CONFIG_URL, new Response(JSON.stringify(config), {
    headers: { 'Content-Type': 'application/json' },
  }));
}

async function readPushConfig() {
  const response = await caches.match(PUSH_CONFIG_URL);
  if (!response) {
    return null;
  }

  try {
    return await response.json();
  } catch (error) {
    return null;
  }
}

self.addEventListener('message', (event) => {
  const data = event.data;
  if (!data || data.type !== 'push-config') {
    return;
  }

  event.waitUntil(persistPushConfig({
    publicKey: String(data.publicKey || ''),
    csrfToken: String(data.csrfToken || ''),
  }));
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

async function refreshPushSubscription(event) {
  const config = await readPushConfig();
  if (!config?.publicKey || !config?.csrfToken) {
    return;
  }

  const subscription = await self.registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: base64UrlToUint8Array(config.publicKey),
  });

  const saveParams = new URLSearchParams({
    action: 'save_push_subscription',
    csrf_token: config.csrfToken,
    subscription: JSON.stringify(subscription.toJSON()),
  });

  await fetch('./home_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: saveParams.toString(),
    credentials: 'same-origin',
  });

  const oldEndpoint = typeof event.oldSubscription?.endpoint === 'string' ? event.oldSubscription.endpoint : '';
  if (!oldEndpoint || oldEndpoint === subscription.endpoint) {
    return;
  }

  const deleteParams = new URLSearchParams({
    action: 'delete_push_subscription',
    csrf_token: config.csrfToken,
    endpoint: oldEndpoint,
  });

  await fetch('./home_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: deleteParams.toString(),
    credentials: 'same-origin',
  });
}

self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(refreshPushSubscription(event));
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
