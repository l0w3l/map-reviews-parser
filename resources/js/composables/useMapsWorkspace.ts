import { request, ApiError } from '../lib/http';
import { ref, onMounted, onUnmounted } from 'vue';
import type { Organization, Detail } from '../types/maps';

export function useMapsWorkspace() {
    const user = ref<{ name: string } | null>(null);
    const email = ref('');
    const password = ref('');
    const url = ref('');
    const error = ref('');
    const busy = ref(false);
    const ready = ref(false);
    const organizations = ref<Organization[]>([]);
    const detail = ref<Detail | null>(null);
    const configured = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let disposed = false;
    let selectionVersion = 0;
    async function refresh() {
        if (!detail.value) return;
        url.value = detail.value.organization.url;
        await save(detail.value.organization.provider);
    }

    async function api<T = void>(
        path: string,
        method = 'GET',
        body?: object,
    ): Promise<T> {
        try {
            return await request<T>(path, method, body);
        } catch (e) {
            if (e instanceof ApiError && e.status === 401) {
                clearTimeout(timer);
                selectionVersion++;
                user.value = null;
                detail.value = null;
            }
            throw e;
        }
    }
    async function action(callback: () => Promise<void>) {
        busy.value = true;
        error.value = '';
        try {
            await callback();
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Ошибка запроса';
        } finally {
            busy.value = false;
        }
    }
    async function list() {
        const result = await api<{ data: Organization[]; configured: boolean }>(
            '/api/organizations',
        );
        organizations.value = result.data;
        configured.value = result.configured;
    }
    async function select(id: number, page = 1) {
        clearTimeout(timer);
        const version = ++selectionVersion;
        const result = await api<Detail>(
            `/api/organizations/${id}?page=${page}`,
        );
        if (disposed || version !== selectionVersion) return;
        detail.value = result;
        if (
            !disposed &&
            ['queued', 'running', 'retrying'].includes(
                detail.value!.organization.status,
            )
        ) {
            timer = setTimeout(() => {
                void (async () => {
                    try {
                        await select(id, page);
                        await list();
                    } catch (e) {
                        error.value =
                            e instanceof Error
                                ? e.message
                                : 'Ошибка обновления';
                    }
                })();
            }, 3000);
        }
    }
    async function login() {
        await action(async () => {
            await api('/sanctum/csrf-cookie');
            await api('/login', 'POST', {
                email: email.value,
                password: password.value,
            });
            password.value = '';
            user.value = await api<{ name: string }>('/api/user');
            await list();
        });
    }
    async function save(provider?: string) {
        await action(async () => {
            const result = await api<{ id: number }>(
                '/api/organizations',
                'POST',
                {
                    url: url.value,
                    provider,
                },
            );
            await list();
            await select(result.id);
        });
    }
    async function logout() {
        await action(async () => {
            await api('/logout', 'POST');
            clearTimeout(timer);
            selectionVersion++;
            user.value = null;
            detail.value = null;
        });
    }
    onMounted(async () => {
        try {
            user.value = await api<{ name: string }>('/api/user');
            await list();
            if (organizations.value[0]) await select(organizations.value[0].id);
        } catch (e) {
            if (user.value || !(e instanceof ApiError && e.status === 401))
                error.value = String(e);
        } finally {
            ready.value = true;
        }
    });
    onUnmounted(() => {
        disposed = true;
        clearTimeout(timer);
    });

    return {
        user,
        email,
        password,
        url,
        error,
        busy,
        ready,
        organizations,
        detail,
        configured,
        login,
        logout,
        save,
        refresh,
        action,
        select,
    };
}
