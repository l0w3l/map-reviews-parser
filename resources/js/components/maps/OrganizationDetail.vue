<script setup lang="ts">
import { computed } from 'vue';
import type { Detail } from '../../types/maps';
import { date, number, statusLabel } from '../../lib/format';
import OrganizationMetrics from './OrganizationMetrics.vue';
import ReviewStatistics from './ReviewStatistics.vue';
import ReviewList from './ReviewList.vue';
import SyncHistory from './SyncHistory.vue';
const props = defineProps<{ detail: Detail; busy: boolean }>();
const emit = defineEmits<{ refresh: []; page: [page: number] }>();
const active = computed(() =>
    ['queued', 'running', 'retrying'].includes(
        props.detail.organization.status,
    ),
);
</script>

<template>
    <div class="organization-detail" :aria-busy="busy">
        <section class="detail-heading">
            <div>
                <h2>
                    {{ detail.organization.name || 'Новая организация' }}
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
            <button :disabled="busy || !!active" @click="emit('refresh')">
                ↻ Обновить
            </button>
        </section>
        <div class="sync-status" role="status">
            <span class="status-dot" :class="detail.organization.status"></span
            >{{ statusLabel(detail.organization.status)
            }}<span v-if="active">
                · Получено
                {{ number(detail.run?.collected) }} отзывов</span
            >
        </div>
        <p v-if="detail.organization.error" class="error" role="alert">
            {{ detail.organization.error }} Предыдущие сохранённые данные
            остаются доступны.
        </p>
        <OrganizationMetrics
            :organization="detail.organization"
            :total="detail.reviews.total"
        />
        <p v-if="detail.statistics.source_limited" class="notice">
            Собраны 600 доступных отзывов. Яндекс ограничивает глубину выдачи;
            общий счётчик площадки может быть больше.
        </p>
        <ReviewStatistics
            :statistics="detail.statistics"
            :total="detail.reviews.total"
        />
        <ReviewList
            :reviews="detail.reviews"
            :active="active"
            :busy="busy"
            @page="emit('page', $event)"
        />
        <SyncHistory :history="detail.history" />
    </div>
</template>
