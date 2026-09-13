<script setup lang="ts">
import { computed } from 'vue';
import type { Detail } from '../../types/maps';
import { number } from '../../lib/format';
const props = defineProps<{
    statistics: Detail['statistics'];
    total: number;
}>();
const distribution = computed(() =>
    [...props.statistics.distribution].reverse(),
);
const positive = computed(() =>
    distribution.value
        .filter((row) => row.rating >= 4)
        .reduce((sum, row) => sum + row.count, 0),
);
</script>

<template>
    <section v-if="total" class="panel analytics">
        <div>
            <p class="eyebrow">СОХРАНЁННАЯ ВЫБОРКА</p>
            <h3>Что говорят клиенты</h3>
            <strong class="positive-value"
                >{{ Math.round((positive / total) * 100) }}%</strong
            >
            <p class="muted">
                отзывов с оценкой 4 или 5.<br />Не статистика всей карточки.
            </p>
        </div>
        <div class="distribution">
            <div v-for="row in distribution" :key="row.rating" class="bar-row">
                <span>{{ row.rating ? row.rating + ' ★' : 'Без оценки' }}</span>
                <div class="bar-track">
                    <div
                        class="bar-fill"
                        :style="{
                            width: `${(row.count / total) * 100}%`,
                        }"
                    ></div>
                </div>
                <span>{{ number(row.count) }}</span>
            </div>
        </div>
    </section>
</template>
