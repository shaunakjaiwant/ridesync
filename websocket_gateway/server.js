const crypto = require('node:crypto');
const http = require('node:http');
const mysql = require('mysql2/promise');
const WebSocket = require('ws');

const port = Number(process.env.RIDESYNC_WS_PORT || 8081);
const pollMs = Math.max(500, Number(process.env.RIDESYNC_WS_POLL_MS || 1500));
const secret = String(process.env.RIDESYNC_WS_SHARED_TOKEN || '');
const clients = new Set();

let lastEventId = 0;
let pool;

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

  const expected = sign(audienceType, audienceType === 'admin' ? 0 : audienceId, expiresAt);
  if (!safeEqual(token, expected)) {
    return null;
  }

  return {
    audienceType,
    audienceId: audienceType === 'admin' ? 0 : audienceId,
    lastSeenId: Number(url.searchParams.get('after_id') || 0),
  };
}

function matchesClient(event, client) {
  if (event.audience_type !== client.audienceType) {
    return false;
  }

  return event.audience_id === null || Number(event.audience_id) === client.audienceId;
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
      const event = {
        id: Number(row.id),
        event_type: row.event_type,
        audience_type: row.audience_type,
        audience_id: row.audience_id === null ? null : Number(row.audience_id),
        aggregate_type: row.aggregate_type,
        aggregate_id: row.aggregate_id === null ? null : Number(row.aggregate_id),
        payload: JSON.parse(row.payload_json || '{}'),
        created_at: row.created_at,
      };

      for (const client of clients) {
        if (matchesClient(event, client)) {
          send(client, event);
        }
      }
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
    console.log(JSON.stringify({
      level: 'info',
      message: 'websocket_gateway_started',
      port,
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
