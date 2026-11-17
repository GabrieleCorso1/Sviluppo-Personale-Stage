const { isIPv4 } = require('node:net');

function selectMobileHost(configuredHost, interfaces) {
  const addresses = Object.entries(interfaces).flatMap(([name, entries]) =>
    (entries || []).filter(entry => !entry.internal && isIPv4(entry.address) &&
      /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(entry.address))
      .map(entry => ({ name, address: entry.address })));
  if (configuredHost && addresses.some(entry => entry.address === configuredHost)) return configuredHost;
  const physical = addresses.filter(entry => !/vethernet|virtual|vmware|docker|wsl|loopback|\btun\b|\btap\b/i.test(entry.name));
  const wifi = physical.filter(entry => /wi-?fi|wireless|wlan/i.test(entry.name));
  const candidates = [...new Set((wifi.length ? wifi : physical).map(entry => entry.address))];
  if (candidates.length !== 1) throw new Error('Impossibile scegliere la rete: imposta in dev.local.json il lanHost attuale del PC.');
  return candidates[0];
}

module.exports = { selectMobileHost };
