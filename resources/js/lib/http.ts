export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
    }
}

export async function request<T = void>(
    path: string,
    method = 'GET',
    body?: object,
): Promise<T> {
    const token = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw new ApiError(
            response.status,
            response.status === 419
                ? 'Сессия истекла. Обновите страницу.'
                : Object.values(payload.errors ?? {})
                      .flat()
                      .join(' ') ||
                      payload.message ||
                      `Ошибка HTTP ${response.status}`,
        );
    }
    return response.status === 204 ? (undefined as T) : response.json();
}
