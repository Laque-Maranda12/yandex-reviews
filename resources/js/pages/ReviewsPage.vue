<template>
  <div>
    <!-- Empty state -->
    <div v-if="!loading && !source" class="text-center py-16">
      <p class="text-gray-400 mb-4">Источник не подключён</p>
      <router-link
        to="/settings"
        class="inline-block px-6 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg transition-colors text-sm font-medium"
      >
        Подключить Яндекс
      </router-link>
    </div>

    <!-- Content -->
    <div v-else>
      <!-- Yandex badge -->
      <div class="flex items-center gap-2 mb-5">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="7" fill="#FF4433"/>
          <circle cx="8" cy="5" r="2" fill="white"/>
          <path d="M8 8C8 8 8 14 8 14" stroke="white" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span class="text-sm font-medium text-gray-700">Яндекс Карты</span>
      </div>

      <div class="flex gap-8">
        <!-- Reviews list -->
        <div class="flex-1">
          <!-- Sort controls -->
          <div class="flex items-center gap-3 mb-4">
            <span class="text-sm text-gray-500">Сортировка:</span>
            <div class="flex gap-1.5">
              <button
                v-for="option in sortOptions"
                :key="option.value"
                @click="changeSort(option.value)"
                class="px-3 py-1.5 text-sm rounded-lg border transition-colors"
                :class="currentSort === option.value
                  ? 'bg-primary text-white border-primary'
                  : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50 hover:border-gray-400'"
              >
                {{ option.label }}
              </button>
            </div>
          </div>

          <!-- Loading -->
          <div v-if="loading" class="space-y-4">
            <div v-for="i in 3" :key="i" class="bg-white rounded-xl border border-gray-200 p-6 animate-pulse">
              <div class="h-4 bg-gray-200 rounded w-1/3 mb-3"></div>
              <div class="h-3 bg-gray-200 rounded w-1/4 mb-4"></div>
              <div class="space-y-2">
                <div class="h-3 bg-gray-200 rounded w-full"></div>
                <div class="h-3 bg-gray-200 rounded w-5/6"></div>
              </div>
            </div>
          </div>

          <!-- Review cards -->
          <div v-else class="space-y-4">
            <div
              v-for="review in reviews"
              :key="review.id"
              class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-sm transition-shadow"
            >
              <!-- Header: date, branch, stars -->
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-3">
                  <span class="text-sm text-gray-500">{{ formatDate(review.published_at) }}</span>
                  <span v-if="review.branch_name" class="text-sm text-gray-500">{{ review.branch_name }}</span>
                  <svg v-if="review.branch_name" width="12" height="12" viewBox="0 0 16 16" fill="none">
                    <circle cx="8" cy="8" r="7" fill="#FF4433"/>
                    <circle cx="8" cy="5" r="2" fill="white"/>
                    <path d="M8 8C8 8 8 14 8 14" stroke="white" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                </div>
                <div v-if="review.rating != null" class="flex gap-0.5">
                  <svg
                    v-for="star in 5"
                    :key="star"
                    width="18"
                    height="18"
                    viewBox="0 0 20 20"
                    :fill="star <= review.rating ? '#F5A623' : '#E0E0E0'"
                  >
                    <path d="M10 1l2.39 4.84 5.34.78-3.87 3.77.91 5.32L10 13.27l-4.77 2.51.91-5.32L2.27 6.69l5.34-.78L10 1z"/>
                  </svg>
                </div>
                <span v-else class="text-sm text-gray-400 italic">Без оценки</span>
              </div>

              <!-- Author -->
              <div class="flex items-center gap-3 mb-3">
                <span class="font-medium text-gray-900">{{ review.author_name }}</span>
                <span v-if="review.author_phone" class="text-sm text-gray-400">{{ review.author_phone }}</span>
              </div>

              <!-- Text -->
              <p class="text-sm text-gray-700 leading-relaxed">{{ review.text }}</p>
            </div>

            <!-- No reviews -->
            <div v-if="reviews.length === 0 && !loading" class="text-center py-12">
              <p class="text-gray-400">Отзывов пока нет</p>
            </div>
          </div>

          <!-- Pagination -->
          <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
            <button
              @click="goToPage(meta.current_page - 1)"
              :disabled="meta.current_page === 1"
              class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition"
            >
              &larr;
            </button>

            <template v-for="page in visiblePages" :key="page">
              <span v-if="page === '...'" class="px-2 text-gray-400">...</span>
              <button
                v-else
                @click="goToPage(page)"
                class="px-3 py-1.5 text-sm border rounded-lg transition"
                :class="page === meta.current_page
                  ? 'bg-primary text-white border-primary'
                  : 'border-gray-300 hover:bg-gray-50'"
              >
                {{ page }}
              </button>
            </template>

            <button
              @click="goToPage(meta.current_page + 1)"
              :disabled="meta.current_page === meta.last_page"
              class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition"
            >
              &rarr;
            </button>
          </div>
        </div>

        <!-- Rating sidebar -->
        <div v-if="source" class="w-64 flex-shrink-0">
          <div class="bg-white rounded-xl border border-gray-200 p-6 sticky top-8">
            <!-- Rating number & stars -->
            <div v-if="displayRating" class="flex items-center gap-3 mb-4">
              <span class="text-5xl font-bold text-gray-900">{{ Number(displayRating).toFixed(1) }}</span>
              <div class="flex flex-col gap-1">
                <div class="flex gap-0.5">
                  <svg
                    v-for="star in 5"
                    :key="star"
                    width="22"
                    height="22"
                    viewBox="0 0 20 20"
                    :fill="star <= Math.round(displayRating) ? '#F5A623' : '#E0E0E0'"
                  >
                    <path d="M10 1l2.39 4.84 5.34.78-3.87 3.77.91 5.32L10 13.27l-4.77 2.51.91-5.32L2.27 6.69l5.34-.78L10 1z"/>
                  </svg>
                </div>
              </div>
              <!-- Refresh indicator -->
              <svg
                v-if="refreshingRating"
                width="14" height="14" viewBox="0 0 16 16" fill="none"
                class="animate-spin ml-1 text-gray-400"
              >
                <path d="M14 8A6 6 0 1 1 8 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
              </svg>
            </div>
            <div v-else class="mb-4">
              <span v-if="refreshingRating" class="text-sm text-gray-400">Загрузка рейтинга...</span>
              <span v-else class="text-2xl font-bold text-gray-400">Нет оценки</span>
            </div>

            <!-- Total reviews count -->
            <p class="text-sm text-gray-500">
              <template v-if="source.total_reviews && meta.total < source.total_reviews">
                Загружено отзывов: <span class="font-semibold text-gray-700">{{ formatNumber(meta.total) }}</span>
                <span class="text-gray-400"> из {{ formatNumber(source.total_reviews) }}</span>
              </template>
              <template v-else>
                Всего отзывов: <span class="font-semibold text-gray-700">{{ formatNumber(meta.total || source.total_reviews) }}</span>
              </template>
            </p>

            <!-- Last sync time -->
            <p v-if="source.last_synced_at" class="text-xs text-gray-400 mt-1">
              Обновлено: {{ formatRelativeTime(source.last_synced_at) }}
            </p>

            <!-- Sync button -->
            <button
              @click="syncReviews"
              :disabled="syncing"
              class="mt-4 w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium border border-gray-300 rounded-lg transition-colors"
              :class="syncing
                ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                : 'bg-white text-gray-700 hover:bg-gray-50 hover:border-gray-400'"
            >
              <svg
                width="16" height="16" viewBox="0 0 16 16" fill="none"
                :class="{ 'animate-spin': syncing }"
              >
                <path d="M14 8A6 6 0 1 1 8 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                <path d="M8 0L10 2L8 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              {{ syncing ? 'Обновление...' : 'Обновить отзывы' }}
            </button>
            <p v-if="syncing" class="mt-2 text-xs text-center text-gray-400">
              Загрузка может занять несколько минут
            </p>
            <p v-if="syncMessage" class="mt-2 text-xs text-center" :class="syncError ? 'text-red-500' : 'text-green-600'">
              {{ syncMessage }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import http from '../http';

const reviews = ref([]);
const source = ref(null);
const loading = ref(true);
const syncing = ref(false);
const refreshingRating = ref(false);
const syncMessage = ref('');
const syncError = ref(false);
const currentSort = ref('newest');
const meta = ref({
  current_page: 1,
  last_page: 1,
  per_page: 50,
  total: 0,
});

const sortOptions = [
  { value: 'newest', label: 'Сначала новые' },
  { value: 'oldest', label: 'Сначала старые' },
  { value: 'rating_high', label: 'Высокий рейтинг' },
  { value: 'rating_low', label: 'Низкий рейтинг' },
];

const sortParams = {
  newest: { sort: 'published_at', direction: 'desc' },
  oldest: { sort: 'published_at', direction: 'asc' },
  rating_high: { sort: 'rating', direction: 'desc' },
  rating_low: { sort: 'rating', direction: 'asc' },
};

const displayRating = computed(() => {
  return source.value?.rating || null;
});

onMounted(() => {
  fetchReviews(1);
  refreshRating();
});

async function fetchReviews(page = 1) {
  loading.value = true;
  try {
    const params = sortParams[currentSort.value] || sortParams.newest;
    const { data } = await http.get('/reviews', {
      params: {
        page,
        sort: params.sort,
        direction: params.direction,
        per_page: 50,
      },
      timeout: 30000,
    });
    reviews.value = data.reviews;
    source.value = data.source;
    meta.value = data.meta;
  } catch (e) {
    console.error('Failed to fetch reviews:', e);
  } finally {
    loading.value = false;
  }
}

async function refreshRating() {
  refreshingRating.value = true;
  try {
    const { data } = await http.post('/reviews/refresh-rating', {}, {
      timeout: 20000,
    });
    if (data.rating && source.value) {
      source.value = {
        ...source.value,
        rating: data.rating,
        total_reviews: data.total_reviews || source.value.total_reviews,
      };
    }
  } catch (e) {
    // Silent fail — we still have the stored rating
    console.warn('Rating refresh failed:', e);
  } finally {
    refreshingRating.value = false;
  }
}

function changeSort(sortValue) {
  if (currentSort.value === sortValue) return;
  currentSort.value = sortValue;
  fetchReviews(1);
}

async function syncReviews() {
  if (syncing.value) return;
  syncing.value = true;
  syncMessage.value = '';
  syncError.value = false;
  try {
    const { data } = await http.post('/reviews/sync', {}, {
      timeout: 300000,
    });
    syncMessage.value = data.message || 'Отзывы обновлены!';
    syncError.value = false;
    await fetchReviews(1);
  } catch (e) {
    if (e.code === 'ECONNABORTED') {
      syncMessage.value = 'Превышено время ожидания. Попробуйте позже.';
    } else if (e.response?.status === 409) {
      syncMessage.value = e.response.data.message || 'Синхронизация уже выполняется';
    } else {
      syncMessage.value = e.response?.data?.message || 'Ошибка при обновлении отзывов';
    }
    syncError.value = true;
  } finally {
    syncing.value = false;
    setTimeout(() => { syncMessage.value = ''; }, 10000);
  }
}

function goToPage(page) {
  if (page < 1 || page > meta.value.last_page) return;
  fetchReviews(page);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

const visiblePages = computed(() => {
  const current = meta.value.current_page;
  const last = meta.value.last_page;
  const pages = [];

  if (last <= 7) {
    for (let i = 1; i <= last; i++) pages.push(i);
    return pages;
  }

  pages.push(1);
  if (current > 3) pages.push('...');

  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);
  for (let i = start; i <= end; i++) pages.push(i);

  if (current < last - 2) pages.push('...');
  pages.push(last);

  return pages;
});

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }) + ' ' + d.toLocaleTimeString('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatRelativeTime(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  const now = new Date();
  const diffMs = now - d;
  const diffMin = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMin < 1) return 'только что';
  if (diffMin < 60) return `${diffMin} мин. назад`;
  if (diffHours < 24) return `${diffHours} ч. назад`;
  if (diffDays < 7) return `${diffDays} дн. назад`;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatNumber(num) {
  if (!num) return '0';
  return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}
</script>
