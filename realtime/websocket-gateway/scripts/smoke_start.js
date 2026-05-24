const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { spawn } = require('node:child_process');

function loadDotEnv(filePath, env) {
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
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key) || Object.prototype.hasOwnProperty.call(env, key)) {
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
    env[key] = value;
  }
}

function fail(child, message, output) {
  if (child && !child.killed) {
    child.kill();
  }
  console.error(JSON.stringify({
    ok: false,
    message,
    output: output.trim().slice(-2000),
  }));
  process.exit(1);
}

function checkHealth(child, port, output, timeout) {
  const request = http.get({
    hostname: '127.0.0.1',
    port,
    path: '/health',
    timeout: 2000,
  }, (response) => {
    let body = '';
    response.setEncoding('utf8');
    response.on('data', (chunk) => {
      body += chunk;
    });
    response.on('end', () => {
      clearTimeout(timeout);
      if (response.statusCode !== 200) {
        fail(child, `health returned ${response.statusCode}`, output + body);
      }

      let parsed;
      try {
        parsed = JSON.parse(body);
      } catch (error) {
        fail(child, 'health returned malformed JSON', output + body);
      }

      if (!parsed.ok || parsed.service !== 'ridesync-websocket-gateway') {
        fail(child, 'health returned unexpected payload', output + body);
      }

      console.log(JSON.stringify({ ok: true, message: 'websocket_startup_check_passed', port }));
      child.kill();
      process.exit(0);
    });
  });

  request.on('timeout', () => {
    request.destroy(new Error('health timeout'));
  });
  request.on('error', (error) => {
    fail(child, `health request failed: ${error.message}`, output);
  });
}

const gatewayDir = path.resolve(__dirname, '..');
const env = { ...process.env, RIDESYNC_WS_PORT: '0', RIDESYNC_WS_POLL_MS: '10000' };
loadDotEnv(path.resolve(gatewayDir, '..', '..', '.env'), env);

const child = spawn(process.execPath, ['server.js'], {
  cwd: gatewayDir,
  env,
  windowsHide: true,
  stdio: ['ignore', 'pipe', 'pipe'],
});

let output = '';
const timeout = setTimeout(() => {
  fail(child, 'websocket gateway did not start within timeout', output);
}, 10000);

child.stdout.on('data', (chunk) => {
  output += chunk.toString();
  for (const line of chunk.toString().split(/\r?\n/)) {
    if (!line.trim()) {
      continue;
    }
    try {
      const event = JSON.parse(line);
      if (event.message === 'websocket_gateway_started' && Number(event.port) > 0) {
        checkHealth(child, Number(event.port), output, timeout);
      }
    } catch (error) {
      // Keep collecting logs; fatal startup output is handled by exit/timeout.
    }
  }
});

child.stderr.on('data', (chunk) => {
  output += chunk.toString();
});

child.on('exit', (code) => {
  if (code !== 0) {
    clearTimeout(timeout);
    fail(null, `websocket gateway exited with code ${code}`, output);
  }
});
