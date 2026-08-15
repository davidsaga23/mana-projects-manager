"""Structural check of PHP alternation syntax (if:/endif;, foreach:/endforeach;).

Brace counting can't distinguish a real mismatch from a regex that mis-parses
nesting, so this walks tokens with a stack and names the block that is unclosed.

Getting the input right turned out to be most of the job. Three traps, each of
which made a healthy file look broken:

  * A PHP file is mostly HTML, and prose is full of apostrophes ("didn't"). Read
    as string delimiters they swallow the endif that follows.
  * '#4A90D9' — a colour literal. Treat '#' as a comment start and the closing
    quote disappears with it.
  * 'https://…' — the '//' is inside a string, not a comment.

Strings and comments are therefore lexed in ONE left-to-right pass over the PHP
regions only, which is the only way each can correctly mask the other.
"""
import io
import re
import glob

KW_OPEN = ('if', 'foreach', 'for', 'while', 'switch')
TOKEN = re.compile(
    r'\b(if|elseif|else|foreach|for|while|switch'
    r'|endif|endforeach|endfor|endwhile|endswitch)\b'
)


def php_code_only(text):
    """Blank HTML, strings, heredocs and comments; keep executable PHP in place.

    Newlines are preserved throughout so reported line numbers match the file.
    """
    out = []
    i = 0
    n = len(text)
    in_php = False

    def keep(ch):
        out.append(ch)

    def hide(ch):
        out.append('\n' if ch == '\n' else ' ')

    while i < n:
        if not in_php:
            if text.startswith('<?php', i):
                in_php = True
                for c in text[i:i + 5]:
                    hide(c)
                i += 5
            elif text.startswith('<?=', i):
                in_php = True
                for c in text[i:i + 3]:
                    hide(c)
                i += 3
            else:
                hide(text[i])
                i += 1
            continue

        # --- inside PHP ---
        if text.startswith('?>', i):
            in_php = False
            hide(text[i]); hide(text[i + 1])
            i += 2
        elif text.startswith('//', i) or text[i] == '#':
            while i < n and text[i] != '\n':
                hide(text[i]); i += 1
        elif text.startswith('/*', i):
            j = text.find('*/', i + 2)
            j = n if j == -1 else j + 2
            while i < j:
                hide(text[i]); i += 1
        elif text[i] in ("'", '"'):
            q = text[i]
            hide(text[i]); i += 1
            while i < n:
                if text[i] == '\\' and i + 1 < n:
                    hide(text[i]); hide(text[i + 1]); i += 2
                    continue
                if text[i] == q:
                    hide(text[i]); i += 1
                    break
                hide(text[i]); i += 1
        elif text.startswith('<<<', i):
            m = re.match(r"<<<\s*['\"]?([A-Za-z_]\w*)['\"]?\r?\n", text[i:])
            if m:
                label = m.group(1)
                end = re.search(r'^\s*' + label + r'\b', text[i + m.end():], re.M)
                stop = i + m.end() + (end.end() if end else 0)
                while i < stop and i < n:
                    hide(text[i]); i += 1
            else:
                keep(text[i]); i += 1
        else:
            keep(text[i]); i += 1

    return ''.join(out)


def check(path):
    src = php_code_only(io.open(path, encoding='utf-8').read())
    stack = []
    errs = []
    for m in TOKEN.finditer(src):
        word = m.group(1)
        line = src.count('\n', 0, m.start()) + 1

        if word in KW_OPEN:
            j = m.end()
            while j < len(src) and src[j] in ' \t\r\n':
                j += 1
            if j < len(src) and src[j] == '(':
                depth = 0
                while j < len(src):
                    if src[j] == '(':
                        depth += 1
                    elif src[j] == ')':
                        depth -= 1
                        if depth == 0:
                            j += 1
                            break
                    j += 1
            while j < len(src) and src[j] in ' \t\r\n':
                j += 1
            if j < len(src) and src[j] == ':' and src[j:j + 2] != '::':
                stack.append((word, line))

        elif word.startswith('end'):
            want = word[3:]
            if not stack:
                errs.append('line %d: %s with nothing open' % (line, word))
            else:
                opened, oline = stack.pop()
                if opened != want:
                    errs.append('line %d: %s closes a %s opened at line %d'
                                % (line, word, opened, oline))

    for opened, oline in stack:
        errs.append('line %d: %s: never closed' % (oline, opened))
    return errs


files = sorted(set(
    glob.glob('*.php') + glob.glob('includes/*.php') + glob.glob('includes/*/*.php')
))
bad = 0
for f in files:
    errs = check(f)
    if errs:
        bad += 1
        print(f + ':')
        for e in errs[:8]:
            print('    ' + e)
print('')
print('%d files checked, %d with unbalanced alternation blocks' % (len(files), bad))


# ---------------------------------------------------------------------------
# Duplicate function names.
#
# A second definition of a name already declared is a FATAL error in PHP, so the
# page 500s the moment both files are loaded together — and only then. Every
# other page keeps working, which is what makes it look like a bug in the new
# screen rather than a collision with an old one.
#
# Cost person.php its first load (2026-08-15): money.php defined fmt_rate() for a
# PAY rate, quota.php already had one for a WORK rate. Same English word, two
# meanings, one fatal.
#
# Checked here rather than in a separate script because START_HERE tells people
# to run ONE command before handing over, and a check nobody runs is not a check.
# ---------------------------------------------------------------------------
FUNC = re.compile(r'^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(', re.M)

seen = {}
for f in files:
    text = io.open(f, encoding='utf-8', errors='replace').read()
    code = php_code_only(text)
    for m in FUNC.finditer(code):
        name = m.group(1)
        line = code[:m.start()].count(chr(10)) + 1
        seen.setdefault(name.lower(), []).append((f, line))

clashes = {n: w for n, w in seen.items() if len(w) > 1}
if clashes:
    print('')
    print('DUPLICATE FUNCTION NAMES — PHP fatals when two of these load together:')
    for name, where in sorted(clashes.items()):
        print('  %s()' % name)
        for f, line in where:
            print('      %s:%d' % (f, line))
print('')
print('%d function names defined, %d duplicated' % (len(seen), len(clashes)))
