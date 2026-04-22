# PRD: WP Origin

## 1. Product overview

### 1.1 Document title and version

- PRD: WP Origin
- Version: 0.1

### 1.2 Product summary

WP Origin makes WordPress content available as a Git remote where posts and pages are represented as Markdown files. A user or coding agent can run normal Git commands such as `clone`, `pull`, and `push`; WordPress remains the source of truth, and pushed Markdown updates WordPress content directly.

The MVP is a standalone WordPress plugin. It focuses on a happy path that proves the workflow works for posts and pages, while keeping the design open for media, block theme entities, custom post types, templates, navigation, and WordPress.com integration later.

The guiding rule is that users must not lose data. If a conversion cannot preserve a block safely, the plugin should preserve it as a fenced `gutenberg` code block or inline HTML, or reject the change with a clear error instead of silently dropping content.

## 2. Goals

### 2.1 Business goals

- Prove that any WordPress site can behave like a Git remote for Markdown content.
- Make WordPress content easier for agents and developer tools to read, edit, review, and version.
- Create a credible standalone plugin path that can later inform WordPress.com or Jetpack integration.
- Reuse php-toolkit Git, Markdown, and Data Liberation primitives where possible.

### 2.2 User goals

- Clone or pull site content as Markdown files.
- Edit content locally in an editor, vault, or coding-agent workspace.
- Push Markdown changes back to WordPress without visiting WP-Admin.
- Keep using WP-Admin as normal while also allowing Git-based editing.
- Rely on Git history locally for review, rollback, and conflict handling.
- Add WordPress as any Git remote name, such as `origin`, `wp`, or `production`, without requiring the site to manage other external remotes.
- Use the checked-out content in Obsidian-compatible Markdown workflows where possible.

### 2.3 Non-goals

- Do not version PHP code, themes, plugins, uploads, or the full database in the MVP.
- Do not manage GitHub/GitLab/Bitbucket synchronization in the MVP. Users can still add those as separate remotes in their local clone.
- Do not preserve a complete immutable Git object graph on the WordPress server in the MVP.
- Do not support every WordPress post type, setting, template, navigation item, or media workflow in the first release.
- Do not treat full block theme editing as MVP scope, but keep `wp_template`, `wp_template_part`, and `wp_navigation` visible as early follow-up candidates because they are post types under the hood.
- Do not introduce external Composer dependencies or required PHP extensions beyond this repo's constraints.

## 3. Users and permissions

### 3.1 Key user types

- **Site owner**: Wants simple local editing, backups, and agent-assisted content updates.
- **Content editor**: Writes or reviews posts and pages in Markdown-friendly tools.
- **Coding agent**: Reads, edits, commits, and pushes content changes on behalf of a user.
- **Developer**: Installs, configures, debugs, and extends the plugin.

### 3.2 Role-based access

- **Unauthenticated users**: Cannot clone, pull, or push private site content.
- **Users with read access**: Can clone or pull content they are allowed to read.
- **Users with edit permissions**: Can push changes to posts or pages they are allowed to edit.
- **Administrators**: Can configure enabled post types, endpoint behavior, and future advanced options.

## 4. Functional requirements

- **Git endpoint** (Priority: High)
  - Expose a Git-compatible HTTP endpoint for Markdown content at `/wp-json/git/v1/md.git`.
  - Support `git clone`, `git pull`, and `git push` for the MVP happy path.
  - Keep the endpoint scoped to content, not code or database exports.

- **Content export to Markdown** (Priority: High)
  - Export posts and pages as Markdown files.
  - Store files in predictable paths that mirror WordPress post type names, such as `post/{slug}.md` and `page/{slug}.md`.
  - Include front matter for stable WordPress metadata such as ID, type, slug, status, title, date, and modified time.
  - Preserve unsupported block markup as fenced `gutenberg` code blocks, or inline HTML when Markdown cannot represent it safely.
  - Keep Markdown human-editable and compatible with common editors such as Obsidian wherever that does not weaken round-trip fidelity.

- **Markdown import to WordPress** (Priority: High)
  - Convert pushed Markdown back into WordPress post content.
  - Update existing posts by stable metadata when possible, then by path or slug as a fallback.
  - Create new posts or pages when new Markdown files are pushed.
  - Move deleted files to trash by default instead of permanently deleting content.

