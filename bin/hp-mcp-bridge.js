/**
 * HP MCP Bridge - WordPress Abilities to Cursor
 * 
 * This bridge handles communication between Cursor's MCP client and WordPress.
 * It filters responses to only include HP abilities tools (excluding WooCommerce 
 * native tools which have oversized schemas that Cursor can't handle).
 * 
 * Usage: node hp-mcp-bridge.js <API_URL> <API_KEY>
 */

const https = require('https');

const API_URL = process.argv[2];
const API_KEY = process.argv[3];

if (!API_URL || !API_KEY) {
  console.error('Usage: node hp-mcp-bridge.js <API_URL> <API_KEY>');
  process.exit(1);
}

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
          
          // Filter to only include HP abilities tools
          // WooCommerce native tools have oversized schemas (10KB+) that Cursor can't handle
          if (parsed.result && parsed.result.tools && Array.isArray(parsed.result.tools)) {
            parsed.result.tools = parsed.result.tools.filter(tool => {
              return tool.name && tool.name.startsWith('hp-abilities');
            });
          }
          
          resolve(parsed);
        } catch (e) {
          resolve({
            jsonrpc: '2.0',
            id: id,
            error: { code: -32700, message: 'Parse error', data: data.substring(0, 200) }
          });
        }
      });
    });

    req.on('error', (e) => {
      if (isNotification) resolve(null);
      else resolve({ jsonrpc: '2.0', id: id, error: { code: -32603, message: 'Bridge Request Error', data: e.message } });
    });

    const payload = { jsonrpc: '2.0', method: method, params: params };
    if (!isNotification) payload.id = id;

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
      const jsonStr = JSON.stringify(response);
      const canContinue = process.stdout.write(jsonStr + '\n');
      if (!canContinue) {
        await new Promise(resolve => process.stdout.once('drain', resolve));
      }
    }
  } catch (e) {
    // Silent fail - don't pollute stdout
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

process.on('uncaughtException', () => {});

// Keep process alive
process.stdin.resume();
setInterval(() => {}, 30000);
