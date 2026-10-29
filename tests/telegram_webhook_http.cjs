#!/usr/bin/env node

// Loopback-only HTTP QA for the public Telegram front controller. It sends no
// supported command, so no Bot API transport can be reached.
const {randomBytes} = require('node:crypto');
const {spawn, execFileSync} = require('node:child_process');
const net = require('node:net');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const php = '/Applications/XAMPP/xamppfiles/bin/php';
const secret = `telegram_http_${randomBytes(24).toString('hex')}`;
const updateId = 1600000000 + Math.floor(Math.random() * 50000000);
let server, checks = 0;
function check(value, label) {
  if (!value) throw new Error(`FAIL: ${label}`);
  checks++;
  console.log('PASS:', label);
}
async function freePort() {
  return await new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1', () => {
      const port = socket.address().port;
      socket.close(() => resolve(port));
    });
    socket.on('error', reject);
  });
}
async function waitForServer(port) {
  for (let attempt = 0; attempt < 60; attempt++) {
    try {
      const response = await fetch(`http://127.0.0.1:${port}/telegram_webhook.php`);
      if (response.status) return;
    } catch (_) {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error('Local PHP Telegram webhook server did not start.');
}

(async () => {
  const port = await freePort();
  let stderr = '';
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', root], {
    cwd: root,
    env: {...process.env, TELEGRAM_ENABLED: '0', TELEGRAM_WEBHOOK_SECRET: secret},
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  server.stderr.on('data', chunk => { stderr += chunk.toString(); });
  try {
    await waitForServer(port);
    const url = `http://127.0.0.1:${port}/telegram_webhook.php`;
    const get = await fetch(url);
    check(get.status === 405 && get.headers.get('allow') === 'POST', 'HTTP endpoint rejects GET and advertises POST only');
    const missing = await fetch(url, {method: 'POST', body: '{}'});
    check(missing.status === 403, 'HTTP endpoint rejects missing Telegram secret header');
    const wrong = await fetch(url, {method: 'POST', headers: {'X-Telegram-Bot-Api-Secret-Token': 'wrong'}, body: '{}'});
    check(wrong.status === 403, 'HTTP endpoint rejects wrong Telegram secret header');
    const malformed = await fetch(url, {method: 'POST', headers: {'X-Telegram-Bot-Api-Secret-Token': secret}, body: '{broken'});
    check(malformed.status === 400 && (await malformed.json()).error === 'invalid_json', 'HTTP endpoint rejects malformed JSON safely');
    const missingId = await fetch(url, {method: 'POST', headers: {'X-Telegram-Bot-Api-Secret-Token': secret}, body: '{}'});
    check(missingId.status === 400 && (await missingId.json()).error === 'invalid_update_id', 'HTTP endpoint rejects update without numeric update_id');
    const huge = await fetch(url, {method: 'POST', headers: {
      'X-Telegram-Bot-Api-Secret-Token': secret,
      'Content-Type': 'application/json',
    }, body: JSON.stringify({padding: 'x'.repeat(262145)})});
    check(huge.status === 413, 'HTTP endpoint rejects oversized request body');
    const body = JSON.stringify({update_id: updateId, edited_channel_post: {text: 'must not be stored'}});
    const accepted = await fetch(url, {method: 'POST', headers: {
      'X-Telegram-Bot-Api-Secret-Token': secret,
      'Content-Type': 'application/json',
    }, body});
    const acceptedJson = await accepted.json();
    check(accepted.status === 200 && acceptedJson.ok === true && acceptedJson.result === 'ignored',
      'correct secret accepts a sanitized unsupported update without outbound response');
    const duplicate = await fetch(url, {method: 'POST', headers: {
      'X-Telegram-Bot-Api-Secret-Token': secret,
      'Content-Type': 'application/json',
    }, body});
    const duplicateJson = await duplicate.json();
    check(duplicate.status === 200 && duplicateJson.duplicate === true, 'duplicate update_id returns neutral HTTP success');
    const responseText = [await get.text(), await missing.text(), await wrong.text()].join(' ');
    check(!responseText.includes(secret), 'HTTP responses do not disclose configured webhook secret');
    const applicationLogs = stderr.split('\n').filter(line => !/\[\d{3}\]: (GET|POST|PUT) /.test(line)).join('\n');
    check(!applicationLogs.includes(secret), 'application logs do not disclose configured webhook secret');
    console.log(`Completed ${checks} Telegram webhook HTTP checks. Loopback only; no Telegram request sent.`);
  } finally {
    if (server) server.kill('SIGTERM');
    try {
      execFileSync(php, ['-r', `require ${JSON.stringify(path.join(root, 'config.php'))}; db()->prepare('DELETE FROM telegram_webhook_updates WHERE update_id=?')->execute([${updateId}]);`],
        {cwd: root, stdio: 'ignore'});
    } catch (_) {}
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
