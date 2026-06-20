const cfg = window.ProcessWire?.config?.PanoramaWarmup || {};
const form = document.getElementById('panorama-warmup-form');
const statusBox = document.querySelector('.panorama-warmup-status');
const titleEl = document.querySelector('[data-panorama-warmup-title]');
const countEl = document.querySelector('[data-panorama-warmup-count]');
const progressEl = document.querySelector('[data-panorama-warmup-progress]');
const statsEl = document.querySelector('[data-panorama-warmup-stats]');

if (form) initWarmup();

function initWarmup() {
	form.addEventListener('submit', async event => {
		event.preventDefault();
		const data = new FormData(form);
		setDisabled(true);
		setStatus(cfg.labels?.running || '', 0, 0, 0, 0, 0);
		try {
			const start = await post(cfg.startUrl, data);
			if (!start.success) throw new Error(start.message || cfg.labels?.error || '');
			if (!start.total) {
				setStatus(cfg.labels?.done || '', 0, 0, 0, 0, 0);
				setDisabled(false);
				return;
			}
			await runBatches(start.job);
		} catch (error) {
			setError(error.message || cfg.labels?.error || '');
			setDisabled(false);
		}
	});
}

async function runBatches(job) {
	let done = false;
	while (!done) {
		const body = new FormData();
		body.set('job', job);
		const json = await post(cfg.batchUrl, body);
		if (!json.success) throw new Error(json.message || cfg.labels?.error || '');
		done = !!json.done;
		setStatus(
			done ? (cfg.labels?.done || '') : (cfg.labels?.running || ''),
			json.processed || 0,
			json.total || 0,
			json.generated || 0,
			json.skipped || 0,
			json.failed || 0
		);
	}
	setDisabled(false);
}

async function post(url, body) {
	body.set(cfg.token.name, cfg.token.value);
	const controller = new AbortController();
	const timeout = setTimeout(() => controller.abort(), 120000);
	try {
		const res = await fetch(url, {
			method: 'POST',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			body,
			signal: controller.signal,
		});
		const json = await res.json();
		if (!res.ok) throw new Error(json.message || cfg.labels?.requestFailed || res.statusText);
		return json;
	} finally {
		clearTimeout(timeout);
	}
}

function setStatus(title, processed, total, generated, skipped, failed) {
	statusBox.hidden = false;
	const pct = total ? Math.round(processed / total * 100) : 100;
	titleEl.textContent = title;
	countEl.textContent = total ? `${processed} / ${total}` : '0 / 0';
	progressEl.value = pct;
	statsEl.textContent = `${cfg.labels?.generated || ''}: ${generated} · ${cfg.labels?.skipped || ''}: ${skipped} · ${cfg.labels?.failed || ''}: ${failed}`;
}

function setError(message) {
	statusBox.hidden = false;
	titleEl.textContent = message;
	countEl.textContent = '';
	progressEl.value = 0;
	statsEl.textContent = '';
}

function setDisabled(disabled) {
	form.querySelectorAll('input, select, button').forEach(el => { el.disabled = disabled; });
}
