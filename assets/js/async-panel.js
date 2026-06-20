/**
 * Generic async loader for Panorama sections that return { success, html }.
 */
document.querySelectorAll('[data-panorama-async-url]').forEach(root => {
	loadPanel(root);
});

async function loadPanel(root) {
	const note = root.querySelector('[data-panorama-loading-note]');
	const labels = {
		working: root.dataset.panoramaLabelWorking || '',
		error: root.dataset.panoramaLabelError || '',
		timeout: root.dataset.panoramaLabelTimeout || '',
	};
	const slowNote = setTimeout(() => {
		if (note) note.textContent = labels.working;
	}, 5000);
	try {
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 45000);
		const res = await fetch(root.dataset.panoramaAsyncUrl, {
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			signal: controller.signal,
		});
		clearTimeout(timeout);
		clearTimeout(slowNote);
		const json = await res.json();
		if (!json.success) throw new Error(labels.error);
		root.innerHTML = json.html || '';
		root.dispatchEvent(new CustomEvent('panorama:loaded', { bubbles: true, detail: json }));
	} catch (error) {
		clearTimeout(slowNote);
		const message = error.name === 'AbortError' ? labels.timeout : (error.message || labels.error);
		root.innerHTML = `<div class="uk-alert uk-alert-danger">${escapeHtml(message)}</div>`;
	}
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