- **Round-trip fidelity** (Priority: High)
  - Treat `wp -> md -> wp` and `md -> wp -> md` as byte-preservation contracts for supported content.
  - Detect byte changes using fixture-based tests that compare exact strings and content hashes, not rendered output.
  - If exact preservation is not possible for a block, preserve it as an opaque `gutenberg` fence or reject the conversion instead of normalizing it.
  - Avoid automatic newline, whitespace, attribute-order, or escaping changes unless the test fixture explicitly accepts them.

- **Media handling** (Priority: Medium)
  - Export referenced media files into an `attachment/` directory to mirror the WordPress `attachment` post type.
  - Rewrite image references in Markdown to relative paths where possible.
  - Import new or changed media files as WordPress attachments when safe.
  - Track media hashes so unchanged binaries do not get reuploaded.
  - Keep large media transfers streaming-first and reject pushes that exceed safe runtime limits.

- **Block theme entities** (Priority: Medium)
  - Explore `wp_template`, `wp_template_part`, and `wp_navigation` after posts/pages round-trip safely.
  - Represent these as explicit directories rather than mixing them into generic custom post type output.
  - Apply the same non-lossy `gutenberg` fence strategy because these entities are block markup-heavy.

- **Conflict and safety checks** (Priority: High)
  - Reject pushes that would overwrite newer WordPress edits unless the client has pulled the latest content.
  - Create WordPress revisions for pushed updates.
  - Fail closed when conversion would drop content or metadata.
  - Return actionable Git/HTTP errors that explain what the user should do next.

- **Configuration** (Priority: Medium)
  - Allow administrators to enable the MVP for posts and pages.
  - Keep custom post types disabled by default until explicitly supported.
  - Provide a minimal admin-visible status or settings surface only when needed for setup.

- **Extensibility** (Priority: Medium)
  - Keep the content mapping small and explicit so media, custom post types, templates, and navigation can be added later.
  - Keep server-side Git storage optional; the WordPress database is the MVP source of truth.

## 5. Core experience

1. An administrator activates WP Origin and enables Git access for Markdown content.
2. A user authenticates with Git over HTTP Basic Auth using a WordPress application password.
3. The user runs `git clone https://example.com/wp-json/git/v1/md.git` or adds the site as a remote with `git remote add wp https://example.com/wp-json/git/v1/md.git`.
4. The cloned repository contains Markdown files for posts and pages, plus enough metadata to map files back to WordPress content.
5. The user or agent edits Markdown locally and commits normally.
6. The user runs `git push wp trunk` or the equivalent command for the remote name they chose.
7. WP Origin validates permissions, detects stale content, converts Markdown back to WordPress content, and updates or creates posts.
8. If a push cannot be applied safely, WP Origin rejects it with a clear error and leaves existing WordPress content unchanged.

## 6. Technical considerations

### 6.1 Integration points

- WordPress REST API routes for Git Smart HTTP requests, using `/wp-json/git/v1/md.git` as the canonical route.
- A routing layer that maps `/wp-json/git/v1/md.git/*` to Git protocol paths such as `/info/refs?service=git-upload-pack`, `/git-upload-pack`, `/info/refs?service=git-receive-pack`, and `/git-receive-pack`.
- HTTP Basic Auth backed by WordPress application passwords.
- WordPress post APIs for reading, creating, updating, trashing, and revisioning content.
- php-toolkit Git component for Git protocol handling.
- php-toolkit Markdown and Data Liberation components for Markdown/block conversions.

### 6.2 Content format

- Markdown body with YAML-style front matter.
- File paths grouped by WordPress post type name, starting with `post/` and `page/`, with media under `attachment/` once media support lands.
- Front matter should be stable enough for round-trips but small enough for agents to understand.
- Unknown or lossy blocks should round-trip using fenced `gutenberg` code blocks where possible, with raw HTML as a fallback.
- The importer should also accept the existing `block` fence language as a compatibility alias.
- Markdown should remain useful in Obsidian: front matter is allowed, links and images should prefer normal Markdown syntax, media references should prefer relative paths, and hidden metadata should be kept minimal.
- If an Obsidian-friendly representation conflicts with binary-safe round-tripping, round-trip safety wins.

### 6.3 Data storage and privacy

