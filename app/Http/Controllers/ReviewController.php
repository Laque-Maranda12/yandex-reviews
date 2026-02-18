<?php

namespace App\Http\Controllers;

use App\Services\YandexReviewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        private YandexReviewsService $yandexService
    ) {}
    /**
     * Get paginated reviews with sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $source = $request->user()->yandexSources()->latest()->first();

        if (!$source) {
            return response()->json([
                'reviews' => [],
                'source' => null,
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 50,
                    'total' => 0,
                ],
            ]);
        }

        $sortField = $request->input('sort', 'published_at');
        $sortDirection = $request->input('direction', 'desc');
        $perPage = max(1, min(intval($request->input('per_page', 50)), 50));

        // Validate sort params
        $allowedSorts = ['published_at', 'rating', 'created_at'];
        if (!in_array($sortField, $allowedSorts)) {
            $sortField = 'published_at';
        }
        if (!in_array($sortDirection, ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        $reviews = $source->reviews()
            ->orderBy($sortField, $sortDirection)
            ->paginate($perPage);

        $rating = $source->rating;
        $dbCount = $source->reviews()->count();
        $totalReviews = max($source->total_reviews, $dbCount);

        // Fallback: compute average rating from reviews if source rating is missing
        if (!$rating && $totalReviews > 0) {
            $rating = round($source->reviews()->whereNotNull('rating')->avg('rating'), 2);
        }

        return response()->json([
            'reviews' => $reviews->items(),
            'source' => [
                'organization_name' => $source->organization_name,
                'rating' => $rating,
                'total_reviews' => $totalReviews,
                'url' => $source->url,
            ],
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    /**
     * Re-sync reviews from Yandex (full sync).
     */
    public function sync(Request $request): JsonResponse
    {
        $source = $request->user()->yandexSources()->latest()->first();

        if (!$source) {
            return response()->json([
                'message' => 'Источник не подключён. Сначала добавьте ссылку на Яндекс Карты.',
            ], 422);
        }

        // Prevent concurrent syncs for the same source
        $lockKey = 'sync_source_' . $source->id;
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 300); // 5 min max

        if (!$lock->get()) {
            return response()->json([
                'message' => 'Синхронизация уже выполняется. Подождите завершения.',
            ], 409);
        }

        try {
            // Allow sync to run up to 5 minutes
            set_time_limit(300);
            ignore_user_abort(true);

            $updated = $this->yandexService->syncReviews($source);

            return response()->json([
                'message' => 'Отзывы успешно обновлены!',
                'source' => [
                    'organization_name' => $updated->organization_name,
                    'rating' => $updated->rating,
                    'total_reviews' => $updated->total_reviews,
                    'url' => $updated->url,
                ],
                'reviews_count' => $updated->reviews()->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Не удалось обновить отзывы: ' . $e->getMessage(),
            ], 500);
        } finally {
            $lock->release();
        }
    }
}
