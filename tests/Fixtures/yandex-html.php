<?php

function reviewPage(int $page, array $ids, int $count = 2, int $pages = 2, int $remaining = 0): array
{
    return ['data' => ['reviews' => array_map(fn ($id) => [
        'reviewId' => $id, 'businessId' => '112125712262', 'author' => ['name' => 'Test'],
        'text' => 'Review', 'rating' => 5, 'updatedTime' => '2024-08-08T11:13:38.527Z',
    ], $ids), 'params' => ['page' => $page, 'count' => $count, 'totalPages' => $pages, 'reviewsRemained' => $remaining]]];
}

function reviewHtml(array $data, string $id = '112125712262', int $ratings = 95): string
{
    $item = ['type' => 'business', 'id' => $id, 'title' => 'Test',
        'ratingData' => ['ratingValue' => 4.7, 'ratingCount' => $ratings, 'reviewCount' => $data['params']['count']],
        'reviewResults' => $data];

    return '<script class="state-view" type="application/json">'.json_encode(['stack' => [['results' => ['items' => [$item]]]]]).'</script>';
}

function htmlReviewPage(int $page, array $ids, int $count = 2, int $pages = 2, int $remaining = 0): string
{
    return reviewHtml(reviewPage($page, $ids, $count, $pages, $remaining)['data']);
}
