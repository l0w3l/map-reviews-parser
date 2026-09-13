(() => {
    const sort = (a, b) =>
        a.toLowerCase() < b.toLowerCase()
            ? -1
            : a.toLowerCase() > b.toLowerCase()
              ? 1
              : 0;
    const encode = (value) =>
        encodeURIComponent(String(value)).replace(
            /[!'()*]/g,
            (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase(),
        );
    const serialize = (query) => {
        const pairs = [];
        const visit = (key, value) => {
            if (value === undefined) return;
            if (value !== null && typeof value === 'object') {
                for (const child of Object.keys(value).sort(sort))
                    visit(`${key}[${child}]`, value[child]);
            } else
                pairs.push(
                    `${encode(key)}=${encode(value === null ? '' : value)}`,
                );
        };
        for (const key of Object.keys(query).sort(sort)) visit(key, query[key]);
        return pairs.join('&');
    };
    const sign = (text) => {
        let hash = 5381;
        for (let i = 0; i < text.length; i++)
            hash = (hash * 33) ^ text.charCodeAt(i);
        return String(hash >>> 0);
    };
    let refreshedToken;
    const context = () => {
        try {
            const { config } = JSON.parse(
                document.querySelector('script.state-view')?.textContent ||
                    '{}',
            );
            if (!config?.csrfToken || !config?.counters?.analytics?.sessionId)
                return null;
            return {
                csrfToken: refreshedToken || config.csrfToken,
                sessionId: config.counters.analytics.sessionId,
                host_config: config.hostConfig,
                host_exp: config.hostExp,
                ajax: '1',
            };
        } catch {
            return null;
        }
    };
    window.__yandexSession = {
        serialize,
        sign,
        context,
        request: async ({ endpoint, query }) => {
            if (!context()) return { failure: 'session_required' };
            for (let attempt = 0; attempt < 2; attempt++) {
                const params = { ...context(), ...query };
                delete params.s;
                const encoded = serialize(params);
                const response = await fetch(
                    '/maps/api/' +
                        endpoint +
                        '?' +
                        encoded +
                        '&s=' +
                        sign(encoded),
                    {
                        credentials: 'same-origin',
                        headers: { 'X-Retpath-Y': location.href },
                        signal: AbortSignal.timeout(30000),
                    },
                );
                const text = await response.text();
                let payload = null;
                try {
                    payload = JSON.parse(text);
                } catch {}
                if (
                    payload?.type === 'captcha' ||
                    text.trim().toLowerCase() === 'limited'
                ) {
                    return {
                        failure: 'blocked',
                        reason:
                            payload?.type === 'captcha' ? 'captcha' : 'limited',
                        status: response.status,
                    };
                }
                if (
                    response.status === 200 &&
                    !response.redirected &&
                    typeof payload?.csrfToken === 'string'
                ) {
                    if (attempt === 1 || !payload.csrfToken)
                        return { failure: 'session_required' };
                    refreshedToken = payload.csrfToken;
                    continue;
                }
                return {
                    status: response.status,
                    redirected: response.redirected,
                    payload,
                };
            }
        },
    };
})();
