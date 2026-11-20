export type QuestionType = 'scale' | 'choice' | 'multi' | 'yesno' | 'text'
export type PollStatus = 'draft' | 'open' | 'closed'

export interface Admin {
  id: number
  name: string
  email: string
  role: 'super_admin' | 'admin'
  must_change_password: boolean
}

export interface Student {
  id: number
  reg_no: string
  name: string
  class: string
  stream: string
}

export interface Question {
  id?: number
  position?: number
  section: string | null
  prompt: string
  type: QuestionType
  options: string[]
  required: boolean
}

export interface Poll {
  id: number
  title: string
  intro: string | null
  about: 'school' | 'teachers'
  sample_size: number | null
  status: PollStatus
  audience: string[] | null
  closes_at: string | null
  opened_at: string | null
  closed_at: string | null
  updated_at: string
  accepting: boolean
  votes: number
  eligible: number
}

export interface PollListItem extends Poll {
  questions: number
}

export interface PollDetail extends Poll {
  questions: Question[]
}

export interface ClassGroup {
  class: string
  students: number
  streams: { stream: string; students: number }[]
}

export const CLASSES = ['S.1', 'S.2', 'S.3', 'S.4', 'S.5', 'S.6']

export const SCALE = [
  { value: 1, label: 'Strongly disagree', short: 'SD' },
  { value: 2, label: 'Disagree', short: 'D' },
  { value: 3, label: 'Not sure', short: 'NS' },
  { value: 4, label: 'Agree', short: 'A' },
  { value: 5, label: 'Strongly agree', short: 'SA' },
]

export const TYPE_LABELS: Record<QuestionType, string> = {
  scale: 'Agree / disagree (1-5)',
  choice: 'Pick one',
  multi: 'Pick any',
  yesno: 'Yes / No',
  text: 'Written answer',
}
