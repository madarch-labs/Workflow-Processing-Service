#!/usr/bin/env python3
"""Checks doc/: en/ru trees identical, relative links and images exist, #anchors exist (GitHub slug rules).
Run from the repository root: python3 doc/_meta/tools/check_links.py"""
import os, re, sys, unicodedata

DOC = os.path.normpath(os.path.join(os.path.dirname(__file__), '..', '..'))
LINK = re.compile(r'(!?)\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)')

def slug(h):
    h = re.sub(r'`', '', h.strip().lower())
    h = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', h)
    out = []
    for ch in h:
        if ch.isalnum() or ch in '-_' or unicodedata.category(ch).startswith('L'):
            out.append(ch)
        elif ch == ' ':
            out.append('-')
    return ''.join(out)

def anchors(path):
    res, seen, fence = set(), {}, False
    for line in open(path, encoding='utf-8'):
        if line.lstrip().startswith('```'):
            fence = not fence
        if fence:
            continue
        m = re.match(r'#{1,6}\s+(.*)', line)
        if m:
            s = slug(m.group(1)); n = seen.get(s, 0); seen[s] = n + 1
            res.add(s if n == 0 else f'{s}-{n}')
    for m in re.finditer(r'<a\s+(?:name|id)="([^"]+)"', open(path, encoding='utf-8').read()):
        res.add(m.group(1))
    return res

def files(lang):
    base = os.path.join(DOC, lang)
    return sorted(os.path.relpath(os.path.join(r, f), base) for r, _, fs in os.walk(base) for f in fs)

errors = []
en, ru = files('en'), files('ru')
for f in sorted(set(en) ^ set(ru)):
    errors.append(f'tree mismatch: {f} only in {"en" if f in en else "ru"}')

md = [os.path.join(r, f) for r, _, fs in os.walk(DOC) for f in fs if f.endswith('.md')]
cache = {}
for path in md:
    text = open(path, encoding='utf-8').read()
    text_nocode = re.sub(r'```.*?```', '', text, flags=re.S)
    text_nocode = re.sub(r'`[^`\n]*`', '', text_nocode)
    for img, label, target in LINK.findall(text_nocode):
        if re.match(r'^(https?:|mailto:)', target):
            continue
        file_part, _, anchor = target.partition('#')
        dest = os.path.normpath(os.path.join(os.path.dirname(path), file_part)) if file_part else path
        rp = os.path.relpath(path, DOC)
        if not os.path.exists(dest):
            errors.append(f'{rp}: broken {"image" if img else "link"} -> {target}')
            continue
        if anchor and dest.endswith('.md'):
            if dest not in cache:
                cache[dest] = anchors(dest)
            if anchor not in cache[dest]:
                errors.append(f'{rp}: missing anchor -> {target}')

print('\n'.join(errors) if errors else 'OK: no broken links, trees identical')
print(f'{len(md)} markdown files checked')
sys.exit(1 if errors else 0)
