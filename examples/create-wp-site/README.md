# Import Static Files Examples

This repository contains scripts and examples demonstrating how to import static files and documentation from various sources into your project using `bun` and custom scripts.

## Usage

Run the examples using the provided shell script:

```bash
./run-examples.sh
```

Make sure you have [bun](https://bun.sh/) installed before running the script.


## Examples Included

### 1. Gutenberg Documentation (Subset)

Import a subset of Gutenberg documentation focused on data basics:

```bash
bun index.js \
  git https://github.com/WordPress/gutenberg.git \
  --branch=trunk \
  --path-in-repo=docs/how-to-guides/data-basics/ \
  --media-url=https://developer.wordpress.org/files/ \
  --media-url=https://raw.githubusercontent.com/WordPress/gutenberg/HEAD/docs/ \
  --source-site-url=https://developer.wordpress.org/block-editor/how-to-guides/data-basics/ \
  --additional-site-urls=https://developer.wordpress.org/docs/how-to-guides/data-basics/
```

Import the complete Gutenberg documentation:

```bash
bun index.js \
  git https://github.com/WordPress/gutenberg.git \
  --branch=trunk \
  --path-in-repo=docs/ \
  --media-url=https://developer.wordpress.org/files/ \
  --media-url=https://raw.githubusercontent.com/WordPress/gutenberg/HEAD/docs/ \
  --source-site-url=https://developer.wordpress.org/block-editor/ \
  --additional-site-urls=https://developer.wordpress.org/docs/
```

### 3. Adam's Blog (Crawler Example)

Import content from Adam's blog using a crawler:

```bash
bun examples/create-wp-site/index.js crawler https://adamadam.blog
```

**Commentary:**  
- Content import well, including media files and internal links:
- Known issues:
  - HTML to Block markup conversion needs improvement.
  - Header and footer are included in each page.


### 4. Accessibility Testing Content (WXR Import)

Import accessibility testing content from a WordPress XML export:

```bash
bun index.js wxr https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml
```


### 5. Theme Unit Test Data (WXR Import)

Import standard WordPress theme unit test data:

```bash
bun index.js wxr https://raw.githubusercontent.com/WordPress/theme-test-data/master/themeunittestdata.wordpress.xml
```


### 6. EPUB Import Example

Import content from an EPUB file:

```bash
bun index.js epub https://github.com/IDPF/epub3-samples/releases/download/20230704/childrens-literature.epub
```

### 7. Gutenberg Docs from Local Checkout

Import Gutenberg documentation from a local repository checkout:

```bash
bun index.js path ../../../gutenberg/docs/how-to-guides/
```


### 8. Playground Docs – Blueprints Tutorial

Import documentation from the WordPress Playground project:

```bash
bun index.js \
  git https://github.com/WordPress/wordpress-playground.git \
  --branch=trunk \
  --path-in-repo=packages/docs/site/docs/blueprints/ \
  --media-url=https://wordpress.github.io/wordpress-playground/ \
  --source-site-url=https://wordpress.github.io/
```

### 9. Bootstrap 5.3 Documentation

Import Bootstrap 5.3 documentation:

```bash
bun index.js \
  git https://github.com/twbs/bootstrap.git \
  --branch=gh-pages \
  --path-in-repo=docs/5.3/ \
  --media-url=https://getbootstrap.com/docs/5.3/ \
  --source-site-url=https://getbootstrap.com/docs/5.3/ \
  --additional-site-urls=https://getbootstrap.com/docs/
```

### 10. Laravel Documentation

Import Laravel documentation:

```bash
bun index.js \
  git https://github.com/laravel/docs.git \
  --path-in-repo=/ \
  --branch=12.x \
  --source-site-url=https://laravel.com/docs/
```

### 11. CPython Internal Documentation

Import internal documentation from the CPython repository:

```bash
bun index.js \
  git https://github.com/python/cpython.git \
  --branch=main \
  --path-in-repo=InternalDocs/ \
  --source-site-url=https://raw.githubusercontent.com/python/cpython/refs/heads/main/InternalDocs/
```

### 12. Fullstack GraphQL Book

Import content from the Fullstack GraphQL book repository:

```bash
bun index.js \
  git https://github.com/GraphQLCollege/fullstack-graphql.git \
  --branch=master \
  --path-in-repo=manuscript/ \
  --source-site-url=https://raw.githubusercontent.com/GraphQLCollege/fullstack-graphql/refs/heads/master/manuscript/
```

### 13. CPP WASM Book

Import content from the CPP WASM book repository:

```bash
bun index.js \
  git https://github.com/3dgen/cppwasm-book.git \
  --branch=master \
  --path-in-repo=en/ \
  --source-site-url=https://raw.githubusercontent.com/3dgen/cppwasm-book/refs/heads/master/en/
```

## License

This project is open-source and available under the GPLv2.1 License.
