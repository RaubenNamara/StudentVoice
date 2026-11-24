/**
 * One PDF per teacher, all in a ZIP: each teacher's own Student Voice report, to be sent to them
 * privately (e.g. on WhatsApp). A teacher who teaches two subjects gets one file with both.
 * Every written comment is in it (repeats merged and counted), not just the ones picked for the
 * meeting. jsPDF and JSZip are loaded only when the download is asked for.
 */
import { flagOf } from '@/commentFlags'

export interface PdfRow {
  teacher_id: number
  teacher: string
  subject: string // display name
  ratings: number
  counts: Record<string, number[]>
  comments: Record<string, string[]>
}
export interface PdfInput {
  school: string
  poll: string
  questions: { id: number; prompt: string }[]
  commentQuestions: { id: number; prompt: string }[]
  schoolCounts: number[]
  minGroup: number
  rows: PdfRow[]
  /** leave harassment / academic-concern comments out of the teachers' copies */
  withoutSensitive: boolean
  onProgress?: (done: number, total: number) => void
}

const total = (c: number[]) => c.reduce((s, n) => s + n, 0)
const average = (c: number[]) => (total(c) ? c.reduce((s, n, i) => s + n * (i + 1), 0) / total(c) : null)
const agree = (c: number[]) => (total(c) ? Math.round(((c[3] + c[4]) / total(c)) * 100) : 0)
const pct = (v: number | null) => (v == null ? '-' : `${Math.round((v / 5) * 100)}%`)
const sum = (rows: number[][]) => rows.reduce((acc, c) => acc.map((n, i) => n + (c[i] ?? 0)), [0, 0, 0, 0, 0])

/** The built-in PDF fonts only know Western European letters: keep the words, drop emoji etc. */
const safe = (t: string) =>
  t
    .replace(/[‘’‚′]/g, "'")
    .replace(/[“”„″]/g, '"')
    .replace(/[–—―]/g, '-')
    .replace(/…/g, '...')
    .replace(/[^\x20-\x7E\xA0-\xFF\n]/g, '')
    .replace(/[ \t]+/g, ' ')
    .trim()

/** Every comment, identical ones merged with a count; most serious first, then longest */
function allComments(list: string[], withoutSensitive: boolean) {
  const merged = new Map<string, { text: string; n: number; rank: number }>()
  for (const raw of list) {
    const text = safe(raw).replace(/\s+/g, ' ')
    if (!text) continue
    const flag = flagOf(raw)
    if (withoutSensitive && flag) continue
    const k = text.toLowerCase().replace(/[.!\s]+$/g, '')
    const e = merged.get(k)
    if (e) e.n++
    else merged.set(k, { text: text.replace(/^./, (c) => c.toUpperCase()), n: 1, rank: flag?.rank ?? 9 })
  }
  return [...merged.values()].sort((a, b) => a.rank - b.rank || b.text.length - a.text.length)
}

