<?php

namespace App\Http\Controllers;

use App\Services\YandexReviewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function __construct(
        private YandexReviewsService $yandexService
    ) {}

    /**
     * Get current Yandex source for the user.
     */
    public function show(Request $request): JsonResponse
    {
        $source = $request->user()->yandexSources()->latest()->first();

        return response()->json([
            'source' => $source,
        ]);
    }

    /**
     * Save Yandex Maps URL and fetch reviews.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => [
                'required',
                'url',
                'regex:/yandex\.(ru|com)\/maps/i',
            ],
        ], [
            'url.required' => 'Укажите ссылку на Яндекс Карты.',
            'url.url' => 'Некорректная ссылка.',
            'url.regex' => 'Ссылка должна быть на Яндекс Карты.',
        ]);

        $orgId = $this->yandexService->parseOrganizationId($validated['url']);

        if (!$orgId) {
            return response()->json([
                'message' => 'Не удалось определить организацию из ссылки. Убедитесь, что ссылка содержит ID организации.',
            ], 422);
        }

        $existingSource = $request->user()->yandexSources()->first();

        if ($existingSource) {
            if ($existingSource->url === $validated['url']) {
                // Same URL — keep source, reviews will be refreshed by syncReviews
                $source = $existingSource;
            } else {
                // Different URL — delete old source (cascade deletes its reviews), create new
                $existingSource->delete();
                $source = $request->user()->yandexSources()->create([
                    'url' => $validated['url'],
                ]);
            }
        } else {
            // No source yet — create new
            $source = $request->user()->yandexSources()->create([
                'url' => $validated['url'],
            ]);
        }

        // Prevent concurrent syncs for the same source
        $lockKey = 'sync_source_' . $source->id;
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 300);

        if (!$lock->get()) {
            return response()->json([
                'source' => $source,
                'message' => 'Синхронизация уже выполняется. Подождите завершения.',
            ], 409);
        }

        // Sync reviews (deletes old reviews, fetches and inserts fresh ones)
        try {
            // Allow sync to run up to 5 minutes
            set_time_limit(300);
            ignore_user_abort(true);

            $this->yandexService->syncReviews($source);

            return response()->json([
                'source' => $source->fresh(),
                'message' => 'Отзывы успешно загружены!',
            ]);
        } catch (\Exception $e) {
            Log::error('Settings sync failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'source' => $source,
                'message' => 'Ссылка сохранена, но не удалось загрузить отзывы: ' . $e->getMessage(),
            ], 422);
        } finally {
            $lock->release();
        }
    }
}
