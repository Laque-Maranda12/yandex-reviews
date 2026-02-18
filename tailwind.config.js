/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            colors: {
                primary: '#4A9FE8',
                'primary-dark': '#3B8AD4',
                accent: '#F5A623',
                'sidebar-bg': '#F8FAFC',
                'card-border': '#E8E8E8',
            },
        },
    },
    plugins: [],
};