function fileName(name: string, used: Set<string>) {
  const base = safe(name).replace(/[\\/:*?"<>|]/g, '').replace(/\s+/g, ' ').trim() || 'Teacher'
  let out = base
  for (let i = 2; used.has(out.toLowerCase()); i++) out = `${base} (${i})`
  used.add(out.toLowerCase())
  return out + '.pdf'
}

const INK: [number, number, number] = [24, 24, 38]
const NAVY: [number, number, number] = [49, 46, 129]
const GREY: [number, number, number] = [100, 100, 110]
const GOLD: [number, number, number] = [161, 98, 7]

type JsPdf = typeof import('jspdf').jsPDF

/** All of one teacher's rows (their subjects) in one PDF; returns its bytes and comment count */
function buildPdf(jsPDF: JsPdf, input: PdfInput, rows: PdfRow[]): { bytes: ArrayBuffer; comments: number } {
  const today = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
  const schoolAvg = average(input.schoolCounts)
  let commentCount = 0
  {
    const doc = new jsPDF({ unit: 'mm', format: 'a4' })
    const W = 210
    const M = 16
    const BOTTOM = 280
    let y = 0

    const header = () => {
      doc.setFillColor(...NAVY)
      doc.rect(0, 0, W, 4, 'F')
      doc.setFont('helvetica', 'bold').setFontSize(11).setTextColor(...NAVY)
      doc.text(safe(input.school).toUpperCase(), M, 13)
      doc.setFont('helvetica', 'normal').setFontSize(8.5).setTextColor(...GREY)
      doc.text(`Student Voice - ${safe(input.poll)} - confidential, for ${safe(rows[0].teacher)}`, M, 18)
      doc.setDrawColor(...NAVY).setLineWidth(0.4).line(M, 21, W - M, 21)
      y = 29
    }
    const need = (h: number) => {
      if (y + h > BOTTOM) {
        doc.addPage()
        header()
      }
    }
    /** Wrapped text; returns nothing, moves y */
    const para = (text: string, opts: { size?: number; bold?: boolean; italic?: boolean; color?: [number, number, number]; x?: number; gap?: number } = {}) => {
      const size = opts.size ?? 10
      const x = opts.x ?? M
      doc.setFont('helvetica', opts.bold ? 'bold' : opts.italic ? 'italic' : 'normal').setFontSize(size).setTextColor(...(opts.color ?? INK))
      const lh = size * 0.42
      for (const line of doc.splitTextToSize(text, W - M - x) as string[]) {
        need(lh)
        doc.text(line, x, y)
        y += lh
      }
      y += opts.gap ?? 1.5
    }

    header()
    para(safe(rows[0].teacher), { size: 18, bold: true, gap: 1 })
    para(`${rows.map((r) => safe(r.subject)).join(', ')}  -  ${today}`, { size: 10, color: GREY, gap: 4 })

    for (const r of rows) {
      const all = sum(Object.values(r.counts))
      const avg = average(all)
      need(30)
      doc.setFillColor(238, 240, 250)
      doc.roundedRect(M, y - 5, W - 2 * M, 17, 2, 2, 'F')
      doc.setFont('helvetica', 'bold').setFontSize(13).setTextColor(...NAVY)
      doc.text(safe(r.subject), M + 4, y + 1)
      doc.setFont('helvetica', 'normal').setFontSize(9).setTextColor(...GREY)
      doc.text(`${r.ratings} students rated you  -  school average ${pct(schoolAvg)}`, M + 4, y + 7)
      doc.setFont('helvetica', 'bold').setFontSize(16).setTextColor(...NAVY)
      doc.text(pct(avg), W - M - 4, y + 2, { align: 'right' })
      doc.setFont('helvetica', 'normal').setFontSize(8.5).setTextColor(...GREY)
      doc.text(`score (${avg == null ? '-' : avg.toFixed(2)}/5)  -  ${agree(all)}% agree`, W - M - 4, y + 7, { align: 'right' })
      y += 19

      // every statement
      const stated = input.questions.map((q) => ({ q, c: r.counts[q.id] ?? [0, 0, 0, 0, 0] })).filter((x) => total(x.c) >= input.minGroup)
      if (stated.length) {
        need(12)
        para('WHAT STUDENTS SAID ABOUT YOUR TEACHING', { size: 8.5, bold: true, color: GOLD, gap: 1 })
        doc.setFont('helvetica', 'bold').setFontSize(8).setTextColor(...GREY)
        doc.text('Agree', W - M - 22, y, { align: 'right' })
        doc.text('Score', W - M, y, { align: 'right' })
        y += 4
        for (const x of stated) {
          doc.setFont('helvetica', 'normal').setFontSize(9.5)
          const lines = doc.splitTextToSize(safe(x.q.prompt), W - 2 * M - 34) as string[]
          need(lines.length * 4 + 2)
          doc.setTextColor(...INK)
          doc.text(lines, M, y)
          doc.text(`${agree(x.c)}%`, W - M - 22, y, { align: 'right' })
          doc.text(pct(average(x.c)), W - M, y, { align: 'right' })
          y += lines.length * 4
          doc.setDrawColor(225, 225, 232).setLineWidth(0.2).line(M, y - 2, W - M, y - 2)
          y += 1.5
        }
        y += 3
      }

      // every written comment
      for (const q of input.commentQuestions) {
        const list = allComments(r.comments[q.id] ?? [], input.withoutSensitive)
        commentCount += list.reduce((s, c) => s + c.n, 0)
        need(14)
        para(safe(q.prompt).toUpperCase(), { size: 8.5, bold: true, color: GOLD, gap: 1.5 })
        if (!list.length) {
          para('No written comments.', { size: 9.5, color: GREY, gap: 3 })
          continue
        }
        list.forEach((c, i) => {
          doc.setFont('helvetica', 'bold').setFontSize(9.5).setTextColor(...GREY)
          need(4.2)
          doc.text(`${i + 1}.`, M + 7, y, { align: 'right' })
          para(`"${c.text}"${c.n > 1 ? `  (${c.n} students)` : ''}`, { size: 9.5, italic: true, x: M + 9, gap: 1.2 })
        })
        y += 3
      }
      y += 4
    }

    // footer on every page
    const pages = doc.getNumberOfPages()
    for (let p = 1; p <= pages; p++) {
      doc.setPage(p)
      doc.setFont('helvetica', 'normal').setFontSize(7.5).setTextColor(...GREY)
      doc.text("Anonymous: students' names are never kept with their answers. Score = average answer out of 5, as a %.", M, 290)
      doc.text(`Page ${p} of ${pages}`, W - M, 290, { align: 'right' })
    }

    return { bytes: doc.output('arraybuffer'), comments: commentCount }
  }
}

/** One teacher's report as a single PDF (all their subjects) */
export async function teacherReportPdf(input: PdfInput): Promise<{ blob: Blob; name: string; comments: number }> {
  const { jsPDF } = await import('jspdf')
  const { bytes, comments } = buildPdf(jsPDF, input, input.rows)
  return { blob: new Blob([bytes], { type: 'application/pdf' }), name: fileName(input.rows[0]?.teacher ?? 'Teacher', new Set()), comments }
}

export async function teacherReportsZip(input: PdfInput): Promise<{ blob: Blob; files: number; comments: number }> {
  const [{ jsPDF }, { default: JSZip }] = await Promise.all([import('jspdf'), import('jszip')])
  const zip = new JSZip()
  const used = new Set<string>()

  // one file per teacher, their subjects together
  const byTeacher = new Map<number, PdfRow[]>()
  for (const r of input.rows) byTeacher.set(r.teacher_id, [...(byTeacher.get(r.teacher_id) ?? []), r])
  const teachers = [...byTeacher.values()].sort((a, b) => a[0].teacher.localeCompare(b[0].teacher))

  let done = 0
  let commentCount = 0
  for (const rows of teachers) {
    const { bytes, comments } = buildPdf(jsPDF, input, rows)
    commentCount += comments
    zip.file(fileName(rows[0].teacher, used), bytes)
    input.onProgress?.(++done, teachers.length)
    await new Promise((res) => setTimeout(res, 0)) // let the page show progress
  }

  const blob = await zip.generateAsync({ type: 'blob', compression: 'DEFLATE' })
  return { blob, files: teachers.length, comments: commentCount }
}
