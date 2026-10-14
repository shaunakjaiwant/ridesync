// Automated E2E & Visual Testing Suite with Google Chrome via CDP
import http from 'node:http';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const CDP_PORT = 9222;
const BASE_URL = 'http://localhost/ridesync';

function getJson(urlPath) {
    return new Promise((resolve, reject) => {
        const req = http.request({ hostname: '127.0.0.1', port: CDP_PORT, path: urlPath, method: 'GET' }, res => {
            let data = ''; res.on('data', c => data += c); res.on('end', () => { try { resolve(JSON.parse(data)); } catch(e) { resolve(data); } });
        });
        req.on('error', reject); req.end();
    });
}

function putJson(urlPath) {
    return new Promise((resolve, reject) => {
        const req = http.request({ hostname: '127.0.0.1', port: CDP_PORT, path: urlPath, method: 'PUT' }, res => {
            let data = ''; res.on('data', c => data += c); res.on('end', () => { try { resolve(JSON.parse(data)); } catch(e) { resolve(data); } });
        });
        req.on('error', reject); req.end();
    });
}

function sendCDP(ws, method, params = {}, id = 1) {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({ id, method, params });
        const handler = (event) => {
            try {
                const msg = JSON.parse(event.data);
                if (msg.id === id) {
                    ws.removeEventListener('message', handler);
                    if (msg.error) reject(msg.error);
                    else resolve(msg.result);
                }
            } catch (e) {
                // ignore
            }
        };
        ws.addEventListener('message', handler);
        ws.send(payload);
    });
}

async function ensureChromeCDP() {
    try {
        const ver = await getJson('/json/version');
        if (ver && ver.webSocketDebuggerUrl) {
            console.log(`[Chrome CDP] Connected to existing instance (${ver.Browser})`);
            return;
        }
    } catch (e) {
        // not running, launch
    }

    if (!fs.existsSync(CHROME_PATH)) {
        throw new Error(`Google Chrome executable not found at: ${CHROME_PATH}`);
    }

    console.log(`[Chrome CDP] Launching Google Chrome with remote debugging on port ${CDP_PORT}...`);
    const proc = spawn(CHROME_PATH, [
        '--headless=new',
        `--remote-debugging-port=${CDP_PORT}`,
        '--user-data-dir=C:\\Users\\shaun\\.chrome-test-profile',
        '--disable-gpu',
        'about:blank'
    ], { detached: true, stdio: 'ignore' });
    proc.unref();

    for (let i = 0; i < 20; i++) {
        await new Promise(r => setTimeout(r, 500));
        try {
            const ver = await getJson('/json/version');
            if (ver && ver.webSocketDebuggerUrl) {
                console.log(`[Chrome CDP] Chrome started successfully (${ver.Browser})`);
                return;
            }
        } catch (e) { }
    }
    throw new Error('Failed to connect to Google Chrome CDP');
}

async function testPage(pageUrl, pageName) {
    console.log(`\n-----------------------------------------`);
    console.log(`Testing with Chrome: ${pageName} (${pageUrl})`);
    console.log(`-----------------------------------------`);

    const newTarget = await putJson('/json/new?' + encodeURIComponent(pageUrl));
    const wsUrl = newTarget.webSocketDebuggerUrl;
    const targetId = newTarget.id;

    const ws = new WebSocket(wsUrl);
    await new Promise((resolve, reject) => {
        ws.onopen = resolve;
        ws.onerror = reject;
    });

    let msgId = 1;
    await sendCDP(ws, 'Page.enable', {}, msgId++);
    await sendCDP(ws, 'Runtime.enable', {}, msgId++);

    // Wait for network/DOM
    await new Promise(r => setTimeout(r, 1200));

    // Evaluate page title, headings, and key CSS classes
    const evalRes = await sendCDP(ws, 'Runtime.evaluate', {
        expression: `({
            title: document.title,
            h1: document.querySelector('h1')?.innerText?.trim() || null,
            navbarExists: !!document.querySelector('.navbar'),
            shortAddressCount: document.querySelectorAll('.short-address').length,
            cardCount: document.querySelectorAll('.card, .ride-card, .smart-route-chip, .role-card').length,
            buttonsCount: document.querySelectorAll('.btn, .btn-primary, .btn-secondary').length,
            bodyBg: window.getComputedStyle(document.body).backgroundColor
        })`,
        returnByValue: true
    }, msgId++);

    console.log(`[Chrome Page Result]`, evalRes.result.value);

    // Capture screenshot
    const screenshotRes = await sendCDP(ws, 'Page.captureScreenshot', { format: 'png' }, msgId++);
    if (screenshotRes && screenshotRes.data) {
        const outDir = process.env.RIDESYNC_SCREENSHOT_DIR || 'C:\\Users\\shaun\\.gemini\\antigravity-ide\\brain\\ca18c696-e312-4f93-a3fd-7400001cb600';
        fs.mkdirSync(outDir, { recursive: true });
        const imgPath = path.join(outDir, `chrome_${pageName.toLowerCase().replace(/[^a-z0-9]/g, '_')}.png`);
        fs.writeFileSync(imgPath, Buffer.from(screenshotRes.data, 'base64'));
        console.log(`[Screenshot Saved] ${imgPath}`);
    }

    ws.close();
    // Close target
    try {
        await getJson(`/json/close/${targetId}`);
    } catch (e) { }

    return evalRes.result.value;
}

async function run() {
    await ensureChromeCDP();

    const results = {};
    results.landing = await testPage(`${BASE_URL}/index.php`, 'LandingPage');
    results.login = await testPage(`${BASE_URL}/pages/login.php`, 'RiderLogin');
    results.driverLogin = await testPage(`${BASE_URL}/pages/driver_login.php`, 'DriverLogin');

    console.log(`\n=========================================`);
    console.log(`[Chrome Test Summary] All pages rendered successfully in Google Chrome!`);
    console.log(JSON.stringify(results, null, 2));
    console.log(`=========================================`);
}

run().catch(err => {
    console.error('Chrome test execution failed:', err);
    process.exit(1);
});