- WordPress remains the canonical storage layer.
- The MVP does not require a persistent `.git` directory on the server.
- Local clones keep their own Git history.
- Credentials must never be written into generated Markdown files or Git history.
- Application passwords should be used only through HTTP auth and never serialized into the generated repository.
- Private, draft, or restricted content must follow existing WordPress permissions.

### 6.4 Scalability and performance

- Use streaming where available for Git pack data and content conversion.
- Start with posts and pages to keep repository size manageable.
- Avoid loading all media or full site data into memory in the MVP.
- Stream media files when exporting/importing and hash them incrementally.
- Add limits and clear errors for large pushes that exceed PHP execution or memory limits.

### 6.5 Potential challenges

- WordPress is mutable while Git expects immutable object history.
- Markdown conversion can be lossy for custom blocks and complex layouts.
- The current Markdown conversion already notes some block attributes can be lost, so the MVP must test and reject cases that would silently flatten important content.
- Git clients expect precise HTTP behavior.
- Slug changes, deleted posts, trashed posts, and duplicate titles need stable mapping rules.
- Media introduces binary data, large payloads, URL rewriting, deduplication, and attachment metadata concerns.
- Block theme entities are post types, but their content is closer to site structure than editorial content and may need separate conflict rules.
- Multiple agents and WP-Admin users can edit the same content concurrently.

## 7. Prior art and repo fit

- **`plugins/git-repo/git-repo.php`** already proves WordPress can sit behind a `GitEndpoint` and respond to Git upload/receive requests. It currently materializes a test Git repository and syncs WordPress content in an ad hoc way. WP Origin should reuse the idea but make the content mapping explicit and Markdown-first.
- **`plugins/static-files-editor/DataSource.php`** models the opposite workflow: a WordPress plugin pulls from and pushes to an external Git remote. WP Origin should invert that relationship. The site itself is the remote, and the WordPress database remains the source of truth.
- **`components/Git`** already supports pure-PHP Git repositories, commits, refs, protocol v2 discovery, fetch, receive-pack, `GitFilesystem`, and remote operations. The MVP should use these pieces rather than shelling out to the `git` binary.
- **`components/Markdown`** already supports bidirectional Markdown and block markup conversion with front matter. It serializes blocks that cannot be represented as Markdown into fenced `block` code blocks; WP Origin should evolve that toward a more descriptive `gutenberg` fence while accepting `block` for compatibility.
- **`components/DataLiberation`** supplies streaming-oriented content import/export primitives. That matters for future media and larger sites, even if the first MVP keeps the data scope small.
- **`wp-plugin-template`** uses a simple docs-first planning flow with `docs/prd.md` and a minimal plugin entry file later. For now, WP Origin should stay documentation-only until the shape is agreed.

## 8. Success metrics

- A user can clone a test site and see posts and pages as Markdown.
- A user can edit an existing post locally and push it back without data loss.
- A user can create a new Markdown file and push it as a new post or page.
- A stale push is rejected instead of overwriting newer WordPress edits.
- Round-trip tests prove byte-for-byte stability for supported `wp -> md -> wp` and `md -> wp -> md` fixtures.
- Unsupported blocks are preserved as fenced `gutenberg` payloads or HTML.
- A Git smoke-test script can clone, edit, commit, push, pull, and verify the resulting WordPress content.
- The MVP works in a normal standalone plugin environment without new external dependencies.

## 9. Milestones

- **Milestone 1: Round-trip contract tests**
  - Add fixtures for simple Markdown, core blocks, nested blocks, unsupported blocks, front matter, whitespace, and representative media references.
  - Assert exact byte preservation for `wp -> md -> wp` and `md -> wp -> md` where content is declared supported.
  - Assert unsupported block preservation through `gutenberg` fences.
  - Make these tests the prerequisite for endpoint work.

- **Milestone 2: Git action test script**
  - Add a script target, for example `bin/test-wp-origin-git-actions.sh`, that can run clone, pull, edit, commit, push, and verification against a local test site.
  - Make the script fail loudly on changed bytes, missing files, unexpected WordPress content, auth failures, or stale push behavior.
  - Keep the script usable by agents as an end-to-end acceptance harness.

- **Milestone 3: Endpoint and authentication spike**
  - Plugin boots.
  - REST route exposes `/wp-json/git/v1/md.git`.
  - Git endpoint accepts discovery/fetch requests in a local test environment.
  - HTTP Basic Auth with application passwords works.

