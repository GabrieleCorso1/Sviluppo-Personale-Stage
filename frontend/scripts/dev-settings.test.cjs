const { test } = require('node:test');
const assert = require('node:assert/strict');
const { validateSettings } = require('./dev-settings.cjs');

test('defaults keep backend access on the local PC', () => {
  assert.deepEqual(validateSettings(), { backendPort: 8000, frontendPort: 4200, lanHost: '' });
});

test('rejects public, wildcard, loopback and malformed mobile hosts', () => {
  for (const lanHost of ['*', '0.0.0.0', '127.0.0.1', '8.8.8.8', 'http://10.1.0.2', '10.1.0.2:4200']) {
    assert.throws(() => validateSettings({ lanHost }));
  }
  for (const lanHost of ['10.1.0.2', '192.168.1.2', '172.16.0.2']) {
    assert.equal(validateSettings({ lanHost }).lanHost, lanHost);
  }
});

test('rejects invalid ports and collisions', () => {
  for (const settings of [{backendPort: '8000'}, {frontendPort: 0}, {backendPort: 65536}, {backendPort: 4200}]) {
    assert.throws(() => validateSettings(settings));
  }
});
