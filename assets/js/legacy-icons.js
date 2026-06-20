import { icon } from './icons.js';

const aliases = {
	download: 'download',
	'file-o': 'file',
	eye: 'search',
	'search-plus': 'search',
	'trash-o': 'delete',
	'plus-circle': 'duplicates',
};

const ignored = new Set([
	'angle-down',
	'angle-left',
	'angle-right',
	'angle-up',
	'caret-down',
	'caret-left',
	'caret-right',
	'caret-up',
]);

function iconName(node) {
	for (const cls of node.classList) {
		if (!cls.startsWith('fa-')) continue;
		const name = cls.slice(3);
		if (name === 'fw') continue;
		if (ignored.has(name)) return '';
		return aliases[name] || name;
	}
	return 'file';
}

function replaceLegacyIcons(root = document) {
	const nodes = [];
	if (root.matches?.('.ProcessPanorama i.fa')) nodes.push(root);
	root.querySelectorAll?.('.ProcessPanorama i.fa').forEach(node => nodes.push(node));
	nodes.forEach(node => {
		const name = iconName(node);
		if (!name) {
			node.remove();
			return;
		}
		const span = document.createElement('span');
		span.innerHTML = icon(name);
		node.replaceWith(span.firstElementChild);
	});
}

replaceLegacyIcons();

const root = document.querySelector('.ProcessPanorama');
if (root) {
	new MutationObserver(records => {
		for (const record of records) {
			record.addedNodes.forEach(node => {
				if (node.nodeType === 1) replaceLegacyIcons(node);
			});
		}
	}).observe(root, { childList: true, subtree: true });
}
