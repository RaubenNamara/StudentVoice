import { reactive } from 'vue'

/** Small messages at the bottom of the screen, and a yes/no question before risky actions. */

export const toasts = reactive<{ id: number; text: string; kind: 'ok' | 'error' }[]>([])
let next = 1

export function toast(text: string, kind: 'ok' | 'error' = 'ok') {
  const id = next++
  toasts.push({ id, text, kind })
  setTimeout(() => {
    const i = toasts.findIndex((t) => t.id === id)
    if (i >= 0) toasts.splice(i, 1)
  }, kind === 'error' ? 6000 : 3500)
}

export const confirmState = reactive({
  open: false,
  title: '',
  body: '',
  action: 'Yes',
  danger: false,
  resolve: (_ok: boolean) => {},
})

export function confirm(title: string, body = '', action = 'Yes', danger = false): Promise<boolean> {
  return new Promise((resolve) => {
    Object.assign(confirmState, { open: true, title, body, action, danger, resolve })
  })
}

export function formatDate(value: string | null, withTime = true): string {
  if (!value) return ''
  const d = new Date(value.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString('en-GB', withTime ? { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'short', year: 'numeric' })
}

export function percent(part: number, whole: number): number {
  return whole > 0 ? Math.round((part / whole) * 100) : 0
}
