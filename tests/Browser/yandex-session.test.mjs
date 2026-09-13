import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
const source = readFileSync(
    new URL(
        '../../app/Services/Review/Browser/yandex-session.js',
        import.meta.url,
    ),
    'utf8',
);
function session(
    responses,
    config = {
        csrfToken: 'initial',
        counters: { analytics: { sessionId: 'session' } },
    },
) {
    const calls = [];
    const window = {};
    runInNewContext(source, {
        window,
        document: {
            querySelector: () => ({ textContent: JSON.stringify({ config }) }),
        },
        location: { href: 'https://yandex.ru/maps/' },
        AbortSignal,
        fetch: async (url) => {
            calls.push(url);
            return {
                status: 200,
                redirected: false,
                text: async () => JSON.stringify(responses.shift()),
            };
        },
    });
    return { api: window.__yandexSession, calls };
}
void test('serializes nested query using RFC3986 and omits undefined and empty objects', () => {
    const { api } = session([]);
    assert.equal(
        api.serialize({
            z: {},
            B: null,
            a: { x: ['a b', "!'()*"] },
            c: undefined,
        }),
        'a%5Bx%5D%5B0%5D=a%20b&a%5Bx%5D%5B1%5D=%21%27%28%29%2A&B=',
    );
    assert.equal(api.sign('abc'), '193409669');
});
void test('refreshes CSRF once and retains new token for later pages', async () => {
    const { api, calls } = session([
        { csrfToken: 'new' },
        { data: {} },
        { data: {} },
    ]);
    await api.request({
        endpoint: 'business/fetchReviews',
        query: { page: 1 },
    });
    await api.request({
        endpoint: 'business/fetchReviews',
        query: { page: 2 },
    });
    assert.equal(calls.length, 3);
    assert.match(calls[0], /csrfToken=initial/);
    assert.match(calls[1], /csrfToken=new/);
    assert.match(calls[2], /csrfToken=new/);
    assert.notEqual(calls[0].split('&s=')[1], calls[1].split('&s=')[1]);
});
void test('stops after second CSRF rejection and detects captcha', async () => {
    const { api, calls } = session([
        { csrfToken: 'new' },
        { csrfToken: 'again' },
    ]);
    assert.equal(
        (await api.request({ endpoint: 'search', query: {} })).failure,
        'session_required',
    );
    assert.equal(calls.length, 2);
    const blocked = await session([{ type: 'captcha' }]).api.request({
        endpoint: 'search',
        query: {},
    });
    assert.equal(blocked.failure, 'blocked');
    assert.equal(blocked.reason, 'captcha');
    assert.equal(blocked.status, 200);
});
