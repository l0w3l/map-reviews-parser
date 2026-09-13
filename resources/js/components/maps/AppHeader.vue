<script setup lang="ts">
defineProps<{ user: { name: string } | null; busy: boolean }>();
const emit = defineEmits<{ logout: [] }>();
</script>

<template>
    <header class="topbar">
        <a class="brand" href="/" aria-label="На главную"
            ><span class="brand-icon">↗</span> На карте<span
                class="brand-caption"
                >репутация бизнеса</span
            ></a
        >
        <div v-if="user" class="account">
            <span>{{ user.name }}</span
            ><button
                class="button-quiet"
                :disabled="busy"
                @click="emit('logout')"
            >
                Выйти ↗
            </button>
        </div>
    </header>
</template>
