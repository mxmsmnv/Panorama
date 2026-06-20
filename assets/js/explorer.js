/**
 * Panorama Explorer — vanilla ES module (no jQuery).
 *
 * Browse media three ways:
 *   - by page      : grouped per owner page
 *   - by template  : grouped per template
 *   - gallery      : flat grid
 *
 * Clicking an image opens a detail drawer showing the page it's attached to and
 * its variations (sizes + total), with a one-click "clean variations" button.
 * PhotoSwipe powers the lightbox; bulk actions + CSV export are kept.
 */
import PhotoSwipeLightbox from '../libs/photoswipe/photoswipe-lightbox.esm.min.js';
import { icon } from './icons.js';

const cfg = window.ProcessWire?.config?.Panorama;
const resultsEl = document.getElementById('panorama-results');
if (cfg && resultsEl) init();

function init() {
	const labels = cfg.labels || {};
	const typeInputs = [...document.querySelectorAll('#wrap_Inputfield_media_type input')];
	const selectorInput = document.getElementById('Inputfield_pages_selector');
	const modeButtons = [...document.querySelectorAll('#Inputfield_view_mode button')];

	let rows = [];
	let isImages = true;
	let search = '';
	let mode = localStorage.getItem('panorama_mode') || cfg.defaultMode || 'gallery';
	let lightbox = null;
	const selected = new Map();
	const itemKey = d => `${d.pages_id}|${d.field}|${d.filename}`;
	const itemRef = d => ({ id: d.pages_id, field: d.field, name: d.filename });
	const currentType = () => (typeInputs.find(i => i.checked) || {}).value || 'images';

	// ---- Data -------------------------------------------------------------

	async function fetchResults() {
		selected.clear();
		resultsEl.innerHTML = loadingMarkup(labels.loading || '');
		const body = new URLSearchParams({ type: currentType(), selector: selectorInput ? selectorInput.value : '' });
		let json;
		const slowNote = setTimeout(() => setResultsLoadingNote(labels.working || ''), 5000);
		try {
			const controller = new AbortController();
			const timeout = setTimeout(() => controller.abort(), 45000);
			const res = await fetch(cfg.resultsUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body, signal: controller.signal });
			clearTimeout(timeout);
			clearTimeout(slowNote);
			json = await res.json();
		} catch (e) {
			clearTimeout(slowNote);
			resultsEl.innerHTML = '';
			resultsEl.appendChild(el('div', { class: 'uk-alert uk-alert-danger', text: e.name === 'AbortError' ? (labels.timeout || '') : e.message }));
			return;
		}
		if (json.error) {
			resultsEl.textContent = '';
			resultsEl.appendChild(el('div', { class: 'uk-placeholder uk-text-center panorama-empty', text: json.error }));
			return;
		}
		rows = json.rows || [];
		isImages = json.is_images;
		renderShell();
		render();
		initLightbox();
	}

	function filtered() {
		if (!search) return rows;
		const q = search.toLowerCase();
		return rows.filter(r => {
			const tokens = [
				r.filename,
				r.title,
				r.field,
				r.template,
				humanBytes(r.filesize),
				String(r.filesize || ''),
			];
			if (r.width && r.height) {
				tokens.push(`${r.width}×${r.height}`, `${r.width}x${r.height}`, String(r.width), String(r.height));
			}
			return tokens.join(' ').toLowerCase().includes(q);
		});
	}

	// ---- Shell ------------------------------------------------------------

	function renderShell() {
		resultsEl.textContent = '';
		const toolbar = el('div', { class: 'panorama-workspace-head' });
		const summary = el('div', { class: 'panorama-workspace-summary' });
		summary.appendChild(el('span', { class: 'panorama-workspace-kicker', text: modeLabel() }));
		summary.appendChild(el('strong', { class: 'panorama-workspace-title', text: currentType() === 'images' ? (labels.images || '') : (labels.files || '') }));
		summary.appendChild(el('span', { class: 'uk-text-meta panorama-count' }));
		toolbar.appendChild(summary);
		const searchWrap = el('div', { class: 'uk-search uk-search-default panorama-search' });
		searchWrap.appendChild(el('span', { 'uk-search-icon': '' }));
		const searchInput = el('input', { type: 'search', class: 'uk-search-input', placeholder: labels.search || '', value: search });
		searchInput.addEventListener('input', () => { search = searchInput.value; renderView(); });
		searchWrap.appendChild(searchInput);
		toolbar.appendChild(searchWrap);
		toolbar.appendChild(el('span', { class: 'uk-text-meta panorama-count' }));
		resultsEl.appendChild(toolbar);
		resultsEl.appendChild(buildBulkBar());
		resultsEl.appendChild(el('div', { class: 'panorama-view' }));
		updateBulkBar();
	}

	function render() { renderView(); }

	function modeLabel() {
		if (mode === 'page') return labels.byPage || '';
		if (mode === 'template') return labels.byTemplate || '';
		return labels.gallery || '';
	}

	function renderView() {
		const container = resultsEl.querySelector('.panorama-view');
		if (!container) return;
		container.textContent = '';
		const data = filtered();
		const count = resultsEl.querySelector('.panorama-count');
		if (count) count.textContent = search ? `${data.length} / ${rows.length} ${labels.items || ''}` : `${data.length} ${labels.items || ''}`;
		const kicker = resultsEl.querySelector('.panorama-workspace-kicker');
		if (kicker) kicker.textContent = modeLabel();

		if (!data.length) { container.appendChild(el('div', { class: 'uk-placeholder uk-text-center panorama-empty', text: labels.noMedia || '' })); return; }

		if (mode === 'page') renderGroups(container, data, r => ({ key: r.owner_id || r.pages_id, label: r.title || ('#' + (r.owner_id || r.pages_id)), sub: r.template, edit: r.edit_url }));
		else if (mode === 'template') renderGroups(container, data, r => ({ key: r.template || '—', label: r.template || '—', sub: '', edit: '' }));
		else renderGallery(container, data);
		updateBulkBar();
	}

	function renderGroups(container, data, keyer) {
		const groups = new Map();
		data.forEach(r => {
			const g = keyer(r);
			if (!groups.has(g.key)) groups.set(g.key, { meta: g, items: [] });
			groups.get(g.key).items.push(r);
		});
		[...groups.values()].forEach(g => {
			const section = el('div', { class: 'panorama-group' });
			const head = el('div', { class: 'uk-card uk-card-default uk-card-small uk-card-body panorama-group-head' });
			const title = g.meta.edit
				? el('a', { class: 'panorama-group-title', href: g.meta.edit, text: g.meta.label })
				: el('span', { class: 'panorama-group-title', text: g.meta.label });
			head.appendChild(title);
			if (g.meta.sub) head.appendChild(el('span', { class: 'uk-text-meta panorama-group-sub', text: g.meta.sub }));
			head.appendChild(el('span', { class: 'uk-badge panorama-group-count', text: `${g.items.length}` }));
			section.appendChild(head);
			const grid = el('div', { class: 'panorama-gallery' });
			g.items.forEach(r => grid.appendChild(mediaCard(r)));
			section.appendChild(grid);
			container.appendChild(section);
		});
	}

	function renderGallery(container, data) {
		const grid = el('div', { class: 'panorama-gallery' });
		data.forEach(r => grid.appendChild(mediaCard(r)));
		container.appendChild(grid);
	}

	// ---- Cards ------------------------------------------------------------

	function mediaCard(data) {
		const card = el('div', { class: 'panorama-card' });
		const fig = el('div', { class: 'panorama-card-fig' });

		// Thumbnail — clicking opens the detail drawer (shows the owner page)
		const thumb = el('div', { class: 'panorama-card-thumb' });
		if (isImages) {
			thumb.appendChild(el('img', { src: data.thumb_url, alt: '', loading: 'lazy' }));
		} else {
			thumb.appendChild(el('span', { class: 'panorama-card-icon', html: icon('file') }));
		}
		thumb.addEventListener('click', () => openDetail(data));
		fig.appendChild(thumb);

		// Zoom (PhotoSwipe) for raster images with known dimensions
		if (isImages && data.width && data.height) {
			const zoom = el('a', { class: 'panorama-card-icon-btn panorama-pswp', href: data.image_url, html: icon('search') });
			zoom.setAttribute('data-pswp-width', data.width);
			zoom.setAttribute('data-pswp-height', data.height);
			fig.appendChild(zoom);
		}

		// Variation badge
		if (data.variations && data.variations.count) {
			fig.appendChild(el('span', {
				class: 'panorama-card-badge',
				title: labels.variations + ': ' + humanBytes(data.variations.bytes),
				html: `${icon('duplicates')} ${data.variations.count}`,
			}));
		}

		// Selection checkbox
		fig.appendChild(selectBox(data));

		card.appendChild(fig);
		card.appendChild(el('div', { class: 'panorama-card-name', text: data.filename, title: data.filename }));
		const meta = el('div', { class: 'panorama-card-meta' });
		meta.appendChild(el('span', { class: 'panorama-card-meta-item', text: humanBytes(data.filesize) }));
		if (isImages && data.width) meta.appendChild(el('span', { class: 'panorama-card-meta-item', text: `${data.width}×${data.height}` }));
		card.appendChild(meta);
		return card;
	}

	// ---- Detail drawer ----------------------------------------------------

	function openDetail(data) {
		closeDetail();
		const drawer = el('div', { class: 'panorama-drawer' });
		const close = el('button', { type: 'button', class: 'panorama-drawer-close', html: icon('close') });
		close.addEventListener('click', closeDetail);
		drawer.appendChild(close);

		if (isImages) {
			const a = el('a', { class: 'panorama-drawer-img', href: data.image_url, target: '_blank' });
			a.appendChild(el('img', { src: data.thumb_url, alt: '' }));
			drawer.appendChild(a);
		}
		drawer.appendChild(el('h3', { class: 'panorama-drawer-name', text: data.filename }));

		const dl = el('dl', { class: 'panorama-drawer-dl' });
		addRow(dl, labels.attachedTo || '', linkEl(data.edit_url, (data.prefix || '') + data.title));
		if (data.template) addRow(dl, labels.template || '', document.createTextNode(data.template));
		addRow(dl, labels.field || '', document.createTextNode(data.field));
		if (isImages && data.width) addRow(dl, labels.width + ' × ' + labels.height, document.createTextNode(`${data.width} × ${data.height}`));
		addRow(dl, labels.filesize || '', document.createTextNode(humanBytes(data.filesize)));
		drawer.appendChild(dl);

		// Variations
		const varBox = el('div', { class: 'panorama-drawer-vars' });
		varBox.appendChild(el('h4', { text: labels.variations || '' }));
		const varList = el('div', { class: 'panorama-var-list', text: labels.loading || '' });
		varBox.appendChild(varList);
		drawer.appendChild(varBox);

		const actions = el('div', { class: 'panorama-drawer-actions' });
		actions.appendChild(linkBtn(data.edit_url, `${icon('edit')} ${labels.edit || ''}`));
		const cleanBtn = el('button', { type: 'button', class: 'uk-button uk-button-danger panorama-btn', html: `${icon('delete')} ${labels.cleanVariations || ''}`, style: 'display:none' });
		actions.appendChild(cleanBtn);
		drawer.appendChild(actions);

		(resultsEl.closest('.ProcessPanorama') || document.body).appendChild(drawer);
		drawerEl = drawer;

		// Load variation detail
		loadVariations(data).then(info => {
			varList.textContent = '';
			if (!info.variations.length) {
				varList.appendChild(el('p', { class: 'panorama-empty', text: labels.noVariations || '' }));
				return;
			}
			const ul = el('ul', { class: 'panorama-var-ul' });
			info.variations.forEach(v => {
				ul.appendChild(el('li', { text: `${v.width}×${v.height} — ${humanBytes(v.bytes)}` }));
			});
			varList.appendChild(ul);
			varList.appendChild(el('p', { class: 'panorama-note', text: `${info.variations.length} · ${humanBytes(info.bytes)}` }));
			cleanBtn.style.display = '';
			cleanBtn.addEventListener('click', async () => {
				if (!confirm(labels.cleanConfirm || '')) return;
				await runBulk('variations', [itemRef(data)]);
				openDetail(data); // reload drawer
				fetchBadgeRefresh(data);
			});
		});
	}

	let drawerEl = null;
	function closeDetail() { if (drawerEl) { drawerEl.remove(); drawerEl = null; } }

	async function loadVariations(data) {
		const params = new URLSearchParams({ id: data.pages_id, name: data.filename });
		try {
			const controller = new AbortController();
			const timeout = setTimeout(() => controller.abort(), 30000);
			const res = await fetch(cfg.variationsUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
			clearTimeout(timeout);
			return await res.json();
		} catch (e) { return { variations: [], bytes: 0 }; }
	}

	function fetchBadgeRefresh() { fetchResults(); }

	function addRow(dl, term, valueNode) {
		dl.appendChild(el('dt', { text: term }));
		const dd = el('dd');
		dd.appendChild(valueNode);
		dl.appendChild(dd);
	}
	function linkEl(href, text) { return el('a', { href, text }); }
	function linkBtn(href, html) { const a = el('a', { class: 'uk-button uk-button-default panorama-btn', href, html }); return a; }

	// ---- Bulk -------------------------------------------------------------

	function buildBulkBar() {
		const bar = el('div', { class: 'uk-card uk-card-default uk-card-small uk-card-body panorama-bulk', style: 'display:none' });
		bar.appendChild(el('span', { class: 'panorama-bulk-count' }));
		const actions = [
			['delete', `${icon('delete')} ${labels.delete || ''}`, 'uk-button-danger'],
			['variations', `${icon('refresh')} ${labels.regen || ''}`, 'uk-button-default'],
			['tag-add', `${icon('tag')} ${labels.tagAdd || ''}`, 'uk-button-default'],
			['tag-remove', `${icon('tag')} ${labels.tagRemove || ''}`, 'uk-button-default'],
			['zip', `${icon('download')} ZIP`, 'uk-button-default'],
		];
		actions.forEach(([action, html, cls]) => {
			const b = el('button', { type: 'button', class: 'uk-button ' + cls + ' panorama-btn', html });
			b.addEventListener('click', () => bulkAction(action));
			bar.appendChild(b);
		});
		const clear = el('button', { type: 'button', class: 'panorama-bulk-clear', html: icon('close') });
		clear.addEventListener('click', () => { selected.clear(); renderView(); });
		bar.appendChild(clear);
		return bar;
	}

	function updateBulkBar() {
		const bar = resultsEl.querySelector('.panorama-bulk');
		if (!bar) return;
		bar.style.display = selected.size ? 'flex' : 'none';
		const c = bar.querySelector('.panorama-bulk-count');
		if (c) c.textContent = `${selected.size} ${labels.selected || 'selected'}`;
		bar.setAttribute('aria-live', selected.size ? 'polite' : 'off');
	}

	function selectBox(data) {
		const cb = el('input', { type: 'checkbox', class: 'panorama-select' });
		cb.checked = selected.has(itemKey(data));
		cb.addEventListener('click', e => e.stopPropagation());
		cb.addEventListener('change', () => {
			if (cb.checked) selected.set(itemKey(data), itemRef(data));
			else selected.delete(itemKey(data));
			updateBulkBar();
		});
		return cb;
	}

	async function bulkAction(action) {
		const items = [...selected.values()];
		if (!items.length) return;
		if (action === 'zip') { submitZip(items); return; }
		let value = '';
		if (action === 'tag-add' || action === 'tag-remove') {
			value = prompt(labels.tagPrompt || '') || '';
			if (!value) return;
		}
		if (action === 'delete' && !confirm(labels.deleteConfirm || '')) return;
		await runBulk(action, items, value);
		fetchResults();
	}

	async function runBulk(action, items, value) {
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('items', JSON.stringify(items));
		if (value) body.set('value', value);
		body.set(cfg.token.name, cfg.token.value);
		try {
			const res = await fetch(cfg.bulkUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body });
			const json = await res.json();
			if (!json.success) alert(json.message || labels.error || '');
			else if (json.skipped) alert(labels.skippedItems || '');
			return json;
		} catch (e) { alert(e.message); }
	}

	function submitZip(items) {
		const f = el('form', { method: 'POST', action: cfg.bulkUrl });
		f.appendChild(el('input', { type: 'hidden', name: 'action', value: 'zip' }));
		const it = el('input', { type: 'hidden', name: 'items' }); it.value = JSON.stringify(items); f.appendChild(it);
		f.appendChild(el('input', { type: 'hidden', name: cfg.token.name, value: cfg.token.value }));
		document.body.appendChild(f); f.submit(); f.remove();
	}

	function loadingMarkup(text) {
		return `
			<div class="uk-card uk-card-default uk-card-small uk-card-body panorama-panel panorama-loading">
				<div class="panorama-loading-head"><span uk-spinner="ratio: 0.7"></span><div><strong>${escapeHtml(text)}</strong><span class="uk-text-meta" data-panorama-loading-note>${escapeHtml(labels.preparing || '')}</span></div></div>
				<div class="panorama-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
			</div>`;
	}

	function setResultsLoadingNote(text) {
		const note = resultsEl.querySelector('[data-panorama-loading-note]');
		if (note) note.textContent = text;
	}

	// ---- PhotoSwipe -------------------------------------------------------

	function initLightbox() {
		if (lightbox) return;
		lightbox = new PhotoSwipeLightbox({
			gallery: '#panorama-results',
			children: 'a.panorama-pswp',
			pswpModule: () => import('../libs/photoswipe/photoswipe.esm.min.js'),
		});
		lightbox.init();
	}

	// ---- Controls ---------------------------------------------------------

	function syncModeButtons() {
		modeButtons.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
	}
	function syncTypeChoices() {
		typeInputs.forEach(input => {
			const label = input.closest('.panorama-choice');
			if (label) label.classList.toggle('is-active', input.checked);
		});
	}
	modeButtons.forEach(btn => {
		btn.addEventListener('click', () => {
			mode = btn.dataset.mode;
			localStorage.setItem('panorama_mode', mode);
			syncModeButtons();
			renderView();
		});
	});
	syncModeButtons();

	typeInputs.forEach(i => i.addEventListener('change', () => {
		syncTypeChoices();
		fetchResults();
	}));
	syncTypeChoices();

	if (selectorInput) {
		let allow = true, debounce;
		selectorInput.addEventListener('change', () => { if (allow) fetchResults(); });
		selectorInput.addEventListener('input', () => {
			allow = false;
			clearTimeout(debounce);
			debounce = setTimeout(() => { allow = true; fetchResults(); }, 1000);
		});
	}

	document.querySelectorAll('.panorama-export-link').forEach(link => {
		link.addEventListener('click', e => {
			e.preventDefault();
			const params = new URLSearchParams({ type: currentType(), selector: selectorInput ? selectorInput.value : '' });
			window.location.href = cfg.exportUrl + '?' + params.toString();
		});
	});

	document.addEventListener('keyup', e => { if (e.key === 'Escape') closeDetail(); });

	fetchResults();

	// ---- Helpers ----------------------------------------------------------

	function humanBytes(bytes) {
		bytes = Number(bytes) || 0;
		if (bytes <= 0) return '0 B';
		const units = ['B', 'KB', 'MB', 'GB', 'TB'];
		const p = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
		const val = bytes / Math.pow(1024, p);
		return (p === 0 || val >= 100 ? Math.round(val) : val.toFixed(1)) + ' ' + units[p];
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, char => ({
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;',
		}[char]));
	}

	function el(tag, props = {}) {
		const node = document.createElement(tag);
		for (const [k, v] of Object.entries(props)) {
			if (k === 'text') node.textContent = v;
			else if (k === 'html') node.innerHTML = v;
			else if (k === 'class') node.className = v;
			else if (k === 'for') node.htmlFor = v;
			else if (k in node && k !== 'style' && k !== 'href' && k !== 'src' && k !== 'value') node[k] = v;
			else node.setAttribute(k, v);
		}
		if (props.value !== undefined) node.value = props.value;
		if (props.src !== undefined) node.setAttribute('src', props.src);
		if (props.href !== undefined) node.setAttribute('href', props.href);
		return node;
	}
}
