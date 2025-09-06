# Toolkit Coding Standards

PHPCS ruleset for this toolkit. Use it to lint or auto‑fix code in the components.

## Problems Solved
- Consistent coding style across components
- Easy integration with CI and local development

## Usage
```bash
vendor/bin/phpcs --standard=components/ToolkitCodingStandards/WordPressToolkitCodingStandards \
  components/Filesystem components/Git

vendor/bin/phpcbf --standard=components/ToolkitCodingStandards/WordPressToolkitCodingStandards \
  components/Filesystem
```

The root ruleset is `components/ToolkitCodingStandards/WordPressToolkitCodingStandards/ruleset.xml`.

