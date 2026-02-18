<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <div class="flex items-center justify-center gap-2 mb-2">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
            <path d="M8 5l8 7-8 7" stroke="#4A9FE8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span class="text-2xl font-bold text-gray-900">Daily Grow</span>
        </div>
        <p class="text-gray-500">Создайте аккаунт</p>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
        <div v-if="error" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
          {{ error }}
        </div>

        <div class="space-y-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Имя</label>
            <input
              v-model="form.name"
              type="text"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition"
              placeholder="Ваше имя"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
            <input
              v-model="form.email"
              type="email"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition"
              placeholder="email@example.com"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Пароль</label>
            <input
              v-model="form.password"
              type="password"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition"
              placeholder="Минимум 6 символов"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Подтвердите пароль</label>
            <input
              v-model="form.password_confirmation"
              type="password"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none transition"
              placeholder="Повторите пароль"
              @keyup.enter="submit"
            />
          </div>

          <button
            @click="submit"
            :disabled="loading"
            class="w-full py-2.5 bg-primary hover:bg-primary-dark text-white font-medium rounded-lg transition-colors disabled:opacity-50"
          >
            {{ loading ? 'Регистрация...' : 'Зарегистрироваться' }}
          </button>
        </div>

        <p class="mt-5 text-center text-sm text-gray-500">
          Уже есть аккаунт?
          <router-link to="/login" class="text-primary hover:underline">Войти</router-link>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../store/auth';

const auth = useAuthStore();
const router = useRouter();

const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });
const loading = ref(false);
const error = ref('');

async function submit() {
  loading.value = true;
  error.value = '';

  try {
    await auth.register(form);
    router.push({ name: 'settings' });
  } catch (e) {
    const errors = e.response?.data?.errors;
    error.value = errors ? Object.values(errors).flat()[0] : 'Ошибка регистрации';
  } finally {
    loading.value = false;
  }
}
</script>
