const { test } = require('node:test');
const assert = require('node:assert/strict');
const { selectMobileHost } = require('./mobile-host.cjs');
const entry = address => ({ address, internal: false });
test('riconosce il nuovo IP Wi-Fi e ignora la rete virtuale', () => {
  assert.equal(selectMobileHost('10.1.0.243', { 'Wi-Fi': [entry('10.1.0.8')], 'vEthernet': [entry('172.28.32.1')] }), '10.1.0.8');
});
test('conserva una scelta esplicita ancora valida', () => {
  assert.equal(selectMobileHost('192.168.1.2', { Ethernet: [entry('192.168.1.2')], 'Wi-Fi': [entry('10.1.0.8')] }), '192.168.1.2');
});
test('non sceglie reti pubbliche, virtuali o ambigue', () => {
  for (const interfaces of [{ Ethernet: [entry('8.8.8.8')] }, { vEthernet: [entry('172.28.32.1')] }, { Ethernet: [entry('10.1.0.8'), entry('10.1.0.9')] }]) {
    assert.throws(() => selectMobileHost('', interfaces));
  }
});
