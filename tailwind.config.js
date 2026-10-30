import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                bsm: {
                    primary: {
                        DEFAULT: '#0f2744', // BSM Deep Navy
                        hover: '#0a1a2e',
                        light: '#1e3d64',
                        soft: '#f0f4f8',
                        subtle: '#e2e8f0',
                    },
                    accent: {
                        DEFAULT: '#ea580c', // BSM Vibrant Orange
                        hover: '#c2410c',
                        dark: '#9a3412',
                        soft: '#fff7ed',
                        subtle: '#ffedd5',
                    },
                },
            },
        },
    },

    plugins: [forms],
};
