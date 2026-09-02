import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
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
            // Semantic aliases over Tailwind's own palette — a single source of truth for
            // "the app's primary/success/danger/warning color" instead of every page picking
            // its own shade (blue-600 in one place, indigo-600 in another, for the same
            // meaning). Purely additive: existing bg-blue-600/bg-red-600/etc. classes already
            // in the codebase keep working unchanged — new/updated code should prefer these
            // semantic names instead so the whole app moves as one when a color needs to change.
            colors: {
                primary: colors.blue,
                // green, not emerald — confirmed against actual usage: green-* already
                // appears 60+ times across the app for money/positive indicators, emerald
                // essentially never does. Aliasing to emerald here would have introduced a
                // second, slightly different "success green" instead of unifying on the one
                // that's already everywhere.
                success: colors.green,
                danger: colors.red,
                warning: colors.amber,
            },
        },
    },

    plugins: [forms],
};
