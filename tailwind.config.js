import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Los valores de `tudi` salen literalmente de `resources/css/tudi-tokens.css`,
 * que es la fuente de verdad del rediseño: aquí solo se reflejan para poder
 * escribirlos como utilidades (`bg-tudi-bg`, `text-tudi-muted`, ...). Si un
 * token cambia, se cambia allí y se copia aquí — nunca al revés.
 *
 * Los radios se añaden con prefijo (`rounded-tudi-lg`) en vez de sobrescribir
 * la escala por defecto, para no mover de sitio los `rounded-*` que ya usan
 * otras vistas.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                tudi: {
                    // Fondos claros
                    bg: '#EFE9DE',
                    surface: '#E2DACB',
                    card: '#FFFDF8',
                    'card-inset': '#F3EEE4',
                    border: '#DED7C8',
                    divider: '#E4DCCC',
                    'input-border': '#CFC6B4',

                    // Tinta sobre claro
                    ink: '#171512',
                    'ink-2': '#3A362F',
                    'ink-3': '#4A463E',
                    muted: '#6B665C',

                    // Superficies oscuras (paneles de dato)
                    dark: '#171512',
                    'dark-2': '#221F1A',
                    'dark-3': '#2A2721',
                    'dark-4': '#3A362F',
                    'on-dark': '#EFE9DE',
                    'on-dark-2': '#A8A296',
                    'on-dark-3': '#8C8578',

                    // Lima = progreso, y solo progreso
                    lime: '#C8F03C',
                    'lime-600': '#AFD62F',
                    'lime-700': '#5C6B14',
                    'lime-800': '#4C5A12',
                    'lime-900': '#3E4A0E',

                    // Ámbar = aviso / ajuste sugerido
                    amber: '#E8A33D',
                    'amber-bg': '#FBF1DE',
                    'amber-ink': '#7A5A16',
                },
            },

            fontFamily: {
                sans: ['Instrument Sans', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },

            borderRadius: {
                'tudi-sm': '12px',
                'tudi-md': '16px',
                'tudi-lg': '20px',
                'tudi-xl': '24px',
                'tudi-pill': '999px',
            },

            letterSpacing: {
                'tudi-display': '-0.045em',
                'tudi-title': '-0.03em',
                'tudi-label': '0.14em',
            },

            boxShadow: {
                'tudi-sm': '0 1px 2px rgba(23,21,18,0.06)',
                'tudi-md': '0 6px 18px rgba(23,21,18,0.10)',
                'tudi-lg': '0 24px 60px rgba(23,21,18,0.18)',
            },
        },
    },

    plugins: [forms],
};
