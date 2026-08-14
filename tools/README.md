# tools/

Development helpers. **Not deployed** — `tools/**` is in the deploy exclude list,
so nothing here ever reaches the server.

## `check_php_blocks.py`

```
python tools/check_php_blocks.py
```

Checks every `.php` file for unbalanced alternation syntax — an `if (…):` with no
`endif;`, an `endforeach;` closing an `if`, and so on. Run it from the project
root; it prints the file and line of anything that doesn't pair up.

**Why it exists.** These screens are mostly HTML with PHP woven through, and a
missing `endif;` is a white page rather than a visible error. There is no PHP
binary on the machine this is developed on, so there is no `php -l` to fall back
on. Counting braces with `grep` was the first attempt and it is not good enough:
it cannot tell a real mismatch from its own mis-parse, and it produced several
false alarms on healthy files.

**What it took to make it trustworthy** — worth knowing before "improving" it:

- A PHP file is mostly HTML, and prose is full of apostrophes (`didn't`). Read as
  string delimiters, they swallow everything up to the next quote, including the
  `endif;` that follows.
- `'#4A90D9'` is a colour, not a comment. Treat `#` as a comment start and the
  closing quote vanishes with it.
- `'https://…'` — that `//` is inside a string.

Strings and comments therefore have to be lexed in **one** left-to-right pass
over `<?php … ?>` regions only. Each has to be able to mask the other, which two
separate regex sweeps cannot do in either order.

It is a structural check, not a parser: it says nothing about undefined
variables, typos in function names, or SQL. It catches the one class of mistake
that is both easy to make in this codebase and invisible until a page is blank.
