// Applies the saved theme (or the device's) before the app draws. Kept in a file, not inline,
// so the page's Content-Security-Policy can forbid inline scripts.
;(function () {
  var saved = null
  try {
    saved = localStorage.getItem('sv-theme')
  } catch (e) {}
  var dark = saved ? saved === 'dark' : window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
  if (dark) document.documentElement.classList.add('dark')
})()
