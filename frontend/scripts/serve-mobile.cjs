const path = require('node:path');
const os = require('node:os');
const { spawn } = require('node:child_process');
const { readDevSettings } = require('./dev-settings.cjs');
const { selectMobileHost } = require('./mobile-host.cjs');

try {
  const { lanHost: configuredHost, frontendPort } = readDevSettings();
  const lanHost = selectMobileHost(configuredHost, os.networkInterfaces());
  console.log(`\nDal telefono apri: http://${lanHost}:${frontendPort}/login`);
  if (configuredHost !== lanHost) console.log('Rilevato l’indirizzo attuale della rete. Dopo un cambio di IP riavvia questo comando.');
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
