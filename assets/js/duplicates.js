import { icon } from './icons.js';

const cfg = window.ProcessWire?.config?.Panorama?.duplicates || {};
const root = document.getElementById('panorama-duplicates');

if (root) initDuplicates();

function initDuplicates() {
	let groups = [];
	let stats = {};
	let search = '';
	let drawerEl = null;
	let visibleLimit = 24;

	const load = async (refresh = false) => {
		setLoading(refresh ? cfg.scanning : cfg.loading);
		const slowNote = setTimeout(() => setLoadingNote(cfg.working || ''), 5000);
		try {
			const controller = new AbortController();
			const timeout = setTimeout(() => controller.abort(), 120000);
			const res = await fetch(refresh ? cfg.refreshUrl : cfg.dataUrl, {
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				signal: controller.signal,
			});
			clearTimeout(timeout);
			clearTimeout(slowNote);
			const json = await res.json();
			if (!json.success) throw new Error(cfg.error || '');
			if (json.needsScan && !refresh) {
				setLoading(cfg.scanning || cfg.loading);
				load(true);
				return;
			}
			groups = Array.isArray(json.groups) ? json.groups : [];
			stats = json.stats || {};
			visibleLimit = 24;
			renderShell();
			renderView();
		} catch (error) {
			clearTimeout(slowNote);
			const message = error.name === 'AbortError' ? (cfg.timeout || cfg.error || '') : (error.message || cfg.error || '');
			root.innerHTML = `<div class="uk-alert uk-alert-danger">${escapeHtml(message)}</div>`;
		}
	};

	function renderShell() {
		root.innerHTML = '';
		root.appendChild(summaryTiles());

		const panel = el('div', { class: 'uk-card uk-card-default uk-card-small uk-card-body panorama-panel' });
		const head = el('div', { class: 'panorama-dupe-header' });
		head.appendChild(el('h3', { class: 'uk-card-title panorama-panel-title', html: `${icon('duplicates')} ${escapeHtml(cfg.duplicateFiles || '')}` }));
		const tools = el('div', { class: 'panorama-dupe-actions' });
		tools.appendChild(el('span', { class: 'uk-text-meta', text: `${cfg.lastScan || ''}: ${stats.lastScan || cfg.never || ''}` }));
		const refresh = el('button', { type: 'button', class: 'uk-button uk-button-default', html: `${icon('refresh')} ${escapeHtml(cfg.refresh || '')}` });
		refresh.addEventListener('click', () => load(true));
		tools.appendChild(refresh);
		head.appendChild(tools);
		panel.appendChild(head);

		const toolbar = el('div', { class: 'panorama-toolbar panorama-dupe-toolbar' });
		const searchWrap = el('div', { class: 'uk-search uk-search-default panorama-search' });
		searchWrap.appendChild(el('span', { 'uk-search-icon': '' }));
		const input = el('input', { type: 'search', class: 'uk-search-input', placeholder: cfg.search || '', value: search });
		input.addEventListener('input', () => {
			search = input.value;
			visibleLimit = 24;
			renderView();
		});
		searchWrap.appendChild(input);
		toolbar.appendChild(searchWrap);
		toolbar.appendChild(el('span', { class: 'uk-text-meta panorama-count' }));
		panel.appendChild(toolbar);
		panel.appendChild(el('div', { class: 'panorama-view' }));
		root.appendChild(panel);
	}

	function summaryTiles() {
		const wrap = el('div', { class: 'uk-grid-small uk-child-width-1-3@m panorama-tiles panorama-dupe-tiles', 'uk-grid': '' });
		wrap.appendChild(tile('clone', cfg.duplicateSets || '', stats.sets || 0, cfg.identicalFiles || ''));
		wrap.appendChild(tile('copy', cfg.extraCopies || '', stats.extraCopies || 0, cfg.copies || ''));
		wrap.appendChild(tile('hdd-o', cfg.reclaimableLabel || '', stats.wastedHuman || '0 B', cfg.reclaimable || ''));
		return wrap;
	}

	function renderView() {
		const container = root.querySelector('.panorama-view');
		if (!container) return;
		const data = filtered();
		const count = root.querySelector('.panorama-count');
		if (count) count.textContent = search ? `${data.length} / ${groups.length}` : `${data.length}`;
		container.textContent = '';

		if (!data.length) {
			container.appendChild(el('div', { class: 'uk-placeholder uk-text-center panorama-empty', text: cfg.noDuplicates || '' }));
			return;
		}

		const grid = el('div', { class: 'panorama-gallery panorama-dupe-gallery' });
		const visible = data.slice(0, visibleLimit);
		visible.forEach(group => grid.appendChild(duplicateCard(group)));
		container.appendChild(grid);

		if (visible.length < data.length) {
			const moreWrap = el('div', { class: 'panorama-show-more' });
			const remaining = data.length - visible.length;
			const more = el('button', {
				type: 'button',
				class: 'uk-button uk-button-default',
				text: `${cfg.showMore || ''} (${remaining})`,
			});
			more.addEventListener('click', () => {
				visibleLimit += 24;
				renderView();
			});
			moreWrap.appendChild(more);
			container.appendChild(moreWrap);
		}
	}

	function filtered() {
		if (!search) return groups;
		const q = search.toLowerCase();
		return groups.filter(group => {
			const tokens = [
				group.search,
				group.filename,
				group.bytesHuman,
				group.wastedHuman,
				String(group.bytes || ''),
				String(group.wasted || ''),
				String(group.copies || ''),
			];
			return tokens.join(' ').toLowerCase().includes(q);
		});
	}

	function duplicateCard(group) {
		const card = el('div', { class: 'panorama-card panorama-dupe-card' });
		const fig = el('div', { class: 'panorama-card-fig' });
		const thumb = el('button', { type: 'button', class: 'panorama-card-thumb panorama-dupe-thumb' });
		if (group.thumb_url) {
			thumb.appendChild(el('img', { src: group.thumb_url, alt: '', loading: 'lazy' }));
		} else {
			thumb.appendChild(el('span', { class: 'panorama-card-icon', html: icon(group.is_image ? 'image' : 'file') }));
		}
		thumb.addEventListener('click', () => openDrawer(group));
		fig.appendChild(thumb);
		fig.appendChild(el('span', { class: 'panorama-card-badge', html: `${icon('duplicates')} ${group.copies}` }));
		card.appendChild(fig);
		card.appendChild(el('div', { class: 'panorama-card-name', text: group.filename, title: group.filename }));
		const meta = el('div', { class: 'panorama-card-meta panorama-dupe-meta' });
		meta.appendChild(el('span', { class: 'panorama-card-meta-item', text: group.bytesHuman }));
		meta.appendChild(el('span', { class: 'panorama-card-meta-item', text: `${group.wastedHuman} ${cfg.reclaimable || ''}` }));
		card.appendChild(meta);
		return card;
	}

	function openDrawer(group) {
		closeDrawer();
		const drawer = el('div', { class: 'panorama-drawer panorama-dupe-drawer' });
		const close = el('button', { type: 'button', class: 'panorama-drawer-close', html: icon('close') });
		close.addEventListener('click', closeDrawer);
		drawer.appendChild(close);

		if (group.thumb_url) {
			const preview = el('a', { class: 'panorama-drawer-img', href: group.file_url, target: '_blank' });
			preview.appendChild(el('img', { src: group.thumb_url, alt: '' }));
			drawer.appendChild(preview);
		}
		drawer.appendChild(el('h3', { class: 'panorama-drawer-name', text: group.filename }));

		const dl = el('dl', { class: 'panorama-drawer-dl' });
		addRow(dl, cfg.copiesLabel || '', document.createTextNode(String(group.copies)));
		addRow(dl, cfg.sizeEach || '', document.createTextNode(group.bytesHuman));
		addRow(dl, cfg.reclaimableLabel || '', document.createTextNode(group.wastedHuman));
		drawer.appendChild(dl);

		const firstMember = group.members[0] || {};
		const actions = el('div', { class: 'panorama-drawer-actions panorama-sidebar-actions' });
		actions.appendChild(el('span', { class: 'uk-text-meta panorama-sidebar-actions-label', text: cfg.actions || '' }));
		actions.appendChild(el('a', {
			class: 'uk-button uk-button-default panorama-btn',
			href: firstMember.file_url || group.file_url,
			target: '_blank',
			html: `${icon('external')} ${escapeHtml(cfg.open || '')}`,
		}));
		if (firstMember.edit_url) {
			actions.appendChild(el('a', {
				class: 'uk-button uk-button-default panorama-btn',
				href: firstMember.edit_url,
				html: `${icon('edit')} ${escapeHtml(cfg.edit || '')}`,
			}));
		}
		drawer.appendChild(actions);

		const list = el('div', { class: 'panorama-dupe-members' });
		list.appendChild(el('h4', { text: cfg.copiesLabel || '' }));
		const ul = el('ul', { class: 'panorama-dupe-member-list' });
		group.members.forEach(member => {
			const li = el('li');
			const main = el('div', { class: 'panorama-dupe-member-main' });
			main.appendChild(member.edit_url ? el('a', { href: member.edit_url, text: member.page }) : el('span', { text: member.page }));
			main.appendChild(el('span', { class: 'uk-text-meta', text: member.filename }));
			li.appendChild(main);
			const memberActions = el('div', { class: 'panorama-dupe-member-actions' });
			if (member.edit_url) {
				memberActions.appendChild(el('a', { class: 'uk-button uk-button-default', href: member.edit_url, html: `${icon('edit')} ${escapeHtml(cfg.edit || '')}` }));
			}
			memberActions.appendChild(el('a', { class: 'uk-button uk-button-default', href: member.file_url, target: '_blank', html: `${icon('external')} ${escapeHtml(cfg.open || '')}` }));
			li.appendChild(memberActions);
			ul.appendChild(li);
		});
		list.appendChild(ul);
		drawer.appendChild(list);
		(root.closest('.ProcessPanorama') || document.body).appendChild(drawer);
		drawerEl = drawer;
	}

	function closeDrawer() {
		if (drawerEl) drawerEl.remove();
		drawerEl = null;
	}

	root.addEventListener('click', event => {
		const refresh = event.target.closest('[data-panorama-refresh-duplicates]');
		if (refresh) {
			event.preventDefault();
			load(true);
		}
	});

	document.addEventListener('keyup', event => {
		if (event.key === 'Escape') closeDrawer();
	});

	load(false);
}

