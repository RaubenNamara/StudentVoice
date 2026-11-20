import type { Component } from 'vue'
import {
  AcademicCapIcon, BeakerIcon, BoltIcon, BookOpenIcon, BriefcaseIcon, BuildingLibraryIcon, CalculatorIcon,
  ChartBarIcon, ChatBubbleLeftRightIcon, ComputerDesktopIcon, GlobeEuropeAfricaIcon, HeartIcon, LanguageIcon,
  PaintBrushIcon, PencilSquareIcon, SparklesIcon, SunIcon, TrophyIcon, VariableIcon,
} from '@heroicons/vue/24/outline'

/**
 * How a subject looks to students: its full name (Tusome keeps short codes like BIO), an icon and
 * a colour. Colours stay in the Tusome family - blues, violet, sky, gold, rose, slate; green is
 * kept for "done".
 */
const TONES = {
  indigo: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/70 dark:text-indigo-300',
  violet: 'bg-violet-50 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
  sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
  gold: 'bg-gold-50 text-gold-700 dark:bg-gold-700/25 dark:text-gold-300',
  rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
  slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
}
type Tone = keyof typeof TONES

const KNOWN: Record<string, [string, Component, Tone]> = {
  BIO: ['Biology', BeakerIcon, 'sky'],
  CHEM: ['Chemistry', BeakerIcon, 'violet'],
  PHY: ['Physics', BoltIcon, 'indigo'],
  MTC: ['Mathematics', CalculatorIcon, 'indigo'],
  SUBMTC: ['Subsidiary Mathematics', VariableIcon, 'slate'],
  ENG: ['English', BookOpenIcon, 'rose'],
  LIT: ['Literature', BookOpenIcon, 'violet'],
  HIST: ['History', BuildingLibraryIcon, 'gold'],
  GEOG: ['Geography', GlobeEuropeAfricaIcon, 'sky'],
  KISW: ['Kiswahili', LanguageIcon, 'gold'],
  LUG: ['Luganda', LanguageIcon, 'rose'],
  ENT: ['Entrepreneurship', BriefcaseIcon, 'gold'],
  ECON: ['Economics', ChartBarIcon, 'indigo'],
  ICT: ['ICT', ComputerDesktopIcon, 'slate'],
  GP: ['General Paper', ChatBubbleLeftRightIcon, 'violet'],
  TD: ['Technical Drawing', PencilSquareIcon, 'slate'],
  FART: ['Fine Art', PaintBrushIcon, 'rose'],
  ART: ['Fine Art', PaintBrushIcon, 'rose'],
  AGRIC: ['Agriculture', SunIcon, 'gold'],
  PE: ['Physical Education', TrophyIcon, 'sky'],
  CRE: ['Christian Religious Education', HeartIcon, 'rose'],
  IRE: ['Islamic Religious Education', SparklesIcon, 'violet'],
}

const key = (code: string) => code.toUpperCase().replace(/[^A-Z]/g, '')

export function subjectLook(code: string): { name: string; icon: Component; tone: string } {
  const k = KNOWN[key(code)]
  if (k) return { name: k[0], icon: k[1], tone: TONES[k[2]] }
  const tones = Object.values(TONES)
  const n = [...code].reduce((s, c) => s + c.charCodeAt(0), 0)
  return { name: code, icon: AcademicCapIcon, tone: tones[n % tones.length] }
}
