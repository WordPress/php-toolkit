# Docs catalog source

Each `<slug>.md` file in this directory is the source of truth for one
component on the [PHP Toolkit docs site](https://wordpress.github.io/php-toolkit/).
Editing a file here changes the rendered reference page **and** the snippet
that runs in CI **and** the captured expected output that the page
pre-renders before WordPress Playground boots.

Component order on the site is controlled by `_order.txt` in this directory.

## Format

````markdown
---
slug: <component-slug>
title: <Component Display Name>
install: <packagist-package-name>          # optional

credit_title: <one-line credit summary>    # optional
credit_body: |
  <multi-line HTML — indent each line by
  two spaces; blank lines preserved>

see_also: <slug> | <Title> | <reason>      # optional, repeatable
see_also: <other-slug> | <Other> | <reason>
---

<one-paragraph lede, raw HTML allowed (e.g. <code>...</code>)>

## Section heading

<body content for the section, raw HTML allowed>

<!-- snippet:
filename: example.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

// example code...
echo "hello\n";
```

<!-- expected-output -->
```
hello
```
````

### Rules

- **Frontmatter** is required. `slug` must match the filename. `title` is
  required. `install` is the Packagist package name (e.g.
  `wp-php-toolkit/zip`); omit it for components that don't ship as a
  separate package.

- **`credit_title` + `credit_body`** render as a callout below the lede on
  the reference page (used for "Ported from WordPress core" notes and the
  like). Body text is raw HTML using the YAML pipe (`|`) form: each
  continuation line is indented two spaces.

- **`see_also`** lines render as the "See also" section at the bottom of
  the page. Format: `<slug> | <Title> | <reason>`. Repeat the key once per
  related component.

- **The lede** is everything between the closing `---` and the first `## `
  heading. Raw HTML allowed.

- **Sections** start at `## Heading` (column zero). Each section's body is
  the raw HTML between its heading and the next `## ` (or end of file).
  Blank lines separate paragraphs; newlines inside `<pre><code>` and inside
  fenced code blocks are preserved.

- **Snippets** are optional, at most one per section, and have two parts:

  1. An HTML comment with metadata:
     ```
     <!-- snippet:
     filename: <name>.php
     runnable: true | false
     -->
     ```
     `filename` is required and uniquely identifies the snippet within the
     component. `runnable` defaults to `true`.

  2. A fenced PHP block immediately after the metadata comment. The fence
     holds the snippet *verbatim* — including the opening `<?php` and the
     autoload `require`. There is no implicit prelude. If the snippet
     itself contains a triple-backtick run (e.g. a markdown sample inside a
     heredoc), use a four-backtick fence; the loader matches the opening
     length.

- **Expected outputs** sit in a sibling fenced block right after the snippet's
  php fence, marked with `<!-- expected-output -->`. The block holds the
  captured stdout from running the snippet locally. The docs site uses it
  to pre-render results before Playground boots; CI compares against it on
  every PR.

- **Pitfalls** are paragraphs in any section's body that begin with
  "Footgun:" or "Gotcha:". `bin/build-reference.py` lifts them out and
  renders them as a unified "Pitfalls" section near the bottom of the page.

## Workflow

- Edit a `.md` file. Snippet code, prose, expected outputs — all live here.
- Run `python3 bin/build-reference.py` to regenerate the local HTML pages.
- Run `bin/run-snippets.py --check` to verify that snippets still produce
  the captured stdout. If a change is intentional, `--update` rewrites the
  expected-output blocks in place.

The generated `docs/reference/<slug>.html` files are **not** checked in —
they regenerate from these markdown sources on every deploy and are
listed in the repo `.gitignore`. Treat them as a build artifact, not as
content. Same for `docs/assets/php-toolkit.zip`, which
`bin/build-docs-bundle.sh` rebuilds from the toolkit source.

CI runs `bin/run-snippets.py --check` on every PR
(`.github/workflows/snippet-tests.yml`) and `bin/build-reference.py` on
every push to `trunk` (`.github/workflows/docs.yml`).

## Tooling notes

`bin/_load_catalog.py` parses these files into the COMPONENTS data
structure consumed by `bin/build-reference.py` and `bin/run-snippets.py`.

`bin/_extract_catalog.py` is the one-shot tool that produced these files
from the legacy Python catalog during the migration. It is kept in the
tree as a regression aid: re-running it after manual edits is a quick way
to confirm that the catalog state can still round-trip.
