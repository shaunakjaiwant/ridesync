const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const mysql = require('mysql2/promise');
const { createClient } = require('redis');
const WebSocket = require('ws');

function loadDotEnv(filePath) {
  if (!fs.existsSync(filePath)) {
    return;
  }

  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  for (const rawLine of lines) {
    let line = rawLine.trim();
    if (!line || line.startsWith('#')) {
      continue;
    }
    if (line.startsWith('export ')) {
      line = line.slice(7).trim();
    }

    const equalsAt = line.indexOf('=');
    if (equalsAt === -1) {
      continue;
    }

    const key = line.slice(0, equalsAt).trim();
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key) || Object.prototype.hasOwnProperty.call(process.env, key)) {
      continue;
    }

    let value = line.slice(equalsAt + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    } else {
      const commentAt = value.indexOf(' #');
      if (commentAt !== -1) {
        value = value.slice(0, commentAt).trimEnd();
      }
    }
    process.env[key] = value;
  }
}

loadDotEnv(path.resolve(__dirname, '..', '..', '.env'));

const port = Number(process.env.RIDESYNC_WS_PORT || 8081);
const pollMs = Math.max(500, Number(process.env.RIDESYNC_WS_POLL_MS || 1500));
const secret = String(process.env.RIDESYNC_WS_SHARED_TOKEN || '');
const redisUrl = String(process.env.RIDESYNC_REDIS_URL || '');
const clients = new Set();

let lastEventId = 0;
let pool;
let redisClient;

function hasStrongSecret(value) {
  const normalized = String(value || '').trim();
  return normalized.length >= 32 && !normalized.toLowerCase().startsWith('replace-with');
}

function dbConfig() {
  return {
    host: process.env.RIDESYNC_DB_HOST || '127.0.0.1',
    port: Number(process.env.RIDESYNC_DB_PORT || 3306),
    user: process.env.RIDESYNC_DB_USER || 'ridesync_app',
    password: process.env.RIDESYNC_DB_PASSWORD || '',
    database: process.env.RIDESYNC_DB_NAME || 'ridesync_db',
    waitForConnections: true,
    connectionLimit: Number(process.env.RIDESYNC_WS_DB_POOL || 5),
    namedPlaceholders: true,
  };
}

function sign(audienceType, audienceId, expiresAt) {
  return crypto
    .createHmac('sha256', secret)
    .update(`${audienceType}:${audienceId}:${expiresAt}`)
    .digest('hex');
}

function safeEqual(a, b) {
  const left = Buffer.from(String(a || ''), 'hex');
  const right = Buffer.from(String(b || ''), 'hex');
  return left.length === right.length && left.length > 0 && crypto.timingSafeEqual(left, right);
}

function authenticate(request) {
  const url = new URL(request.url, `http://${request.headers.host || 'localhost'}`);
  const audienceType = String(url.searchParams.get('audience_type') || '').toLowerCase();
  const audienceId = Number(url.searchParams.get('audience_id') || 0);
  const expiresAt = Number(url.searchParams.get('expires_at') || 0);
  const token = String(url.searchParams.get('token') || '');

  if (!secret || !['admin', 'user', 'driver'].includes(audienceType)) {
    return null;
  }
  if (audienceType !== 'admin' && audienceId <= 0) {
    return null;
  }
  if (expiresAt < Math.floor(Date.now() / 1000)) {
    return null;
  }

  const expected = sign(audienceType, audienceId, expiresAt);
  if (!safeEqual(token, expected)) {
    return null;
  }

  return {
    audienceType,
    audienceId,
    lastSeenId: Number(url.searchParams.get('after_id') || 0),
  };
}

function matchesClient(event, client) {
  if (event.audience_type !== client.audienceType) {
    return false;
  }

  // audience_id null in the event means broadcast to all of that audience type
  if (event.audience_id === null) {
    return true;
  }

  return Number(event.audience_id) === client.audienceId;
}

function send(client, event) {
  if (client.socket.readyState !== WebSocket.OPEN || event.id <= client.lastSeenId) {
    return;
  }

  client.socket.send(JSON.stringify({
    type: 'ridesync.event',
    event,
  }));
  client.lastSeenId = event.id;
}

function fanoutEvent(event) {
  for (const client of clients) {
    if (matchesClient(event, client)) {
      send(client, event);
    }
  }
}

