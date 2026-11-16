const { readDevSettings } = require('./scripts/dev-settings.cjs');
const { backendPort } = readDevSettings();

module.exports = Object.fromEntries(['/api/**', '/images/defaults/**'].map(context => [context, {
  target: `http://127.0.0.1:${backendPort}`,
  changeOrigin: true,
}]));