- **Milestone 4: Pull posts and pages as Markdown**
  - Posts and pages export into predictable `post/` and `page/` Markdown paths.
  - Metadata front matter is included.
  - `git clone` and `git pull` work for the happy path.

- **Milestone 5: Push Markdown into WordPress**
  - Existing posts can be updated.
  - New posts/pages can be created.
  - Deleted files move content to trash.
  - WordPress revisions are created.

- **Milestone 6: Safety and conflicts**
  - Stale pushes are rejected.
  - Lossy conversions fail closed.
  - Errors are understandable from the Git client.

- **Milestone 7: Media and block-theme exploration**
  - Export referenced media into relative Markdown paths.
  - Import safe new/changed media as attachments.
  - Prototype read-only export of `wp_template`, `wp_template_part`, and `wp_navigation`.

- **Milestone 8: MVP hardening**
  - Document setup and supported workflows.
  - Prepare a plugin zip or deployment path.

## 10. Implementation plan

### 10.1 Start with a narrow, testable MVP

- Treat WordPress as the source of truth.
- Expose only Markdown content, not PHP code, themes, uploads, or database dumps.
- Support posts and pages first.
- Preserve data over convenience: reject unsafe pushes rather than applying partial or lossy changes.
- Frontload tests for the Markdown and block conversion components before building the Git endpoint.
- Make every implementation step start with a failing fixture or smoke-test expectation so agents can keep moving independently.

### 10.2 Stabilize Markdown round-tripping first

- Add component-level fixtures for both directions:
  - `wp -> md -> wp`
  - `md -> wp -> md`
- Compare exact bytes for supported content, including whitespace, newlines, attribute ordering, escaped characters, and fenced code block contents.
- Add fixtures for regular Markdown, WordPress core blocks, nested blocks, raw HTML, unsupported blocks, `gutenberg` fences, legacy `block` fences, front matter, and representative Obsidian-style Markdown.
- Add media reference fixtures before implementing full binary media transfer so URL rewriting can be stabilized early.
- Keep unsupported or risky blocks opaque inside fenced `gutenberg` code blocks until a tested lossless Markdown representation exists.
- Treat a changed fixture as a product decision, not incidental churn.

### 10.3 Build the plugin foundation

- Keep this as a docs-only proposal until the endpoint shape and content format are agreed.
- When implementation begins, add the smallest possible plugin entry point under `plugins/wp-origin/`.
- Use `/wp-json/git/v1/md.git` as the public Git URL.
- Authenticate Git HTTP clients with HTTP Basic Auth backed by WordPress application passwords.
- Add a capability check layer that separates read and write permissions.

### 10.4 Introduce a Git action smoke-test script

- Add a script target, for example `bin/test-wp-origin-git-actions.sh`, that can run against a local WordPress sandbox.
- The script should create or reset fixture content in WordPress.
- The script should clone from `/wp-json/git/v1/md.git` using an application password.
- The script should verify expected files and exact Markdown bytes after clone.
- The script should edit Markdown, commit, push, and verify exact WordPress post content.
- The script should edit the WordPress post directly, pull, and verify the local file changed as expected.
- The script should attempt a stale push and assert that it is rejected.
- The script should include media fixtures once media support exists.

### 10.5 Implement pull

- Query posts and pages through WordPress APIs.
- Convert each entity into a Markdown file:
  - `post/{slug}.md`
  - `page/{slug}.md`
- Add front matter with the smallest useful metadata:
  - `id`
  - `type`
  - `slug`
  - `status`
  - `title`
  - `date_gmt`
  - `modified_gmt`
- Use existing php-toolkit Markdown/Data Liberation conversion code where possible.
- Use the Git component to serve a generated repository snapshot.
- Verify with `git clone` and `git pull` against a local WordPress site.

### 10.6 Implement push

- Accept `git receive-pack` requests from a local clone.
- Read the pushed tree and map changed files back to WordPress entities.
- Match existing content by front matter ID first, then by content type and slug/path.
- Update existing posts with `wp_update_post()`.
- Create new posts with `wp_insert_post()`.
- Move deleted files to trash with `wp_trash_post()` instead of permanent deletion.
- Create WordPress revisions through normal post update behavior.
- Apply changes transactionally where practical; if any file fails validation, reject before mutating content.

