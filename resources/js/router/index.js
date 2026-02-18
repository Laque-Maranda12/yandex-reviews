import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../store/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/LoginPage.vue'),
        meta: { guest: true },
    },
    {
        path: '/register',
        name: 'register',
        component: () => import('../pages/RegisterPage.vue'),
        meta: { guest: true },
    },
    {
        path: '/',
        component: () => import('../layouts/AppLayout.vue'),
        meta: { auth: true },
        children: [
            {
                path: '',
                redirect: '/reviews',
            },
            {
                path: 'reviews',
                name: 'reviews',
                component: () => import('../pages/ReviewsPage.vue'),
            },
            {
                path: 'settings',
                name: 'settings',
                component: () => import('../pages/SettingsPage.vue'),
            },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to, from, next) => {
    const auth = useAuthStore();

    if (to.meta.guest) {
        if (!auth.checked) {
            await auth.fetchUser();
        }
        if (auth.isAuthenticated) {
            return next({ name: 'reviews' });
        }
        return next();
    }

    if (!auth.checked) {
        await auth.fetchUser();
    }

    if (to.meta.auth && !auth.isAuthenticated) {
        return next({ name: 'login' });
    }

    next();
});

export default router;