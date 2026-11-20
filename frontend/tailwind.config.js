/**
 * Student Voice: the Tusome family look - royal blue (Tailwind indigo is the brand blue), gold for
 * highlights, warm cream paper. Green only ever means done / voted.
 *
 * Light and dark: the greys, paper, surface, ink and line are CSS variables (src/style.css) that
 * change under `.dark`, so pages follow the theme without dark: variants everywhere.
 */
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
      fontWeight: {
        // Inter only, weights capped at 600
        bold: '600',
        extrabold: '600',
        black: '600',
      },
      colors: {
        gray: Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((n) => [n, v(`g-${n}`)])),
        paper: v('paper'),
        surface: v('surface'),
        ink: v('ink'),
        line: v('line'),
        gold: {
          50: '#fdf8e7',
          100: '#faedc0',
          300: '#f0cc5c',
          400: '#e8b931',
          500: '#d4a017',
          600: '#a87d0e',
          700: '#7a5a0a',
        },
      },
      boxShadow: {
        card: '0 1px 2px rgba(21, 24, 33, 0.05), 0 10px 28px -18px rgba(30, 27, 75, 0.25)',
        lift: '0 2px 4px rgba(21, 24, 33, 0.04), 0 18px 40px -16px rgba(30, 27, 75, 0.3)',
      },
    },
  },
  plugins: [],
}
