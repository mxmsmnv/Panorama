<?php namespace ProcessWire;

trait PanoramaCommon {
	protected function wrap($out) {
		return "<div class='pw-wrap ProcessPanorama'>$out</div><script type='module' src='" . $this->assetUrl('assets/js/legacy-icons.js') . "'></script>";
	}

	protected function assetUrl($path) {
		$path = ltrim((string) $path, '/');
		$file = __DIR__ . '/' . $path;
		$stamp = is_file($file) ? filemtime($file) : $this->wire()->modules->getModuleInfo($this)['version'];
		return $this->wire()->config->urls->$this . $path . '?v=' . rawurlencode((string) $stamp);
	}

	protected function safeBasename($name) {
		$name = str_replace('\\', '/', (string) $name);
		$name = basename($name);
		return $this->wire()->sanitizer->filename($name);
	}

	protected function normalizeIcon($icon, $fallback = 'file-o') {
		$icon = trim((string) $icon);
		if($icon === '') return $fallback;
		$parts = preg_split('/\s+/', $icon);
		$icon = end($parts);
		$legacyPrefix = chr(102) . chr(97) . '-';
		if(strpos($icon, $legacyPrefix) === 0) $icon = substr($icon, strlen($legacyPrefix));
		$icon = preg_replace('/[^a-zA-Z0-9-]/', '', $icon);
		return $icon !== '' ? $icon : $fallback;
	}

	protected function asyncPlaceholder($id, $label, $url = '') {
		$sanitizer = $this->wire()->sanitizer;
		$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id);
		if($id === '') $id = 'panorama-async';
		$label = $sanitizer->entities((string) $label);
		$urlAttr = $url !== '' ? " data-panorama-async-url='" . $sanitizer->entities1((string) $url) . "'" : '';
		$working = $sanitizer->entities1($this->_('Still working in the background…'));
		$error = $sanitizer->entities1($this->_('Could not load data.'));
		$timeout = $sanitizer->entities1($this->_('Request timed out.'));
		return "<div id='$id' class='panorama-async-panel'$urlAttr data-panorama-label-working='$working' data-panorama-label-error='$error' data-panorama-label-timeout='$timeout'>"
			. "<div class='uk-card uk-card-default uk-card-small uk-card-body panorama-panel panorama-loading'>"
			. "<div class='panorama-loading-head'><span uk-spinner='ratio: 0.7'></span><div><strong>$label</strong><span class='uk-text-meta' data-panorama-loading-note>" . $this->_('Preparing data in the background.') . "</span></div></div>"
			. "<div class='panorama-skeleton' aria-hidden='true'><span></span><span></span><span></span></div>"
			. "</div></div>";
	}

	protected function panelHeader($title, $icon) {
		return "<div class='uk-card uk-card-default uk-card-small uk-card-body panorama-panel'><h3 class='uk-card-title panorama-panel-title'>" . $this->icon($icon) . " $title</h3>";
	}

	protected function emptyNote() {
		return "<div class='uk-placeholder uk-text-center panorama-empty'>" . $this->_('No media found.') . "</div>";
	}

	/**
	 * Format a byte count as a human-readable string
	 */
	protected function formatBytes($bytes) {
		$bytes = (int) $bytes;
		if($bytes <= 0) return '0 B';
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$power = min((int) floor(log($bytes, 1024)), count($units) - 1);
		$value = $bytes / pow(1024, $power);
		$decimals = ($power === 0 || $value >= 100) ? 0 : 1;
		return number_format($value, $decimals) . ' ' . $units[$power];
	}

}
