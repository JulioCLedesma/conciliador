import test from 'node:test';
import assert from 'node:assert/strict';
import { catalogPanel, reconciliationBoard } from '../../resources/js/reconciliation-board.js';

function panel() {
    const state = catalogPanel('fifty');
    state.actionHeight = 80;
    state.catalogBusy = false;
    state.$nextTick = async callback => callback?.();
    state.$watch = () => {};
    state.$refs = {
        rows: { style: {}, scrollTop: 400, scrollHeight: 1500, clientHeight: 500, getBoundingClientRect: () => ({ top: 200 }) },
        footer: { offsetHeight: 80 }, sentinel: {},
    };
    state.$el = { style: { setProperty() {} }, getBoundingClientRect: () => ({ top: 0 }) };
    state.$wire = {
        hasMore: { fifty: true }, loadedIds: { fifty: [1, 2] }, generation: { fifty: 0 },
        $hook: (_, callback) => { state.commitHook = callback; return () => { state.unhooks = (state.unhooks || 0) + 1; }; },
    };
    globalThis.window = { innerHeight: 900 };
    globalThis.IntersectionObserver = class {
        observe() {}
        disconnect() { state.disconnects = (state.disconnects || 0) + 1; }
    };
    return state;
}

test('successive loading preserves scroll and rejects simultaneous requests', async () => {
    const state = panel();
    state.init();
    let finish;
    let calls = 0;
    state.$wire.loadMore = (side, count, generation) => {
        assert.deepEqual([side, count, generation], ['fifty', 2, 0]);
        calls++;
        let respond;
        state.commitHook({ commit: { calls: [{ method: 'loadMore', params: ['fifty'] }] }, fail: () => {}, respond: callback => { respond = callback; } });
        return new Promise(resolve => { finish = () => { respond(); state.$refs.rows.scrollTop = 0; resolve(); }; });
    };
    const pending = state.more();
    await state.more();
    assert.equal(calls, 1);
    state.$refs.rows.scrollTop = 460;
    finish();
    await pending;
    assert.equal(state.$refs.rows.scrollTop, 460);
    assert.equal(state.busy, false);
    state.$wire.hasMore.fifty = false;
    await state.more();
    assert.equal(calls, 1);
    state.destroy();
});

test('Livewire failed commits release the lock and permit manual retry', async () => {
    const state = panel();
    state.init();
    state.$wire.loadMore = () => new Promise(() => {});
    const pending = state.more();
    let fail;
    state.commitHook({ commit: { calls: [{ method: 'loadMore', params: ['fifty'] }] }, fail: callback => { fail = callback; }, respond: () => {} });
    fail();
    await pending;
    assert.equal(state.failed, true);
    assert.equal(state.busy, false);
    assert.equal(state.catalogBusy, false);
    state.$wire.loadMore = async () => {};
    await state.more();
    assert.equal(state.failed, false);
    state.destroy();
});

test('reset targets only its own panel and observers and hooks are cleaned up', async () => {
    const state = panel();
    state.init();
    state.reset('pemex');
    assert.equal(state.$refs.rows.scrollTop, 400);
    state.reset('fifty');
    assert.equal(state.$refs.rows.scrollTop, 0);
    state.init();
    state.destroy();
    assert.equal(state.disconnects, 2);
    assert.equal(state.unhooks, 2);
});

test('a new search during loading keeps its reset position', async () => {
    const state = panel();
    let finish;
    state.$wire.loadMore = () => new Promise(resolve => { finish = resolve; });
    const pending = state.more();
    state.$wire.generation.fifty++;
    state.$refs.rows.scrollTop = 0;
    finish();
    await pending;
    assert.equal(state.$refs.rows.scrollTop, 0);
});

test('persistent action bar reserves its measured height and disconnects on removal', async () => {
    const state = reconciliationBoard();
    state.$wire = { $hook: () => () => {} };
    let resized;
    let disconnected = false;
    globalThis.ResizeObserver = class {
        constructor(callback) { resized = callback; }
        observe() {}
        disconnect() { disconnected = true; }
    };
    state.$nextTick = async callback => callback?.();
    state.$refs = { actions: { offsetHeight: 132 } };
    const properties = {};
    state.$el = { style: { setProperty(name, value) { properties[name] = value; } } };
    state.init();
    resized();
    assert.equal(properties['--actions-height'], '132px');
    state.destroy();
    assert.equal(disconnected, true);
});

 test('selection uses only Livewire state including header indeterminate state and later blocks', () => {
    const state = panel();
    state.$wire.selected = { fifty: { 1: 0 } };
    assert.equal(state.isSelected(1), true);
    assert.equal(state.someLoadedSelected(), true);
    assert.equal(state.allLoadedSelected(), false);
    state.$wire.selected.fifty[2] = 0;
    assert.equal(state.allLoadedSelected(), true);
    state.$wire.selected.fifty = {};
    assert.equal(state.someLoadedSelected(), false);
    assert.equal(state.allLoadedSelected(), false);
    state.$wire.loadedIds.fifty.push(3);
    assert.equal(state.isSelected(3), false);
});
