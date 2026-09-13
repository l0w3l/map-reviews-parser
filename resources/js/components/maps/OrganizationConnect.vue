<script setup lang="ts">
defineProps<{
    busy: boolean;
}>();
const url = defineModel<string>('url', { required: true });
const emit = defineEmits<{ save: [] }>();
</script>

<template>
    <section class="panel connection">
        <div>
            <h2>Подключить организацию</h2>
            <p class="muted">
                Вставьте ссылку на карточку Яндекс.Карт. Сбор продолжится в
                фоне.
            </p>
        </div>
        <form class="inline-form" @submit.prevent="emit('save')">
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
    </section>
</template>
