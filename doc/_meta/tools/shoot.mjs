// Usage: node shoot.mjs <outDir> [onlyName]
// Takes documentation screenshots of the WPS UI in en and ru.
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'
import path from 'node:path'

const UI = process.env.UI || 'http://localhost:3081'
const API = process.env.API || 'http://localhost:9511'
const OUT = process.argv[2]
const ONLY = process.argv[3]

const login = async (email = 'admin@wps.local', password = 'admin') => {
  const r = await fetch(`${API}/api/auth/login`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password }),
  })
  return (await r.json()).data
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// shot list: name, url, optional prepare(page), opts
const SHOTS = [
  { name: '01-login', url: '/login', anon: true },
  { name: '02-processes-list', url: '/processes' },
  { name: '03-process-detail', url: '/processes/1' },
  { name: '04-process-edit-header', url: '/processes/1/edit' },
  { name: '05-process-edit-conditions', url: '/processes/3/edit', scrollTo: 'conditions' },
  { name: '06-process-edit-preload', url: '/processes/3/edit', scrollTo: 'actions', expand: ['preload'] },
  { name: '07-process-edit-modify', url: '/processes/2/edit', scrollTo: 'actions', expand: ['modify'] },
  { name: '08-process-edit-start-process', url: '/processes/6/edit', scrollTo: 'actions', expand: ['start'] },
  { name: '09-process-create-validation', url: '/processes/create', prepare: 'saveEmpty' },
  { name: '10-process-executions', url: '/processes/1', scrollTo: 'executions' },
  { name: '11-execution-detail-success', url: 'EXEC:1', },
  { name: '12-execution-detail-failed', url: 'EXEC:5' },
  { name: '13-execution-detail-parent-child', url: 'EXEC:6' },
  { name: '14-manual-run-dialog', url: '/processes/1', prepare: 'openRun' },
  { name: '15-process-run-once-marks', url: '/processes/6', prepare: 'runsTab' },
  { name: '16-users', url: '/users' },
  { name: '17-language-switcher', url: '/processes', prepare: 'langMenu' },
  { name: '18-process-detail-child', url: '/processes/4' },
]

async function execId(token, processId, idx = 0) {
  const r = await fetch(`${API}/api/processes/${processId}/executions?per_page=50`, { headers: { Authorization: `Bearer ${token}` } })
  const list = (await r.json()).data
  return list[idx].id
}

async function clickByText(page, selector, texts) {
  return page.evaluate((selector, texts) => {
    const els = [...document.querySelectorAll(selector)]
    const el = els.find((e) => texts.some((t) => e.textContent.trim().includes(t)))
    if (el) { el.scrollIntoView({ block: 'center' }); el.click(); return true }
    return false
  }, selector, texts)
}

async function main() {
  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome', headless: true,
    args: ['--no-sandbox', '--window-size=1440,900', '--lang=en-US'],
  })
  const auth = await login()
  for (const lang of ['en', 'ru']) {
    fs.mkdirSync(path.join(OUT, lang), { recursive: true })
    for (const s of SHOTS) {
      if (ONLY && s.name !== ONLY) continue
      const page = await browser.newPage()
      await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 })
      await page.goto(`${UI}/login?lang=${lang}`, { waitUntil: 'domcontentloaded' }).catch(() => {}); await sleep(800)
      await page.evaluate(() => localStorage.clear())
      if (!s.anon) {
        await page.evaluate((a, r) => {
          localStorage.setItem('wps_access_token', a)
          localStorage.setItem('wps_refresh_token', r)
        }, auth.access_token, auth.refresh_token)
      }
      let url = s.url
      if (url.startsWith('EXEC:')) url = `/executions/${await execId(auth.access_token, url.slice(5))}`
      await page.evaluate((u) => { location.href = u }, `${UI}${url}${url.includes('?') ? '&' : '?'}lang=${lang}`)
      await sleep(2500)
      if (s.expand) {
        for (const name of s.expand) {
          await page.evaluate((name) => {
            const items = [...document.querySelectorAll('.el-collapse-item')]
            const idx = { preload: 0, modify: 1, start: 2 }[name]
            const item = items[idx]
            if (item && !item.classList.contains('is-active')) item.querySelector('.el-collapse-item__header').click()
          }, name)
        }
        await sleep(600)
      }
      if (s.scrollTo === 'conditions' || s.scrollTo === 'actions') {
        await page.evaluate((which) => {
          const cards = [...document.querySelectorAll('.panel-card')]
          const card = which === 'conditions' ? cards[1] : cards[2]
          if (card) card.scrollIntoView({ block: 'start' })
        }, s.scrollTo)
      }
      if (s.scrollTo === 'executions') {
        await page.evaluate(() => {
          const t = document.querySelector('.el-tabs')
          if (t) t.closest('.panel-card').scrollIntoView({ block: 'start' })
        })
      }
      if (s.prepare === 'saveEmpty') {
        await clickByText(page, '.page-header button', ['Save', 'Сохранить'])
        await sleep(800)
        await page.evaluate(() => window.scrollTo(0, 0))
      }
      if (s.prepare === 'openRun') {
        await clickByText(page, '.page-header button', ['Run', 'Запуст'])
        await sleep(800)
      }
      if (s.prepare === 'runsTab') {
        await page.evaluate(() => {
          const tab = document.querySelector('#tab-runs')
          if (tab) { tab.scrollIntoView({ block: 'start' }); tab.click() }
        })
        await sleep(900)
        await page.evaluate(() => {
          const t = document.querySelector('.el-tabs')
          if (t) t.closest('.panel-card').scrollIntoView({ block: 'start' })
        })
      }
      if (s.prepare === 'langMenu') {
        await page.evaluate(() => document.querySelector('.el-header .el-dropdown, header .el-dropdown, .el-dropdown')?.querySelector('*')?.click())
        await sleep(600)
      }
      await sleep(400)
      const file = path.join(OUT, lang, `${s.name}.png`)
      await page.screenshot({ path: file })
      console.log(file)
      await page.close()
    }
  }
  await browser.close()
}

main().catch((e) => { console.error(e); process.exit(1) })
