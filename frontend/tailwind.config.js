/**
 * Student Voice wears St Mark's College Namagoma's colours, taken from the school crest:
 * - indigo: the crest's deep navy (#1c1464) - every "indigo-*" class is this navy scale
 * - crest: the crest's sky blue, for light accents
 * - gold: the motto ribbon and the sun
 * - red: the cross, used sparingly
 * Warm cream paper behind. Green only ever means done / voted.
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
        // St Mark's navy (crest #1c1464), replacing Tailwind's indigo so every page follows
        indigo: {
          50: '#eef0fb',
          100: '#dde1f7',
          200: '#bcc3ee',
          300: '#8f99df',
          400: '#6470cd',
          500: '#4148b4',
          600: '#2e3299',
          700: '#232483',
          800: '#1c1b6c',
          900: '#18165a',
          950: '#0f0d3c',
        },
        // agree/disagree scale 1-5 (CSS variables: light and dark steps, see style.css)
        lk: Object.fromEntries([1, 2, 3, 4, 5].map((n) => [n, v(`lk-${n}`)])),
        // the crest's sky blue
        crest: {
          50: '#eef8fd',
          100: '#d6eefa',
          200: '#b0def5',
          300: '#7cc6ec',
          400: '#4fb0e2',
          500: '#2a95cf',
          600: '#1c77ac',
        },
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
