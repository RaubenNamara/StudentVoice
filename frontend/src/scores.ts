/**
 * Colours for 1-5 scores: red (low) -> amber -> blue (high). Green is kept for "done", so a high
 * score is the brand blue, not green.
 */
export function scoreCell(v: number | null | undefined): string {
  if (v == null) return 'text-gray-400'
  if (v < 2.5) return 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300'
  if (v < 3) return 'bg-orange-100 text-orange-800 dark:bg-orange-950/50 dark:text-orange-300'
  if (v < 3.5) return 'bg-gold-50 text-gold-700 dark:bg-gold-700/20 dark:text-gold-300'
  if (v < 4) return 'bg-indigo-50 text-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200'
  return 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/60 dark:text-indigo-100'
}

export function scoreText(v: number | null | undefined): string {
  if (v == null) return 'text-gray-400'
  if (v < 3) return 'text-red-700 dark:text-red-400'
  if (v < 3.5) return 'text-gold-700'
  return 'text-indigo-800 dark:text-indigo-300'
}

export const fmt = (v: number | null | undefined) => (v == null ? '–' : v.toFixed(2))

export function downloadCsv(name: string, rows: (string | number | null)[][]) {
  const csv = rows.map((row) => row.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
  a.download = name.replace(/[\\/:*?"<>|]/g, '') + '.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}
