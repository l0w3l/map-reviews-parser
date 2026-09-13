<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue';
type Organization = {
    id: number;
    url: string;
    name: string | null;
    rating: string | null;
    ratings_count: number | null;
    reviews_count: number | null;
    status: string;
    error: string | null;
    synced_at: string | null;
};
type Review = {
    id: number;
    author: string;
    published_at: string;
    text: string;
    rating: number;
};
type Detail = {
    statistics: {
        distribution: { rating: number; count: number }[];
        source_limited: boolean;
    };
    history: {
        id: number;
        status: string;
        collected: number;
        updated_at: string;
        error: string | null;
    }[];
    organization: Organization;
    run: { collected: number } | null;
    reviews: {
        data: Review[];
        current_page: number;
        last_page: number;
        total: number;
    };
};
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
const query = ref('');
const searchResults = ref<
    {
        type: string;
        id: string | null;
        title: string | null;
        address: string | null;
    }[]
>([]);
const searched = ref(false);
async function search() {
    await action(async () => {
        const result = await api(
            `/api/organizations/search?query=${encodeURIComponent(query.value)}`,
        );
        searchResults.value = result.items.filter(
            (item: { type: string; id: string | null }) =>
                item.type === 'business' && item.id,
        );
        searched.value = true;
    });
}
async function connect(id: string) {
    url.value = `https://yandex.ru/maps/org/${id}/`;
    await save();
}
let timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;
let selectionVersion = 0;
const statuses: Record<string, string> = {
    queued: 'В очереди',
    running: 'Собираем отзывы',
    retrying: 'Повторная попытка',
    completed: 'Обновлено',
    failed: 'Ошибка обновления',
    blocked: 'Доступ ограничен',
};
const statusLabel = (status: string) => statuses[status] ?? status;
const number = (value: number | null | undefined) =>
    value == null ? '—' : new Intl.NumberFormat('ru-RU').format(value);
const date = (value: string | null) =>
    value
        ? new Date(
              value.includes('T') ? value : value.replace(' ', 'T') + 'Z',
          ).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' })
        : 'Ещё не обновлялось';
const distribution = computed(() =>
    [...(detail.value?.statistics.distribution ?? [])].reverse(),
);
const positive = computed(() =>
    distribution.value
        .filter((row) => row.rating >= 4)
        .reduce((sum, row) => sum + row.count, 0),
);
const active = computed(
    () =>
        detail.value &&
        ['queued', 'running', 'retrying'].includes(
            detail.value.organization.status,
        ),
);
async function refresh() {
    if (!detail.value) return;
    url.value = detail.value.organization.url;
    await save();
}

async function api(path: string, method = 'GET', body?: object) {
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
        if (response.status === 401) user.value = null;
        const payload = await response.json().catch(() => ({}));
        throw new Error(
            response.status === 419
                ? 'Сессия истекла. Обновите страницу.'
                : Object.values(payload.errors ?? {})
                      .flat()
                      .join(' ') ||
                      payload.message ||
                      `Ошибка HTTP ${response.status}`,
        );
    }
    return response.status === 204 ? null : response.json();
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
    const result = await api('/api/organizations');
    organizations.value = result.data;
    configured.value = result.configured;
}
async function select(id: number, page = 1) {
    clearTimeout(timer);
    const version = ++selectionVersion;
    const result = await api(`/api/organizations/${id}?page=${page}`);
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
                        e instanceof Error ? e.message : 'Ошибка обновления';
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
        user.value = await api('/api/user');
        await list();
    });
}
async function save() {
    await action(async () => {
        const result = await api('/api/organizations', 'POST', {
            url: url.value,
        });
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
        user.value = await api('/api/user');
        await list();
        if (organizations.value[0]) await select(organizations.value[0].id);
    } catch (e) {
        if (
            user.value ||
            (e instanceof Error && e.message !== 'Unauthenticated.')
        )
            error.value = String(e);
    } finally {
        ready.value = true;
    }
});
onUnmounted(() => {
    disposed = true;
    clearTimeout(timer);
});
</script>

