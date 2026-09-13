<script setup lang="ts">
import type { Review } from '../../types/maps';
import { date } from '../../lib/format';
defineProps<{ review: Review }>();
</script>

<template>
    <article class="review">
        <div class="review-heading">
            <span class="avatar" aria-hidden="true">{{
                review.author.charAt(0).toUpperCase()
            }}</span>
            <div class="grow">
                <strong>{{ review.author }}</strong
                ><time :datetime="review.published_at"
                    >Обновлён {{ date(review.published_at) }}</time
                >
            </div>
            <span class="review-rating">{{
                review.rating ? review.rating + ' ★' : 'Без оценки'
            }}</span>
        </div>
        <p class="review-text">
            {{ review.text || 'Без текста' }}
        </p>
    </article>
</template>
