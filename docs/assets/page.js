// Per-page wiring: blueprint, editable snippets, sticky TOC.
//
// Pages declare their h2/h3 structure plainly; this script:
//   1. Fills the shared <script id="toolkit-setup"> blueprint with a runtime-resolved
//      absolute URL to docs/assets/php-toolkit.zip (must be absolute since the
//      Playground iframe is cross-origin).
//   2. Builds a sticky table of contents from <h2>/<h3> headings inside .content.
//   3. Patches each <php-snippet>'s shadow DOM to make the rendered code editable
//      and to reflect the user's edits back into the runner. This is a stopgap
//      until <php-snippet> ships an `editable` attribute.

(function () {
	'use strict';

	// ---------- Blueprint URL injection ----------
	const setup = document.getElementById('toolkit-setup');
	if (setup) {
		const zipUrl = new URL('../assets/php-toolkit.zip', location.href).toString();
		setup.textContent = JSON.stringify({
			steps: [
				{
					step: 'unzip',
					zipFile: { resource: 'url', url: zipUrl },
					extractToPath: '/wordpress/wp-content/php-toolkit',
				},
			],
		});
	}

	// ---------- Table of contents ----------
	function buildToc() {
		const container = document.querySelector('.toc');
		const content = document.querySelector('.content');
		if (!container || !content) return;

		const headings = content.querySelectorAll('h2[id], h2, h3[id], h3');
		if (!headings.length) {
			container.remove();
			return;
		}

		const list = document.createElement('ol');
		const links = [];
		headings.forEach(function (h) {
			if (!h.id) {
				h.id = h.textContent
					.toLowerCase()
					.replace(/[^\w\s-]/g, '')
					.trim()
					.replace(/\s+/g, '-');
			}
			const li = document.createElement('li');
			li.className = 'toc-depth-' + h.tagName.substring(1);
			const a = document.createElement('a');
			a.href = '#' + h.id;
			a.textContent = h.textContent;
			li.appendChild(a);
			list.appendChild(li);
			links.push({ a, target: h });
		});

		const title = document.createElement('p');
		title.className = 'toc-title';
		title.textContent = 'On this page';
		container.replaceChildren(title, list);

		// Active-section tracking via IntersectionObserver.
		const byId = new Map();
		links.forEach(function (l) { byId.set(l.target.id, l.a); });

		const observer = new IntersectionObserver(
			function (entries) {
				const visible = entries
					.filter(function (e) { return e.isIntersecting; })
					.sort(function (a, b) { return a.target.offsetTop - b.target.offsetTop; });
				if (!visible.length) return;
				links.forEach(function (l) { l.a.classList.remove('active'); });
				const a = byId.get(visible[0].target.id);
				if (a) a.classList.add('active');
			},
			{ rootMargin: '-80px 0px -70% 0px', threshold: 0 }
		);
		links.forEach(function (l) { observer.observe(l.target); });
	}

	// ---------- Editable snippet shim ----------
	// <php-snippet> renders into shadow DOM:
	//   <pre><code>...highlighted PHP...</code></pre>
	// We swap the <code> for a contentEditable element and patch _code on input.
	function patchSnippet(el) {
		if (el._editablePatched) return;
		const root = el.shadowRoot;
		if (!root) {
			// Wait until the component renders.
			const obs = new MutationObserver(function () {
				if (el.shadowRoot && el.shadowRoot.querySelector('pre code')) {
					obs.disconnect();
					patchSnippet(el);
				}
			});
			obs.observe(el, { attributes: true, childList: true, subtree: true });
			return;
		}
		const code = root.querySelector('pre code');
		if (!code) return;

		// HTML emitted by build-reference.py escapes "</script" inside the
		// <script type="application/x-php"> wrapper as "<\/script" so the
		// outer tag isn't closed prematurely. The browser preserves that
		// backslash in textContent, so the component's internal _code field
		// contains "<\/script" and PHP runs with a literal backslash where
		// the snippet author wrote a real close tag (breaking
		// WP_HTML_Tag_Processor among other things). Reverse the escape so
		// the runtime sees what the author wrote.
		if (typeof el._code === 'string' && el._code.indexOf('<\\/script') !== -1) {
			el._code = el._code.replace(/<\\\/script/g, '</script');
			// Refresh the highlighted view so the on-screen code matches.
			if (typeof el._render === 'function') {
				try { el._render(); } catch (e) {}
			}
		}

		if (el.getAttribute('runnable') === 'false') {
			root.querySelectorAll('button').forEach(function (button) {
				if (/run/i.test(button.textContent || button.getAttribute('aria-label') || '')) {
					button.style.display = 'none';
				}
			});
			el.setAttribute('data-static-snippet', '');
			el._editablePatched = true;
			return;
		}

		code.setAttribute('contenteditable', 'plaintext-only');
		code.setAttribute('spellcheck', 'false');
		code.style.outline = 'none';
		code.style.caretColor = 'currentColor';
		el.setAttribute('data-editable', '');

		// On every edit, push the plain text back into the component's internal
		// _code field so client.run({ code }) sees the new value. The component's
		// highlightPhp() output stays as-is until the user reloads — that's fine
		// for an editing session and the highlighter resyncs on next render.
		code.addEventListener('input', function () {
			el._code = code.textContent.trim();
		});

		// Re-highlight on blur so the colors track the user's edits.
		code.addEventListener('blur', function () {
			if (typeof el._render === 'function') {
				const previous = el._code;
				el._code = code.textContent.trim();
				// Avoid clobbering output panel state — only the code area is reset.
				const output = root.querySelector('.output');
				const wasVisible = output && output.classList.contains('visible');
				const outputBody = root.querySelector('.output-body');
				const previousOutput = outputBody ? outputBody.textContent : '';
				el._render();
				if (wasVisible) {
					root.querySelector('.output').classList.add('visible');
					root.querySelector('.output-body').textContent = previousOutput;
				}
				// Re-patch the freshly rendered code element.
				el._editablePatched = false;
				patchSnippet(el);
				el._code = previous;
			}
		});

		el._editablePatched = true;
	}

	function patchAllSnippets() {
		document.querySelectorAll('php-snippet').forEach(patchSnippet);
	}

	if (window.customElements && customElements.whenDefined) {
		customElements.whenDefined('php-snippet').then(function () {
			// Defer past the component's first render.
			requestAnimationFrame(patchAllSnippets);
			// Also catch any late-defined snippets.
			new MutationObserver(patchAllSnippets).observe(document.body, {
				childList: true,
				subtree: true,
			});
		});
	}

	// ---------- Sidebar mobile toggle ----------
	function wireTocToggle() {
		const toggle = document.querySelector('.sidebar-toggle');
		const sidebar = document.querySelector('.sidebar');
		if (!toggle || !sidebar) return;
		toggle.addEventListener('click', function () {
			sidebar.classList.toggle('open');
			toggle.setAttribute(
				'aria-expanded',
				sidebar.classList.contains('open') ? 'true' : 'false'
			);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			buildToc();
			wireTocToggle();
		});
	} else {
		buildToc();
		wireTocToggle();
	}
})();
