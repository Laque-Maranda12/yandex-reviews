<template>
  <div>
    <h1 class="text-xl font-semibold text-gray-900 mb-4">Подключить Яндекс</h1>

    <!-- Success message -->
    <div
      v-if="success"
      class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm"
    >
      {{ success }}
    </div>

    <!-- Error message -->
    <div
      v-if="error"
      class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm"
    >
      {{ error }}
    </div>

    <!-- URL input -->
    <p class="text-sm text-gray-500 mb-3">
      Укажите ссылку на Яндекс Карты (yandex.ru или yandex.com), пример
      <span class="text-primary">https://yandex.ru/maps/org/samoye_populyarnoye_kafe/1010501395/reviews/</span>
    </p>

    <input
      v-model="url"
      type="url"
      class="w-full max-w-2xl px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition text-sm text-gray-600 mb-4"
      placeholder="https://yandex.ru/maps/org/samoye_populyarnoye_kafe/1010501395/reviews/"
    />

    <div>
      <button
        @click="save"
        :disabled="loading"
        class="px-8 py-2.5 bg-primary hover:bg-primary-dark text-white font-medium rounded-lg transition-colors disabled:opacity-50 text-sm"
      >
        {{ loading ? 'Сохранение...' : 'Сохранить' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import http from '../http';

const router = useRouter();

const url = ref('');
const loading = ref(false);
const error = ref('');
const success = ref('');

onMounted(async () => {
  try {
    const { data } = await http.get('/settings');
    if (data.source) {
      url.value = data.source.url;
    }
  } catch {
    // No source yet
  }
});

async function save() {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  success.value = '';

  try {
    const { data } = await http.post('/settings', { url: url.value }, {
      timeout: 180000,
    });
    success.value = data.message || 'Сохранено!';
    setTimeout(() => router.push({ name: 'reviews' }), 1200);
  } catch (e) {
    if (e.code === 'ECONNABORTED') {
      error.value = 'Превышено время ожидания загрузки отзывов. Попробуйте обновить позже.';
    } else if (e.response?.status === 409) {
      error.value = e.response.data.message || 'Синхронизация уже выполняется';
    } else {
      const errors = e.response?.data?.errors;
      error.value = errors
        ? Object.values(errors).flat()[0]
        : e.response?.data?.message || 'Ошибка сохранения';
    }
  } finally {
    loading.value = false;
  }
}
</script>