function setLoading(text) {
	root.innerHTML = `
		<div class="uk-card uk-card-default uk-card-small uk-card-body panorama-panel panorama-loading">
			<div class="panorama-loading-head"><span uk-spinner="ratio: 0.7"></span><div><strong>${escapeHtml(text || cfg.loadingFallback || '')}</strong><span class="uk-text-meta" data-panorama-loading-note>${escapeHtml(cfg.preparing || '')}</span></div></div>
			<div class="panorama-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
		</div>`;
}

function setLoadingNote(text) {
	const note = root.querySelector('[data-panorama-loading-note]');
	if (note) note.textContent = text;
}

function tile(iconName, label, value, sub) {
	return el('div', {
		html: `<div class="uk-card uk-card-default uk-card-small uk-card-body panorama-tile">
			<div class="uk-flex uk-flex-between uk-flex-middle"><span class="uk-text-meta panorama-tile-label">${escapeHtml(label)}</span>${icon(iconName)}</div>
			<div class="uk-text-large uk-text-bold panorama-tile-value">${escapeHtml(value)}</div>
			<div class="uk-text-meta panorama-tile-sub">${escapeHtml(sub)}</div>
		</div>`,
	});
}

function addRow(dl, term, valueNode) {
	dl.appendChild(el('dt', { text: term }));
	const dd = el('dd');
	dd.appendChild(valueNode);
	dl.appendChild(dd);
}

function el(tag, props = {}) {
	const node = document.createElement(tag);
	for (const [key, value] of Object.entries(props)) {
		if (key === 'text') node.textContent = value;
		else if (key === 'html') node.innerHTML = value;
		else if (key === 'class') node.className = value;
		else if (key in node && key !== 'style' && key !== 'href' && key !== 'src' && key !== 'value') node[key] = value;
		else node.setAttribute(key, value);
	}
	if (props.value !== undefined) node.value = props.value;
	if (props.src !== undefined) node.setAttribute('src', props.src);
	if (props.href !== undefined) node.setAttribute('href', props.href);
	return node;
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