### 10.7 Add data-loss protections

- Track the WordPress `modified_gmt` value exported into each Markdown file.
- Reject pushes where the live post has changed since the exported value.
- Preserve unsupported blocks as fenced `gutenberg` payloads or inline HTML.
- Reject files that would drop required metadata for an existing post.
- Reject path traversal, unsupported directories, binary files, and unexpected extensions.
- Reject round-trip changes that are not covered by an explicit fixture update.
- Keep private/draft content behind WordPress permission checks.

### 10.8 Add media support

- Start with media referenced by posts/pages, not the whole media library.
- Export attachments into a stable `attachment/` directory.
- Rewrite image URLs to relative paths in Markdown.
- Store enough metadata to map a file back to its attachment ID and source URL.
- Hash binary files to detect unchanged media and avoid reuploads.
- Add binary-safe tests that compare exported and imported media hashes.

### 10.9 Explore block theme entities

- Add read-only fixtures for `wp_template`, `wp_template_part`, and `wp_navigation`.
- Keep them in explicit directories that mirror post type names: `wp_template/`, `wp_template_part/`, and `wp_navigation/`.
- Preserve block markup exactly until there is a safe higher-level Markdown representation.
- Decide later whether block theme entities belong in the same `md.git` remote or a separate remote/branch.

### 10.10 Keep TDD useful for independent agents

- Each task should include a failing test name, target fixture, and expected behavior before implementation begins.
- Prefer small fixtures that isolate one rule: front matter, slug mapping, unsupported block preservation, media hash preservation, stale push rejection, and so on.
- Add golden-file tests for content formats and smoke tests for Git workflows.
- When a model changes behavior, it should update the fixture and add a short reason in the test name or fixture comment.
- Keep a "known unsupported" fixture set so agents can add tests for future work without making the current MVP fail.

### 10.11 Prepare the first demo

- Document local setup and example commands:
  - `git clone https://example.com/wp-json/git/v1/md.git`
  - `git remote add wp https://example.com/wp-json/git/v1/md.git`
  - edit `post/hello-world.md`
  - `git commit -am "Update hello world"`
  - `git push wp trunk`
- Show WP-Admin edits flowing back through `git pull`.
- Show a rejected stale push and the recovery path.
- Show media references if the media milestone lands before the demo.
- Keep broader custom post types and WordPress.com transport as explicit follow-up work.

## 11. User stories

### 11.1 Clone content as Markdown

- **ID**: US-001
- **Description**: As a content editor, I want to clone WordPress posts and pages as Markdown so that I can work with them locally.
- **Acceptance criteria**:
  - The user can authenticate with a Git client using HTTP Basic Auth and a WordPress application password.
  - The clone contains Markdown files for supported posts and pages.
  - Files are organized in predictable content-type directories.
  - Files include metadata needed for safe round-trips.
  - The site can be added using any local remote name, such as `wp` or `origin`.

### 11.2 Pull the latest WordPress edits

- **ID**: US-002
- **Description**: As a coding agent, I want to pull the latest WordPress content so that I work from the current source of truth.
- **Acceptance criteria**:
  - Pull reflects changes made in WP-Admin since the last clone or pull.
  - The local working tree updates using standard Git behavior.
  - Deleted or trashed content is represented consistently.

### 11.3 Push an edit to an existing post

- **ID**: US-003
- **Description**: As a user, I want to edit a Markdown file and push it so that the matching WordPress post is updated.
- **Acceptance criteria**:
  - The pushed file maps to the correct existing post.
  - The post title, slug, status, and content update according to supported metadata.
  - A WordPress revision is created.
  - The push does not modify unrelated content.

### 11.4 Create content from a new Markdown file

- **ID**: US-004
- **Description**: As a user, I want to add a Markdown file and push it so that WordPress creates a new post or page.
- **Acceptance criteria**:
  - A new file under `post/` creates a post.
  - A new file under `page/` creates a page.
  - Missing metadata receives safe WordPress defaults.
  - The response gives the Git client a successful push result when creation succeeds.

### 11.5 Delete content safely

