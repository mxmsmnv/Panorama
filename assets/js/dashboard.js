/**
 * Panorama dashboard chart.
 * The page HTML renders immediately; only media-heavy panels load separately.
 */
import Chart from '../libs/chart/chart.js';

const cfg = window.ProcessWire?.config?.Panorama?.dashboard || {};
const root = document.getElementById('panorama-dashboard');

if (root) renderChart(Array.isArray(cfg.chart) ? cfg.chart : []);

function renderChart(data) {
	const canvas = document.getElementById('panorama-chart-types');
	if (!canvas || !data.length) return;

	const styles = getComputedStyle(root);
	const accent = styles.getPropertyValue('--pw-main-color').trim() || styles.getPropertyValue('--panorama-accent').trim() || '#ff4e00';
	const palette = [accent, '#3eb998', '#e8a33d', '#5b8def', '#8d6cf0', '#e8563d', '#48b0c4', '#9aa0a6'];
	const textColor = getComputedStyle(document.body).color || '#354b60';

	new Chart(canvas, {
		type: 'doughnut',
		data: {
			labels: data.map(d => d.label),
			datasets: [{
				data: data.map(d => d.value),
				backgroundColor: palette,
				borderWidth: 0,
				hoverOffset: 0,
			}],
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			cutout: '62%',
			plugins: {
				legend: {
					position: 'right',
					labels: { color: textColor, boxWidth: 12, boxHeight: 12, padding: 12 },
				},
				tooltip: {
					callbacks: {
						label: ctx => ` ${ctx.label}: ${data[ctx.dataIndex].human}`,
					},
				},
			},
		},
	});
}
