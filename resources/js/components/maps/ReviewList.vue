<script setup lang="ts">
import type { Detail } from '../../types/maps';
import { number } from '../../lib/format';
import ReviewCard from './ReviewCard.vue';
defineProps<{ reviews: Detail['reviews']; active: boolean; busy: boolean }>();
const emit = defineEmits<{ page: [page: number] }>();
</script>

<template>
    <section class="panel reviews">
        <div class="section-heading">
            <h3>
                Отзывы клиентов
                <span class="count">{{ number(reviews.total) }}</span>
            </h3>
            <span class="muted">По 50 на странице</span>
        </div>
        <p v-if="!reviews.total" class="empty">
            {{
                active
                    ? 'Собираем отзывы. Они появятся после завершения обновления.'
                    : 'Пока нет сохранённых отзывов.'
            }}
        </p>
        <ReviewCard
            v-for="review in reviews.data"
            :key="review.id"
            :review="review"
        />
        <nav
            v-if="reviews.total"
            class="pagination"
            aria-label="Страницы отзывов"
        >
            <button
                :disabled="busy || reviews.current_page <= 1"
                @click="emit('page', reviews.current_page - 1)"
            >
                ← Назад</button
            ><span>{{ reviews.current_page }} из {{ reviews.last_page }}</span
            ><button
                :disabled="busy || reviews.current_page >= reviews.last_page"
                @click="emit('page', reviews.current_page + 1)"
            >
                Вперёд →
            </button>
        </nav>
    </section>
</template>
