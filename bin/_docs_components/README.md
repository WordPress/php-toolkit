# Docs catalog source

Each `<slug>.md` file in this directory is the source of truth for one
component on the [PHP Toolkit docs site](https://wordpress.github.io/php-toolkit/).
Editing a file here changes both the rendered reference page and the snippet
that runs in CI.

The order in which components appear in the docs is controlled by
`_order.txt` in this directory.

## Format

```markdown
---
slug: <component-slug>
title: <Component Display Name>
install: <packagist-package-name>     # optional
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
echo "hello";
```
```

### Rules

- **Frontmatter** is required. `slug` must match the filename. `title` is required.
  `install` is the Packagist package name (e.g. `wp-php-toolkit/zip`); omit it for
  components without an installable package.

- **The lede** is everything between the closing `---` and the first `## ` heading.
  Treat it as raw HTML — `<code>`, `<a>`, etc. all render directly. Single line
  or multiple paragraphs separated by blank lines.

- **Sections** start at `## Heading` (column zero). Each section's body is the
  raw HTML between its heading and the next `## ` (or end of file). Blank lines
  separate paragraphs; newlines *inside* `<pre><code>` blocks are preserved.

- **Snippets** are optional, at most one per section, and have two parts:

  1. An HTML comment with metadata:
     ```
     <!-- snippet:
     filename: <name>.php
     runnable: true | false
     -->
     ```
     `filename` is required and is used as the cache key in
     `bin/_expected_outputs.json`. `runnable` defaults to `true`.

  2. A fenced PHP block immediately after the metadata comment:
     ````
     ```php
     <?php
     ...
     ```
     ````
     The fence holds the snippet *verbatim* — including the opening `<?php`
     and the autoload `require`. There is no implicit prelude. If the snippet
     itself contains a triple-backtick run (e.g. a markdown sample inside a
     heredoc), use a four-backtick fence; the loader matches the opening
     length.

- **Runnable snippets** are executed in CI by `bin/run-snippets.py --check`
  and their stdout is compared to `bin/_expected_outputs.json`. After
  changing a snippet's output, run `bin/run-snippets.py --update` to refresh
  the JSON.

- **Non-runnable snippets** (`runnable: false`) are still validated with
  `php -l`, so syntax errors fail CI.

## Round-trip and verification

`bin/_load_catalog.py` parses these files at build/check time and feeds the
existing build scripts (`bin/build-docs.py`, `bin/build-reference.py`,
`bin/run-snippets.py`). The schema is intentionally trivial — there is no
templating or include system, so what you see in the markdown file is what
ships on the site.

`bin/_extract_catalog.py` is the one-shot tool that produced these files
from the legacy Python catalog during the migration. It is kept in the tree
as a regression aid: re-running it against an old branch is a quick way to
diff catalog state.
