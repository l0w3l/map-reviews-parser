<script setup lang="ts">
defineProps<{ busy: boolean; error: string }>();
const email = defineModel<string>('email', { required: true });
const password = defineModel<string>('password', { required: true });
const emit = defineEmits<{ submit: [] }>();
</script>

<template>
    <main class="login-layout">
        <section class="login-intro">
            <p class="eyebrow">ВАШ БИЗНЕС ГЛАЗАМИ КЛИЕНТОВ</p>
            <h1>Каждый отзыв.<br />В одной картине.</h1>
            <p>
                Отзывы, рейтинг и история обновлений ваших организаций на
                Яндекс.Картах.
            </p>
            <div class="intro-stars" aria-hidden="true">★★★★★</div>
        </section>
        <form class="panel login-card" @submit.prevent="emit('submit')">
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
</template>