function parseDbEvent(row) {
  return {
    id: Number(row.id),
    event_type: row.event_type,
    audience_type: row.audience_type,
    audience_id: row.audience_id === null ? null : Number(row.audience_id),
    aggregate_type: row.aggregate_type,
    aggregate_id: row.aggregate_id === null ? null : Number(row.aggregate_id),
    payload: JSON.parse(row.payload_json || '{}'),
    created_at: row.created_at,
  };
}

function normalizePubSubEvent(message) {
  const event = JSON.parse(message);
  if (!event || Number(event.id) <= 0 || !event.event_type || !event.audience_type) {
    return null;
  }

  return {
    id: Number(event.id),
    event_type: String(event.event_type),
    audience_type: String(event.audience_type),
    audience_id: event.audience_id === null || event.audience_id === undefined ? null : Number(event.audience_id),
    aggregate_type: event.aggregate_type || null,
    aggregate_id: event.aggregate_id === null || event.aggregate_id === undefined ? null : Number(event.aggregate_id),
    payload: event.payload && typeof event.payload === 'object' ? event.payload : {},
    created_at: event.created_at || new Date().toISOString(),
  };
}

async function subscribeRedisEvents() {
  if (!redisUrl) {
    return;
  }

  try {
    redisClient = createClient({
      url: redisUrl,
      socket: {
        connectTimeout: 500,
        reconnectStrategy: false,
      },
    });
    redisClient.on('error', (error) => {
      console.error(JSON.stringify({
        level: 'warning',
        message: 'websocket_redis_error',
        error: error.message,
      }));
    });
    await redisClient.connect();
    await redisClient.subscribe('ridesync:realtime_events', (message) => {
      try {
        const event = normalizePubSubEvent(message);
        if (!event || event.id <= lastEventId) {
          return;
        }
        lastEventId = Math.max(lastEventId, event.id);
        fanoutEvent(event);
      } catch (error) {
        console.error(JSON.stringify({
          level: 'warning',
          message: 'websocket_redis_message_invalid',
          error: error.message,
        }));
      }
    });
    console.log(JSON.stringify({
      level: 'info',
      message: 'websocket_redis_subscribed',
      channel: 'ridesync:realtime_events',
    }));
  } catch (error) {
    console.error(JSON.stringify({
      level: 'warning',
      message: 'websocket_redis_subscribe_failed',
      error: error.message,
    }));
  }
}

async function pollEvents() {
  if (!pool || clients.size === 0) {
    return;
  }

  try {
    const [rows] = await pool.execute(
      `SELECT id, event_type, audience_type, audience_id, aggregate_type, aggregate_id, payload_json, created_at
       FROM realtime_events
       WHERE id > ?
         AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
       ORDER BY id ASC
       LIMIT 500`,
      [lastEventId],
    );

    for (const row of rows) {
      lastEventId = Math.max(lastEventId, Number(row.id));
      fanoutEvent(parseDbEvent(row));
    }
  } catch (error) {
    console.error(JSON.stringify({
      level: 'error',
      message: 'websocket_poll_failed',
      error: error.message,
    }));
  }
}

async function main() {
  if (!hasStrongSecret(secret)) {
    console.error(JSON.stringify({
      level: 'fatal',
      message: 'websocket_secret_not_configured',
    }));
    process.exit(1);
  }

  pool = mysql.createPool(dbConfig());
  await subscribeRedisEvents();
  const server = http.createServer((request, response) => {
    if (request.url === '/health') {
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ ok: true, service: 'ridesync-websocket-gateway' }));
      return;
    }

    response.writeHead(404, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ ok: false, error: 'not_found' }));
  });

  const wss = new WebSocket.Server({ server, path: '/ridesync/ws' });
  wss.on('connection', (socket, request) => {
    const client = authenticate(request);
    if (!client) {
      socket.close(1008, 'unauthorized');
      return;
    }

    client.socket = socket;
    clients.add(client);
    socket.send(JSON.stringify({
      type: 'ridesync.ready',
      audience_type: client.audienceType,
      audience_id: client.audienceId,
      server_time: new Date().toISOString(),
    }));

    socket.on('close', () => clients.delete(client));
    socket.on('error', () => clients.delete(client));
  });

  setInterval(pollEvents, pollMs).unref();
  server.listen(port, () => {
    const address = server.address();
    console.log(JSON.stringify({
      level: 'info',
      message: 'websocket_gateway_started',
      port: address && typeof address === 'object' ? address.port : port,
      path: '/ridesync/ws',
    }));
  });
}

main().catch((error) => {
  console.error(JSON.stringify({
    level: 'fatal',
    message: 'websocket_gateway_failed',
    error: error.message,
  }));
  process.exit(1);
});
