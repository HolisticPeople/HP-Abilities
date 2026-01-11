const fs = require('fs');
const https = require('https');
const path = require('path');

const API_URL = process.argv[2];
const API_KEY = process.argv[3];
const LOG_FILE = path.join(__dirname, 'bridge.log');

function log(message, data = {}) {
  const payload = {
    timestamp: new Date().toISOString(),
    message,
    data
  };
  try {
    fs.appendFileSync(LOG_FILE, JSON.stringify(payload) + '\n');
  } catch (e) {}
}

if (!API_URL || !API_KEY) {
  console.error('Usage: node hp-mcp-bridge.js <API_URL> <API_KEY>');
  process.exit(1);
}

log('Bridge starting...', { API_URL });

let mcpSessionId = null;
let requestQueue = [];
let isProcessing = false;

function callWP(method, params, id) {
  const isNotification = (id === undefined || id === null);
  
  return new Promise((resolve) => {
    const url = new URL(API_URL);
    const options = {
      hostname: url.hostname,
      port: url.port || (url.protocol === 'https:' ? 443 : 80),
      path: url.pathname + url.search,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-MCP-API-Key': API_KEY,
      }
    };

    if (mcpSessionId) {
      options.headers['Mcp-Session-Id'] = mcpSessionId;
    }

    const req = https.request(options, (res) => {
      let data = '';
      
      const newSessionId = res.headers['mcp-session-id'] || res.headers['Mcp-Session-Id'];
      if (newSessionId) {
        mcpSessionId = newSessionId;
      }

      res.on('data', (chunk) => { data += chunk; });
      res.on('end', () => {
        // #region agent log
        fetch('http://127.0.0.1:7243/ingest/fdc1e251-7d8c-4076-b3bd-ed8c4301842f', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            location: 'hp-mcp-bridge.js:end',
            message: 'Received response from WP',
            data: {
              method: method,
              url: API_URL,
              statusCode: res.statusCode,
              responseLength: data.length,
              mcpSessionId: mcpSessionId,
              responsePreview: data.substring(0, 500)
            },
            timestamp: Date.now(),
            sessionId: 'debug-session',
            hypothesisId: 'H2,H5'
          })
        }).catch(() => {});
        // #endregion

        if (isNotification) return resolve(null);

        if (data.length === 0) {
          return resolve({
            jsonrpc: '2.0',
            id: id,
            error: { code: -32603, message: 'Empty response from WordPress' }
          });
        }

        try {
          // Strip BOM and any leading garbage
          const cleanData = data.replace(/^[^{]*/, '').trim();
          const parsed = JSON.parse(cleanData);
          resolve(parsed);
        } catch (e) {
          log('JSON parse error', { error: e.message, dataPreview: data.substring(0, 100) });
          resolve({
            jsonrpc: '2.0',
            id: id,
            error: { code: -32700, message: 'Parse error', data: data.substring(0, 200) }
          });
        }
      });
    });

    req.on('error', (e) => {
      log('WP request error', { error: e.message });
      if (isNotification) resolve(null);
      else resolve({ jsonrpc: '2.0', id: id, error: { code: -32603, message: 'Bridge Request Error', data: e.message } });
    });

    const payload = { jsonrpc: '2.0', method: method, params: params };
    if (!isNotification) payload.id = id;

    // #region agent log
    fetch('http://127.0.0.1:7243/ingest/fdc1e251-7d8c-4076-b3bd-ed8c4301842f', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        location: 'hp-mcp-bridge.js:send',
        message: 'Sending request to WP',
        data: {
          method: method,
          url: API_URL,
          params: params,
          id: id
        },
        timestamp: Date.now(),
        sessionId: 'debug-session',
        hypothesisId: 'H2'
      })
    }).catch(() => {});
    // #endregion

    req.write(JSON.stringify(payload));
    req.end();
  });
}

async function processQueue() {
  if (isProcessing || requestQueue.length === 0) return;
  isProcessing = true;
  const line = requestQueue.shift();
  try {
    const request = JSON.parse(line);
    const response = await callWP(request.method, request.params, request.id);
    if (response !== null) {
      process.stdout.write(JSON.stringify(response) + '\n');
    }
  } catch (e) {
    log('Process error', { error: e.message });
  } finally {
    isProcessing = false;
    setImmediate(processQueue);
  }
}

let buffer = '';
process.stdin.on('data', (chunk) => {
  buffer += chunk.toString();
  let boundary = buffer.indexOf('\n');
  while (boundary !== -1) {
    const line = buffer.substring(0, boundary).trim();
    buffer = buffer.substring(boundary + 1);
    if (line) {
      requestQueue.push(line);
      processQueue();
    }
    boundary = buffer.indexOf('\n');
  }
});

process.on('uncaughtException', (e) => {
  log('Uncaught Exception', { error: e.message });
});

