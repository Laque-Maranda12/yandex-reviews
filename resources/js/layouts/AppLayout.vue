<template>
  <div class="flex min-h-screen bg-gray-50">
    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col">
      <!-- Logo -->
      <div class="px-5 pt-5 pb-2">
        <div class="flex items-center gap-2">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <path d="M8 5l8 7-8 7" stroke="#4A9FE8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span class="text-xl font-bold text-gray-900">Daily Grow</span>
        </div>
        <p class="text-sm text-gray-400 mt-1">{{ auth.userName }}</p>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 px-3 mt-4">
        <!-- Reviews section -->
        <div class="mb-1">
          <div class="flex items-center gap-3 px-3 py-2.5 text-gray-700">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" class="text-gray-400">
              <path d="M17.5 9.5C17.5 13.09 14.14 16 10 16C9.18 16 8.39 15.89 7.65 15.68L4.17 17.5V14.26C2.83 13.03 2 11.35 2 9.5C2 5.91 5.36 3 10 3C14.14 3 17.5 5.91 17.5 9.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="font-medium">Отзывы</span>
          </div>

          <router-link
            to="/reviews"
            class="block pl-12 pr-3 py-2 text-sm rounded-lg transition-colors"
            :class="$route.name === 'reviews' ? 'text-primary font-medium bg-blue-50' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'"
          >
            Отзывы
          </router-link>

          <router-link
            to="/settings"
            class="block pl-12 pr-3 py-2 text-sm rounded-lg transition-colors"
            :class="$route.name === 'settings' ? 'text-primary font-medium bg-blue-50' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'"
          >
            Настройка
          </router-link>
        </div>
      </nav>
    </aside>

    <!-- Main content -->
    <div class="flex-1 flex flex-col">
      <!-- Top bar -->
      <header class="h-14 bg-white border-b border-gray-200 flex items-center justify-end px-6">
        <button
          @click="handleLogout"
          class="text-gray-400 hover:text-gray-600 transition-colors"
          title="Выйти"
        >
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke-linecap="round" stroke-linejoin="round"/>
            <polyline points="16,17 21,12 16,7" stroke-linecap="round" stroke-linejoin="round"/>
            <line x1="21" y1="12" x2="9" y2="12" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </header>

      <!-- Page content -->
      <main class="flex-1 p-8">
        <router-view />
      </main>
    </div>
  </div>
</template>

<script setup>
import { useAuthStore } from '../store/auth';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>
