#!/usr/bin/env node
/**
 * Проверка локализации:
 *  1. у всех src/locales/*.json одинаковый набор ключей (эталон — ru.json);
 *  2. все ключи t('…') / te('…') из кода существуют в ru.json;
 *  3. в .vue/.ts нет захардкоженной кириллицы вне комментариев.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, extname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const srcDir = join(root, 'src')
const localesDir = join(srcDir, 'locales')
const BASE = 'ru'
/** Файлы, где кириллица допустима (названия языков на самих языках) */
const CYRILLIC_ALLOWED = new Set(['src/i18n/locales.ts'])

let failed = false
const fail = (msg) => {
  failed = true
  console.error('✗ ' + msg)
}

function flatten(obj, prefix = '', out = new Map()) {
  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object') flatten(v, key, out)
    else out.set(key, v)
  }
  return out
}

function walk(dir, files = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name)
    if (statSync(full).isDirectory()) {
      if (full !== localesDir) walk(full, files)
    } else if (['.vue', '.ts'].includes(extname(name)) && !name.endsWith('.d.ts')) {
      files.push(full)
    }
  }
  return files
}

// 1. одинаковые ключи во всех языках
const locales = Object.fromEntries(
  readdirSync(localesDir)
    .filter((f) => f.endsWith('.json'))
    .map((f) => [f.replace('.json', ''), flatten(JSON.parse(readFileSync(join(localesDir, f), 'utf8')))]),
)
const base = locales[BASE]
for (const [lang, keys] of Object.entries(locales)) {
  if (lang === BASE) continue
  const missing = [...base.keys()].filter((k) => !keys.has(k))
  const extra = [...keys.keys()].filter((k) => !base.has(k))
  missing.forEach((k) => fail(`${lang}.json: нет ключа ${k}`))
  extra.forEach((k) => fail(`${lang}.json: лишний ключ ${k}`))
  for (const [k, v] of keys) if (v === '') fail(`${lang}.json: пустой перевод ${k}`)
}

// 2–3. ключи в коде и забытые строки
const keyRe = /\b(?:t|te|\$t)\(\s*['"]([\w.]+)['"]/g
const stripComments = (code) =>
  code
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:'"`])\/\/.*$/gm, '$1')

for (const file of walk(srcDir)) {
  const rel = relative(root, file)
  const code = readFileSync(file, 'utf8')
  for (const m of code.matchAll(keyRe)) {
    const key = m[1]
    // ключи-префиксы для динамических ключей (`execution.status.${s}`) сюда не попадают
    if (!base.has(key)) fail(`${rel}: ключ «${key}» отсутствует в ${BASE}.json`)
  }
  if (CYRILLIC_ALLOWED.has(rel)) continue
  stripComments(code)
    .split('\n')
    .forEach((line, i) => {
      if (/[А-Яа-яЁё]/.test(line)) fail(`${rel}:${i + 1}: кириллица вне комментария: ${line.trim()}`)
    })
}

if (failed) process.exit(1)
console.log(`✓ i18n: ${base.size} ключей, языки: ${Object.keys(locales).join(', ')}`)
