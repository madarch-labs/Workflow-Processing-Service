#!/usr/bin/env python3
"""Inserts the language switch and the footer navigation into every page of doc/en and doc/ru.

Run from the repository root:  python3 doc/_meta/tools/nav.py
Idempotent: previously inserted blocks (between the HTML comment markers) are replaced.
The page order below is the reading order; doc/<lang>/README.md is the table of contents.
"""
import os
import re
import sys

DOC = os.path.join(os.path.dirname(__file__), '..', '..')
ORDER = [
    '01-overview/index.md',
    '01-overview/glossary.md',
    '02-getting-started/requirements.md',
    '02-getting-started/quick-start.md',
    '02-getting-started/first-process.md',
    '03-user-guide/login-and-ui.md',
    '03-user-guide/processes.md',
    '03-user-guide/triggers-and-conditions.md',
    '03-user-guide/actions.md',
    '03-user-guide/change-types-and-expressions.md',
    '03-user-guide/subprocesses.md',
    '03-user-guide/executions-and-audit.md',
    '03-user-guide/manual-run-and-reset.md',
    '03-user-guide/users-and-roles.md',
    '03-user-guide/recipes.md',
    '04-operations/deployment.md',
    '04-operations/configuration.md',
    '04-operations/crm-integration.md',
    '04-operations/monitoring.md',
    '04-operations/reliability.md',
    '04-operations/security.md',
    '04-operations/troubleshooting.md',
    '05-architecture/overview.md',
    '05-architecture/backend.md',
    '05-architecture/event-flow.md',
    '05-architecture/execution-engine.md',
    '05-architecture/data-model.md',
    '05-architecture/frontend.md',
    '05-architecture/decisions.md',
    '06-api-reference/index.md',
    '06-api-reference/endpoints.md',
    '07-developer-guide/dev-environment.md',
    '07-developer-guide/project-structure.md',
    '07-developer-guide/coding-conventions.md',
    '07-developer-guide/testing.md',
    '07-developer-guide/extending/new-action-type.md',
    '07-developer-guide/extending/new-comparator.md',
    '07-developer-guide/extending/new-change-type.md',
    '07-developer-guide/extending/new-load-strategy.md',
    '07-developer-guide/extending/expression-functions.md',
    '07-developer-guide/extending/new-api-endpoint.md',
    '07-developer-guide/extending/new-crm-adapter.md',
    '07-developer-guide/extending/new-ui-screen.md',
    '07-developer-guide/extending/new-language.md',
    '07-developer-guide/contributing.md',
    '08-faq.md',
]

TEXT = {
    'en': {'prev': '← Previous', 'next': 'Next →', 'toc': 'Contents'},
    'ru': {'prev': '← Назад', 'next': 'Далее →', 'toc': 'Оглавление'},
}
LANG_RE = re.compile(r'<!-- lang-switch -->.*?<!-- /lang-switch -->\n*', re.S)
FOOT_RE = re.compile(r'\n*<!-- footer-nav -->.*?<!-- /footer-nav -->\n*', re.S)


def rel(from_page: str, to_path: str) -> str:
    return os.path.relpath(to_path, os.path.dirname(from_page)).replace(os.sep, '/')


def title(path: str) -> str:
    with open(path, encoding='utf-8') as f:
        for line in f:
            if line.startswith('# '):
                return line[2:].strip()
    return os.path.basename(path)


def lang_switch(lang: str, page: str) -> str:
    other = 'ru' if lang == 'en' else 'en'
    link = '../' * (page.count('/') + 1) + f'{other}/{page}'
    if lang == 'en':
        body = f'🇬🇧 English | [🇷🇺 Русский]({link})'
    else:
        body = f'[🇬🇧 English]({link}) | 🇷🇺 Русский'
    return f'<!-- lang-switch -->\n{body}\n<!-- /lang-switch -->\n\n'


def footer(lang: str, page: str) -> str:
    base = os.path.join(DOC, lang)
    i = ORDER.index(page)
    parts = []
    if i > 0:
        p = ORDER[i - 1]
        parts.append(f'{TEXT[lang]["prev"]}: [{title(os.path.join(base, p))}]({rel(page, p)})')
    parts.append(f'[{TEXT[lang]["toc"]}]({rel(page, "README.md")})')
    if i < len(ORDER) - 1:
        n = ORDER[i + 1]
        parts.append(f'[{title(os.path.join(base, n))}]({rel(page, n)}) {TEXT[lang]["next"]}')
    return '\n\n<!-- footer-nav -->\n---\n\n' + ' | '.join(parts) + '\n<!-- /footer-nav -->\n'


def process(lang: str, page: str) -> None:
    path = os.path.join(DOC, lang, page)
    with open(path, encoding='utf-8') as f:
        text = f.read()
    text = FOOT_RE.sub('', LANG_RE.sub('', text)).rstrip() + '\n'
    lines = text.split('\n')
    # after the H1 and the first paragraph (the one-line description)
    h1 = next(i for i, l in enumerate(lines) if l.startswith('# '))
    j = h1 + 1
    while j < len(lines) and lines[j].strip() == '':
        j += 1
    while j < len(lines) and lines[j].strip() != '':
        j += 1
    head = '\n'.join(lines[:j]).rstrip() + '\n\n'
    rest = '\n'.join(lines[j:]).lstrip('\n')
    text = head + lang_switch(lang, page) + rest
    text = text.rstrip() + footer(lang, page)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(text)


def main() -> int:
    missing = [f'{l}/{p}' for l in ('en', 'ru') for p in ORDER if not os.path.exists(os.path.join(DOC, l, p))]
    if missing:
        print('missing pages:\n  ' + '\n  '.join(missing))
        return 1
    for lang in ('en', 'ru'):
        for page in ORDER:
            process(lang, page)
    print(f'updated {len(ORDER) * 2} pages')
    return 0


if __name__ == '__main__':
    sys.exit(main())
