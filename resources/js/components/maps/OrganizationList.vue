<script setup lang="ts">
import type { Organization } from '../../types/maps';
import { statusLabel } from '../../lib/format';
defineProps<{
    organizations: Organization[];
    selectedId?: number;
    busy: boolean;
}>();
const emit = defineEmits<{ select: [id: number] }>();
</script>

<template>
    <aside class="panel organization-list">
        <p class="eyebrow">МОИ ОРГАНИЗАЦИИ</p>
        <nav aria-label="Организации">
            <button
                v-for="org in organizations"
                :key="org.id"
                :class="{
                    selected: selectedId === org.id,
                }"
                :aria-current="selectedId === org.id ? 'true' : undefined"
                :disabled="busy"
                @click="emit('select', org.id)"
            >
                <strong>{{ org.name || 'Новая организация' }}</strong
                ><span>{{ statusLabel(org.status) }}</span>
            </button>
        </nav>
        <p v-if="!organizations.length" class="muted">
            Подключите первую карточку, чтобы увидеть отзывы.
        </p>
    </aside>
</template>
