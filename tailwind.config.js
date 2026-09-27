import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', ...defaultTheme.fontFamily.sans],
                heading: ['Montserrat', 'Verdana', 'system-ui', 'sans-serif'],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
            },
            colors: {
                primary: {
                    DEFAULT: '#00a3cc',
                    hover: '#008fb3',
                    dark: '#007a99',
                    light: '#e0f3f8',
                    50: '#f0f9fc',
                    100: '#e0f3f8',
                    200: '#b8e4f1',
                    300: '#7ecee6',
                    400: '#3cb4d7',
                    500: '#00a3cc',
                    600: '#008fb3',
                    700: '#007a99',
                    800: '#055d74',
                    900: '#094d60',
                },
            },
        },
    },

    plugins: [forms],
};