- **ID**: US-005
- **Description**: As a user, I want deleting a Markdown file to remove it from the Git view without accidentally destroying WordPress content permanently.
- **Acceptance criteria**:
  - A deleted Markdown file moves the matching WordPress post to trash by default.
  - Permanently deleted content is not required for the MVP.
  - The push is rejected if the matching content cannot be identified safely.

### 11.6 Preserve complex blocks

- **ID**: US-006
- **Description**: As a site owner, I want complex WordPress blocks to survive Markdown round-trips so that using Git does not break my site.
- **Acceptance criteria**:
  - Supported blocks convert to Markdown.
  - Unsupported blocks are preserved as fenced `gutenberg` payloads, HTML, or block markup.
  - Legacy fenced `block` payloads are accepted on import.
  - A push is rejected if conversion would silently drop content.

### 11.7 Prevent unauthorized access

- **ID**: US-007
- **Description**: As a site owner, I want only authorized users to read or edit content through Git so that private content stays protected.
- **Acceptance criteria**:
  - Unauthenticated requests are rejected.
  - Users cannot read content they could not read in WordPress.
  - Users cannot push changes they could not make in WordPress.
  - Authentication failures do not leak private content.

### 11.8 Reject stale pushes

- **ID**: US-008
- **Description**: As a content editor, I want stale pushes to be rejected so that local edits do not overwrite newer WP-Admin changes.
- **Acceptance criteria**:
  - The plugin detects when WordPress content changed after the user's last fetched version.
  - The push is rejected before applying partial updates.
  - The error tells the user to pull and resolve the conflict locally.

### 11.9 Preserve round-trip bytes

- **ID**: US-009
- **Description**: As a developer, I want exact round-trip tests so that agents can evolve the plugin without accidentally changing content serialization.
- **Acceptance criteria**:
  - Supported `wp -> md -> wp` fixtures produce identical bytes.
  - Supported `md -> wp -> md` fixtures produce identical bytes.
  - Unsupported content is preserved as an opaque `gutenberg` fence or rejected.
  - Fixture changes require an explicit expected-output update.

### 11.10 Work with Obsidian-friendly Markdown

- **ID**: US-010
- **Description**: As a user, I want cloned content to work naturally in Obsidian where possible so that my WordPress site can fit into Markdown-first writing workflows.
- **Acceptance criteria**:
  - Standard Markdown headings, links, images, lists, quotes, and code blocks remain readable in Obsidian.
  - Front matter remains valid and understandable.
  - Media references use relative paths where possible.
  - WordPress-specific payloads render as ordinary code blocks instead of hidden or editor-breaking syntax.

### 11.11 Sync referenced media

- **ID**: US-011
- **Description**: As a content editor, I want images referenced by posts and pages to move with the Markdown files so that local edits do not break media.
- **Acceptance criteria**:
  - Referenced media exports to a stable `attachment/` directory.
  - Markdown image references point to relative media paths.
  - Pushed new or changed media can become WordPress attachments when safe.
  - Binary file hashes match after round-trip.

### 11.12 Explore block theme content

- **ID**: US-012
- **Description**: As a site builder, I want block theme entities to become Git-addressable once content round-tripping is stable.
- **Acceptance criteria**:
  - `wp_template`, `wp_template_part`, and `wp_navigation` are documented as follow-up post-type targets.
  - Early support starts read-only.
  - Block markup is preserved exactly unless a tested Markdown representation exists.

## 12. Later-phase idea: Git hashes and WordPress revisions

WordPress post revisions are similar to Git objects because they preserve historical snapshots of a post. They are not a complete Git object model: they do not represent a repository-wide tree, they can be pruned, they do not include every attachment or cross-post relationship, and WordPress does not naturally store parent commit metadata.

A later phase could map synthetic Git commit hashes to WordPress revision sets:

- Build a virtual tree from the current exported Markdown files.
- Hash each exported file from exact bytes.
- Map each file hash to a post revision ID, attachment ID/hash, or current post state.
- Build a synthetic commit hash from the tree hash, parent synthetic commit hash, author, timestamp, and message.
- Store the mapping in plugin metadata or a small custom table: synthetic commit hash -> file paths -> WordPress revision IDs/media hashes.
- Use that mapping to make fetch/pull more Git-like without requiring the WordPress site to store a full `.git` object graph.

This should stay out of the MVP unless the mutable virtual repository blocks normal clone/pull/push workflows.
