<script setup lang="ts">
import type { Organization } from '../../types/maps';
import { number } from '../../lib/format';
defineProps<{ organization: Organization; total: number }>();
</script>

<template>
    <section class="metrics" aria-label="Показатели организации">
        <article class="panel metric">
            <span>Рейтинг Яндекса</span
            ><strong
                >{{
                    organization.rating === null
                        ? '—'
                        : Number(organization.rating).toFixed(1)
                }}
                <small class="star">★</small></strong
            ><small>По данным площадки</small>
        </article>
        <article class="panel metric">
            <span>Всего оценок</span
            ><strong>{{ number(organization.ratings_count) }}</strong
            ><small>Включая оценки без отзыва</small>
        </article>
        <article class="panel metric">
            <span>Отзывов на площадке</span
            ><strong>{{ number(organization.reviews_count) }}</strong
            ><small>{{ number(total) }} в сохранённой выборке</small>
        </article>
    </section>
</template>
