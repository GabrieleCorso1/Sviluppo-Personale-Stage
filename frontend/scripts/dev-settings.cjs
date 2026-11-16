const fs = require('node:fs');
const path = require('node:path');
const { isIPv4 } = require('node:net');

const settingsPath = path.join(__dirname, '..', 'dev.local.json');

function validateSettings(settings = {}) {
  const result = { backendPort: 8000, frontendPort: 4200, lanHost: '', ...settings };
  for (const key of ['backendPort', 'frontendPort']) {
    if (!Number.isInteger(result[key]) || result[key] < 1024 || result[key] > 65535) {
      throw new Error(`${key}: indica una porta tra 1024 e 65535 in dev.local.json.`);
    }
  }
  if (result.backendPort === result.frontendPort) {
    throw new Error('Frontend e backend devono usare porte diverse.');
  }
  if (typeof result.lanHost !== 'string' || (result.lanHost !== '' &&
      (!isIPv4(result.lanHost) || !/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(result.lanHost)))) {
    throw new Error('lanHost: indica l’indirizzo IPv4 privato del PC, senza porta o http://.');
  }
  return result;
}

function readDevSettings() {
  const settings = fs.existsSync(settingsPath)
    ? JSON.parse(fs.readFileSync(settingsPath, 'utf8').replace(/^\uFEFF/, ''))
    : {};
  return validateSettings(settings);
}

module.exports = { readDevSettings, validateSettings };
