<script setup lang="ts">
import AppHeader from './components/maps/AppHeader.vue';
import LoginScreen from './components/maps/LoginScreen.vue';
import OrganizationConnect from './components/maps/OrganizationConnect.vue';
import OrganizationList from './components/maps/OrganizationList.vue';
import OrganizationDetail from './components/maps/OrganizationDetail.vue';
import { number } from './lib/format';
import { useMapsWorkspace } from './composables/useMapsWorkspace';
const {
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
} = useMapsWorkspace();
const selectOrganization = (id: number) => action(() => select(id));
const changePage = (page: number) => {
    if (detail.value)
        return action(() => select(detail.value!.organization.id, page));
};
</script>

<template>
    <div class="app-shell">
        <AppHeader :user="user" :busy="busy" @logout="logout" />
        <main v-if="!ready" class="empty" role="status">
            Загружаем рабочее пространство…
        </main>
        <LoginScreen
            v-else-if="!user"
            v-model:email="email"
            v-model:password="password"
            :busy="busy"
            :error="error"
            @submit="login"
        />
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
            <OrganizationConnect v-model:url="url" :busy="busy" @save="save" />
            <div class="content-layout">
                <OrganizationList
                    :organizations="organizations"
                    :selected-id="detail?.organization.id"
                    :busy="busy"
                    @select="selectOrganization"
                />
                <OrganizationDetail
                    v-if="detail"
                    :detail="detail"
                    :busy="busy"
                    @refresh="refresh"
                    @page="changePage"
                />
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
