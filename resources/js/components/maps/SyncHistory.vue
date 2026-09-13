<script setup lang="ts">
import type { Detail } from '../../types/maps';
import { date, number, statusLabel } from '../../lib/format';
defineProps<{ history: Detail['history'] }>();
</script>

<template>
    <section class="panel history">
        <h3>Последние обновления</h3>
        <div v-for="run in history" :key="run.id" class="history-row">
            <span>{{ date(run.updated_at) }}</span
            ><span>{{ statusLabel(run.status) }}</span
            ><strong>{{ number(run.collected) }} отзывов</strong>
        </div>
    </section>
</template>
