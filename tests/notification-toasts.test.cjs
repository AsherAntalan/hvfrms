const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

const source = readFileSync('resources/views/layouts/app.blade.php', 'utf8');
const selection = source.slice(source.indexOf('    function notificationId('), source.indexOf('    function notificationLink('));

test('opening popups include accounts and orders even with many critical inventory alerts', () => {
    const context = vm.createContext({});
    vm.runInContext(selection, context);
    const items = Array.from({ length: 20 }, (_, id) => ({ id: `alert-${id}`, category: 'inventory', severity: 'critical' }));
    items.push({ id: 'account', type: 'account_request', severity: 'info' });
    items.push({ id: 'delivery', type: 'delivery', severity: 'info' });
    items.push({ id: 'recovery', type: 'recovery', severity: 'warning' });
    const result = context.startupNotifications(items);
    assert.equal(result.length, 4);
    assert.ok(result.some(item => item.id === 'account'));
    assert.ok(result.some(item => item.id === 'delivery'));
    assert.ok(result.some(item => item.id === 'recovery'));
    assert.equal(items.length, 23);
    assert.equal(new Set(result.map(item => item.id)).size, 4);
    assert.equal(context.startupNotifications([]).length, 0);
});

test('popup automatically dismisses after seven seconds without mutating notification data', () => {
    const timers = [];
    const toast = {
        classList: { add() {} },
        querySelector: () => ({ addEventListener() {}, appendChild() {} }),
        remove() { this.removed = true; },
    };
    const context = vm.createContext({
        toastBox: { children: [], appendChild() {} },
        sevIcons: { info: { icon: 'ph-info', color: 'blue' } },
        document: { createElement: () => toast },
        window: { matchMedia: () => ({ matches: true }) },
        notificationLink: () => null,
        escHtml: text => text,
        setTimeout: (fn, delay) => { timers.push({ fn, delay }); return timers.length; },
        clearTimeout() {},
    });
    vm.runInContext(source.slice(source.indexOf('    function showToast('), source.indexOf('    function updateSidebarBadge(')), context);
    const notice = { type: 'account_request', severity: 'info', message: 'Approval needed', context: 'Baguio' };
    const before = JSON.stringify(notice);
    context.showToast(notice);
    assert.equal(timers[0].delay, 7000);
    timers[0].fn();
    timers[1].fn();
    assert.equal(toast.removed, true);
    assert.equal(JSON.stringify(notice), before);
});
