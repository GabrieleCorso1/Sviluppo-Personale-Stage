const path = require('node:path');
const os = require('node:os');
const { spawn } = require('node:child_process');
const { readDevSettings } = require('./dev-settings.cjs');

try {
  const { lanHost, frontendPort } = readDevSettings();
  const addresses = Object.values(os.networkInterfaces()).flat()
    .filter(address => address && !address.internal && address.family === 'IPv4')
    .map(address => address.address);
  if (!lanHost || !addresses.includes(lanHost)) {
    throw new Error('Copia dev.local.example.json in dev.local.json e imposta lanHost con l’IPv4 attuale del PC.');
  }
  const child = spawn(process.execPath, [
    path.join(__dirname, '..', 'node_modules', '@angular', 'cli', 'bin', 'ng.js'),
    'serve', '--host', lanHost, '--port', String(frontendPort),
  ], { cwd: path.join(__dirname, '..'), stdio: 'inherit' });
  child.on('error', error => { console.error(error.message); process.exitCode = 1; });
  child.on('exit', code => { process.exitCode = code ?? 1; });
  process.on('SIGINT', () => child.kill('SIGINT'));
  process.on('SIGTERM', () => child.kill('SIGTERM'));
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
