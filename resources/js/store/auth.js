import { defineStore } from 'pinia';
import http from '../http.js';

// Shared promise to deduplicate concurrent fetchUser calls
let fetchUserPromise = null;

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        checked: false,
    }),

    getters: {
        isAuthenticated: (state) => !!state.user,
        userName: (state) => state.user?.name || '',
    },

    actions: {
        async login({ email, password }) {
            const { data } = await http.post('/login', { email, password });
            localStorage.setItem('auth_token', data.token);
            this.user = data.user;
            this.checked = true;
        },

        async register({ name, email, password, password_confirmation }) {
            const { data } = await http.post('/register', { name, email, password, password_confirmation });
            localStorage.setItem('auth_token', data.token);
            this.user = data.user;
            this.checked = true;
        },

        async logout() {
            try {
                await http.post('/logout');
            } catch {
                // Ignore errors — token may already be invalid
            }
            localStorage.removeItem('auth_token');
            this.user = null;
        },

        async fetchUser() {
            // Deduplicate: if a fetch is already in progress, reuse the same promise
            if (fetchUserPromise) {
                return fetchUserPromise;
            }

            fetchUserPromise = this._doFetchUser();
            try {
                return await fetchUserPromise;
            } finally {
                fetchUserPromise = null;
            }
        },

        async _doFetchUser() {
            try {
                const { data } = await http.get('/user', { timeout: 10000 });
                this.user = data;
            } catch {
                this.user = null;
                localStorage.removeItem('auth_token');
            }
            this.checked = true;
        },
    },
});