<template>
    <div class="app-shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="На главную"
                ><span class="brand-icon">↗</span> На карте<span
                    class="brand-caption"
                    >репутация бизнеса</span
                ></a
            >
            <div v-if="user" class="account">
                <span>{{ user.name }}</span
                ><button class="button-quiet" :disabled="busy" @click="logout">
                    Выйти ↗
                </button>
            </div>
        </header>
        <main v-if="!ready" class="empty" role="status">
            Загружаем рабочее пространство…
        </main>
        <main v-else-if="!user" class="login-layout">
            <section class="login-intro">
                <p class="eyebrow">ВАШ БИЗНЕС ГЛАЗАМИ КЛИЕНТОВ</p>
                <h1>Каждый отзыв.<br />В одной картине.</h1>
                <p>
                    Отзывы, рейтинг и история обновлений ваших организаций на
                    Яндекс.Картах.
                </p>
                <div class="intro-stars" aria-hidden="true">★★★★★</div>
            </section>
            <form class="panel login-card" @submit.prevent="login">
                <p class="eyebrow">РАБОЧЕЕ ПРОСТРАНСТВО</p>
                <h2>Добро пожаловать</h2>
                <p class="muted">Войдите с учётной записью вашей команды.</p>
                <label
                    >Email<input
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required
                        placeholder="name@company.ru" /></label
                ><label
                    >Пароль<input
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        placeholder="Ваш пароль"
                /></label>
                <p v-if="error" class="error" role="alert">{{ error }}</p>
                <button class="button-primary" :disabled="busy">
                    {{ busy ? 'Входим…' : 'Войти в кабинет →' }}
                </button>
            </form>
        </main>
        <main v-else class="workspace">
            <div class="page-heading">
                <div>
                    <p class="eyebrow">ОБЗОР РЕПУТАЦИИ</p>
                    <h1>Организации и отзывы</h1>
                    <p class="muted">
                        Следите за обратной связью и держите данные под рукой.
                    </p>
                </div>
                <span class="pill"
                    >{{ number(organizations.length) }} организаций</span
                >
            </div>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <p v-if="!configured" class="notice">
                Подключение к Яндексу не настроено. Сохранённые отзывы доступны.
            </p>
            <section class="panel connection">
                <div>
                    <h2>Подключить организацию</h2>
                    <p class="muted">
                        Вставьте ссылку на карточку Яндекс.Карт. Сбор
                        продолжится в фоне.
                    </p>
                </div>
                <form class="inline-form" @submit.prevent="save">
                    <label class="grow"
                        ><span class="sr-only">Ссылка на организацию</span
                        ><input
                            v-model="url"
                            type="url"
                            required
                            placeholder="https://yandex.ru/maps/org/…" /></label
                    ><button class="button-primary" :disabled="busy">
                        {{ busy ? 'Подождите…' : 'Подключить →' }}
                    </button>
                </form>
                <details>
                    <summary>Не знаете ссылку? Поиск по названию</summary>
                    <form class="inline-form" @submit.prevent="search">
                        <label class="grow"
                            ><span class="sr-only">Название и город</span
                            ><input
                                v-model="query"
                                minlength="2"
                                maxlength="500"
                                required
                                placeholder="Название организации, город" /></label
                        ><button :disabled="busy">Найти</button>
                    </form>
                    <p v-if="searched && !searchResults.length" class="muted">
                        Ничего не найдено. Уточните город или вставьте ссылку.
                    </p>
                    <div
                        v-for="item in searchResults"
                        :key="item.id!"
                        class="search-result"
                    >
                        <div>
                            <strong>{{ item.title }}</strong>
                            <p class="muted">{{ item.address }}</p>
                        </div>
                        <button :disabled="busy" @click="connect(item.id!)">
                            Подключить
                        </button>
                    </div>
                </details>
            </section>
            <div class="content-layout">
                <aside class="panel organization-list">
                    <p class="eyebrow">МОИ ОРГАНИЗАЦИИ</p>
                    <nav aria-label="Организации">
                        <button
                            v-for="org in organizations"
                            :key="org.id"
                            :class="{
                                selected: detail?.organization.id === org.id,
                            }"
                            :aria-current="
                                detail?.organization.id === org.id
                                    ? 'true'
                                    : undefined
                            "
                            :disabled="busy"
                            @click="action(() => select(org.id))"
                        >
                            <strong>{{
                                org.name || 'Новая организация'
                            }}</strong
                            ><span>{{ statusLabel(org.status) }}</span>
                        </button>
                    </nav>
                    <p v-if="!organizations.length" class="muted">
                        Подключите первую карточку, чтобы увидеть отзывы.
                    </p>
                </aside>
                <div
                    v-if="detail"
                    class="organization-detail"
                    :aria-busy="busy"
                >
                    <section class="detail-heading">
                        <div>
                            <h2>
                                {{
                                    detail.organization.name ||
                                    'Новая организация'
                                }}
                            </h2>
                            <p class="muted">
                                {{ date(detail.organization.synced_at) }} ·
                                <a
                                    :href="detail.organization.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    >Карточка на Яндексе ↗</a
                                >
                            </p>
                        </div>
                        <button :disabled="busy || !!active" @click="refresh">
                            ↻ Обновить
                        </button>
                    </section>
                    <div class="sync-status" role="status">
                        <span
                            class="status-dot"
                            :class="detail.organization.status"
                        ></span
                        >{{ statusLabel(detail.organization.status)
                        }}<span v-if="active">
                            · Получено
                            {{ number(detail.run?.collected) }} отзывов</span
                        >
                    </div>
                    <p
                        v-if="detail.organization.error"
                        class="error"
                        role="alert"
                    >
                        {{ detail.organization.error }} Предыдущие сохранённые
                        данные остаются доступны.
                    </p>
                    <section
                        class="metrics"
                        aria-label="Показатели организации"
                    >
                        <article class="panel metric">
                            <span>Рейтинг Яндекса</span
                            ><strong
                                >{{
                                    detail.organization.rating === null
                                        ? '—'
                                        : Number(
                                              detail.organization.rating,
                                          ).toFixed(1)
                                }}
                                <small class="star">★</small></strong
                            ><small>По данным площадки</small>
                        </article>
                        <article class="panel metric">
                            <span>Всего оценок</span
                            ><strong>{{
                                number(detail.organization.ratings_count)
                            }}</strong
                            ><small>Включая оценки без отзыва</small>
                        </article>
                        <article class="panel metric">
                            <span>Отзывов на площадке</span
                            ><strong>{{
                                number(detail.organization.reviews_count)
                            }}</strong
                            ><small
                                >{{ number(detail.reviews.total) }} в
                                сохранённой выборке</small
                            >
                        </article>
                    </section>
                    <p v-if="detail.statistics.source_limited" class="notice">
                        Собраны 600 доступных отзывов. Яндекс ограничивает
                        глубину выдачи; общий счётчик площадки может быть
                        больше.
                    </p>
                    <section
                        v-if="detail.reviews.total"
                        class="panel analytics"
                    >
                        <div>
                            <p class="eyebrow">СОХРАНЁННАЯ ВЫБОРКА</p>
                            <h3>Что говорят клиенты</h3>
                            <strong class="positive-value"
                                >{{
                                    Math.round(
                                        (positive / detail.reviews.total) * 100,
                                    )
                                }}%</strong
                            >
                            <p class="muted">
                                отзывов с оценкой 4 или 5.<br />Не статистика
                                всей карточки.
                            </p>
                        </div>
                        <div class="distribution">
                            <div
                                v-for="row in distribution"
                                :key="row.rating"
                                class="bar-row"
                            >
                                <span>{{
                                    row.rating
                                        ? row.rating + ' ★'
                                        : 'Без оценки'
                                }}</span>
                                <div class="bar-track">
                                    <div
                                        class="bar-fill"
                                        :style="{
                                            width: `${(row.count / detail.reviews.total) * 100}%`,
                                        }"
                                    ></div>
                                </div>
                                <span>{{ number(row.count) }}</span>
                            </div>
                        </div>
                    </section>
                    <section class="panel reviews">
                        <div class="section-heading">
                            <h3>
                                Отзывы клиентов
                                <span class="count">{{
                                    number(detail.reviews.total)
                                }}</span>
                            </h3>
                            <span class="muted">По 50 на странице</span>
                        </div>
                        <p v-if="!detail.reviews.total" class="empty">
                            {{
                                active
                                    ? 'Собираем отзывы. Они появятся после завершения обновления.'
                                    : 'Пока нет сохранённых отзывов.'
                            }}
                        </p>
                        <article
                            v-for="review in detail.reviews.data"
                            :key="review.id"
                            class="review"
                        >
                            <div class="review-heading">
                                <span class="avatar" aria-hidden="true">{{
                                    review.author.charAt(0).toUpperCase()
                                }}</span>
                                <div class="grow">
                                    <strong>{{ review.author }}</strong
                                    ><time :datetime="review.published_at"
                                        >Обновлён
                                        {{ date(review.published_at) }}</time
                                    >
                                </div>
                                <span class="review-rating">{{
                                    review.rating
                                        ? review.rating + ' ★'
                                        : 'Без оценки'
                                }}</span>
                            </div>
                            <p class="review-text">
                                {{ review.text || 'Без текста' }}
                            </p>
                        </article>
                        <nav
                            v-if="detail.reviews.total"
                            class="pagination"
                            aria-label="Страницы отзывов"
                        >
                            <button
                                :disabled="
                                    busy || detail.reviews.current_page <= 1
                                "
                                @click="
                                    action(() =>
                                        select(
                                            detail!.organization.id,
                                            detail!.reviews.current_page - 1,
                                        ),
                                    )
                                "
                            >
                                ← Назад</button
                            ><span
                                >{{ detail.reviews.current_page }} из
                                {{ detail.reviews.last_page }}</span
                            ><button
                                :disabled="
                                    busy ||
                                    detail.reviews.current_page >=
                                        detail.reviews.last_page
                                "
                                @click="
                                    action(() =>
                                        select(
                                            detail!.organization.id,
                                            detail!.reviews.current_page + 1,
                                        ),
                                    )
                                "
                            >
                                Вперёд →
                            </button>
                        </nav>
                    </section>
                    <section class="panel history">
                        <h3>Последние обновления</h3>
                        <div
                            v-for="run in detail.history"
                            :key="run.id"
                            class="history-row"
                        >
                            <span>{{ date(run.updated_at) }}</span
                            ><span>{{ statusLabel(run.status) }}</span
                            ><strong
                                >{{ number(run.collected) }} отзывов</strong
                            >
                        </div>
                    </section>
                </div>
                <section v-else class="panel empty">
                    <h2>Здесь будет ваша репутация</h2>
                    <p class="muted">
                        Подключите организацию или выберите её в списке слева.
                    </p>
                </section>
            </div>
            <footer>
                На карте · Данные Яндекс.Карт · Обновление по запросу
            </footer>
        </main>
    </div>
</template>
