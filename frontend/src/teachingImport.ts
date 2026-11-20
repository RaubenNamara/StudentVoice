/**
 * Reads a teachers-and-streams spreadsheet in the browser and turns it into rows for the server:
 * { name, department, subjects, streams: ['S1A', 'S2B', ...] }.
 *
 * Understands three layouts, whichever sheet has one (the first that fits):
 * 1. a directory: "Teacher Name", "Department", "Subjects Taught", "All Streams Taught" (or
 *    "S1 Streams" ... "S6 Streams") - like St Mark's timetable export
 * 2. a matrix: a teacher per row and a column per stream (S1A, S1B ...) ticked where they teach
 * 3. a simple list: "Teacher", "Subject", and "Streams" (S1A, S2B) or "Class" + "Stream" columns,
 *    one row per teacher or per teacher-and-stream
 */
export interface ImportRow {
  name: string
  department: string
  subjects: string
  streams: string[]
}

const STREAM_CODE = /^S\.?\s*[1-6]\s*[-\s]?[A-Z0-9]{1,4}$/i

export async function readTeachingFile(file: File): Promise<{ rows: ImportRow[]; sheet: string }> {
  const XLSX = await import('xlsx')
  const wb = XLSX.read(await file.arrayBuffer(), { type: 'array' })
  for (const sheetName of wb.SheetNames) {
    const grid = XLSX.utils.sheet_to_json<unknown[]>(wb.Sheets[sheetName], { header: 1, defval: '', raw: false })
    const rows = fromGrid(grid.map((r) => r.map((c) => String(c ?? '').trim())))
    if (rows.length) return { rows, sheet: sheetName }
  }
  throw new Error('No sheet with teacher names and their streams was found. Use columns like "Teacher Name", "Subjects" and "Streams" (S1A, S2B...).')
}

function fromGrid(grid: string[][]): ImportRow[] {
  for (let h = 0; h < Math.min(grid.length, 20); h++) {
    const head = grid[h].map((c) => c.toLowerCase())
    const name = head.findIndex((c) => /^(teacher'?s?\s*name|teacher|name|full name|names?)$/.test(c))
    if (name < 0) continue
    const dept = head.findIndex((c) => /department|dept/.test(c))
    const subjects = head.findIndex((c) => /subject/.test(c))
    const all = head.findIndex((c) => /all streams|streams? taught|^streams?$|^classes?\s*(and|&)?\s*streams?$/.test(c))
    const perLevel = head.map((c, i) => (/^s\.?\s*[1-6]\s*streams?$/.test(c) ? i : -1)).filter((i) => i >= 0)
    const klass = head.findIndex((c) => /^class(es)?$/.test(c))
    const stream = head.findIndex((c) => /^stream$/.test(c))
    const matrix = grid[h].map((c, i) => (STREAM_CODE.test(c.replace(/\s/g, '')) ? i : -1)).filter((i) => i >= 0)

    const out = new Map<string, ImportRow>()
    for (const r of grid.slice(h + 1)) {
      const teacher = (r[name] ?? '').replace(/\s+/g, ' ').trim()
      if (!teacher || /^\d+$/.test(teacher)) continue
      let streams: string[] = []
      if (all >= 0 && r[all]) streams = split(r[all])
      else if (perLevel.length) streams = perLevel.flatMap((i) => split(r[i] ?? ''))
      else if (klass >= 0 && stream >= 0 && r[klass]) streams = split(r[stream]).map((s) => `${r[klass].replace(/[^0-9]/g, '') ? 'S' + r[klass].replace(/[^0-9]/g, '') : r[klass]}${s}`)
      else if (matrix.length) streams = matrix.filter((i) => (r[i] ?? '').trim() !== '').map((i) => grid[h][i].replace(/\s/g, ''))
      if (!streams.length) continue
      // one row per teacher-and-stream: gather them into one teacher (and their subjects)
      const key = teacher.toUpperCase()
      const row = out.get(key) ?? { name: teacher, department: dept >= 0 ? r[dept] : '', subjects: '', streams: [] }
      const subj = subjects >= 0 ? r[subjects] : ''
      if (subj && !row.subjects.split(/,\s*/).includes(subj)) row.subjects = row.subjects ? `${row.subjects}, ${subj}` : subj
      row.streams = [...new Set([...row.streams, ...streams])]
      out.set(key, row)
    }
    if (out.size) return [...out.values()]
  }
  return []
}

function split(cell: string): string[] {
  return cell
    .split(/[,;\n|]+/)
    .map((s) => s.trim().replace(/\s+/g, ''))
    .filter((s) => s !== '' && s !== '-')
}
