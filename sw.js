const CACHE_NAME = 'local-chat-v2';
const APP_SHELL = [
  './',
  'manifest.json',
  'icons/icon.svg',
];
const PUSH_CONFIG_URL = './__push_config__';
const PUSH_STATE_CACHE = 'local-chat-push-state-v1';
const PUSH_STATE_URL = './__push_state__';

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
  const payload = data?.payload || {};

  return {
    chat_users: Array.isArray(payload.chat_users) ? payload.chat_users : [],
    incoming_requests: Array.isArray(payload.incoming_requests) ? payload.incoming_requests : [],
    outgoing_request_updates: Array.isArray(payload.outgoing_request_updates) ? payload.outgoing_request_updates : [],
  };
}

async function readPushNotificationState() {
  try {
    const cache = await caches.open(PUSH_STATE_CACHE);
    const response = await cache.match(PUSH_STATE_URL);
    if (!response) {
      return {
        chat_users: [],
        incoming_requests: [],
        outgoing_request_updates: [],
      };
    }

    const payload = await response.json();
    return {
      chat_users: Array.isArray(payload?.chat_users) ? payload.chat_users : [],
      incoming_requests: Array.isArray(payload?.incoming_requests) ? payload.incoming_requests : [],
      outgoing_request_updates: Array.isArray(payload?.outgoing_request_updates) ? payload.outgoing_request_updates : [],
    };
  } catch (error) {
    return {
      chat_users: [],
      incoming_requests: [],
      outgoing_request_updates: [],
    };
  }
}

async function writePushNotificationState(payload) {
  const cache = await caches.open(PUSH_STATE_CACHE);
  await cache.put(PUSH_STATE_URL, new Response(JSON.stringify(payload), {
    headers: { 'Content-Type': 'application/json' },
  }));
}

async function showBackgroundActivityNotifications() {
  const clientList = await clients.matchAll({ type: 'window', includeUncontrolled: true });
  if (clientList.length > 0) {
    return;
  }

  let payload;
  try {
    payload = await fetchPushNotificationPayload();
  } catch (error) {
    await self.registration.showNotification('New activity', {
      body: 'Open Local Chat to view your latest updates.',
      icon: 'icons/icon.svg',
      tag: 'local-chat-activity',
      renotify: true,
      data: { url: './' },
    });
    return;
  }

  const previousState = await readPushNotificationState();
  const previousMessageCounts = new Map((previousState.chat_users || []).map((chatUser) => [String(chatUser?.id || ''), Number(chatUser?.unseen_count || 0)]));
  const previousIncomingIds = new Set((previousState.incoming_requests || []).map((request) => String(request?.id || request?.sender_id || '')));
  const previousResponseKeys = new Set((previousState.outgoing_request_updates || []).map((update) => `${update?.id || ''}:${update?.status || ''}:${update?.responded_at || ''}`));
  const notificationTasks = [];

  for (const chatUser of payload.chat_users) {
    const userId = Number(chatUser?.id || 0);
    const username = String(chatUser?.username || 'Someone');
    const unseenCount = Number(chatUser?.unseen_count || 0);
    const previousUnseenCount = previousMessageCounts.get(String(userId)) || 0;
    const increaseCount = unseenCount - previousUnseenCount;

    if (increaseCount <= 0) {
      continue;
    }

    const body = increaseCount === 1
      ? `${username} sent you a new message.`
      : `${username} sent you ${increaseCount} new messages.`;

    notificationTasks.push(self.registration.showNotification('New message', {
      body,
      icon: 'icons/icon.svg',
      tag: userId > 0 ? `chat-message-${userId}` : 'chat-message-generic',
      renotify: true,
      data: { url: userId > 0 ? `chat.php?user=${userId}` : './' },
    }));
  }

  for (const request of payload.incoming_requests) {
    const requestId = String(request?.id || request?.sender_id || '');
    if (!requestId || previousIncomingIds.has(requestId)) {
      continue;
    }

    const senderName = String(request?.sender_name || 'Someone');
    notificationTasks.push(self.registration.showNotification('New friend request', {
      body: `${senderName} wants to add you as a friend.`,
      icon: 'icons/icon.svg',
      tag: `friend-request-${requestId}`,
      renotify: true,
      data: { url: './' },
    }));
  }

  for (const update of payload.outgoing_request_updates) {
    const status = String(update?.status || '');
    const responseKey = `${update?.id || ''}:${status}:${update?.responded_at || ''}`;
    if (!responseKey || previousResponseKeys.has(responseKey) || (status !== 'accepted' && status !== 'rejected')) {
      continue;
    }

    const recipientName = String(update?.recipient_name || 'Someone');
    const accepted = status === 'accepted';
    const recipientId = Number(update?.recipient_id || 0);

    notificationTasks.push(self.registration.showNotification(accepted ? 'Friend request accepted' : 'Friend request rejected', {
      body: accepted
        ? `${recipientName} accepted your friend request.`
        : `${recipientName} rejected your friend request.`,
      icon: 'icons/icon.svg',
      tag: `friend-request-response-${update?.id || recipientId || recipientName}`,
      renotify: true,
      data: { url: accepted && recipientId > 0 ? `./chat.php?user=${recipientId}` : './' },
    }));
  }

  await Promise.all(notificationTasks);
  await writePushNotificationState(payload);
}

self.addEventListener('push', (event) => {
  event.waitUntil(showBackgroundActivityNotifications());
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
