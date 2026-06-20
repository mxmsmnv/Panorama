<?php namespace ProcessWire;

trait PanoramaAudit {
	/* ============================================================ *
	 *  AUDIT — images missing alt text (description)
	 * ============================================================ */

	/**
	 * Audit images for missing alt text (description)
	 *
	 * @return string
	 */
	public function ___executeAudit() {
		$config = $this->wire()->config;
		$input = $this->wire()->input;
		$this->headline($this->_('Audit'));
		$this->browserTitle($this->wire()->page->title . ' - ' . $this->_('Audit'));

		if($input->get('data')) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode([
				'success' => true,
				'html' => $this->renderAuditContent(),
			]);
			exit;
		}

		$config->styles->add($this->assetUrl('assets/css/Panorama.css'));
		$out = $this->renderTabs('audit');
		$out .= $this->asyncPlaceholder('panorama-audit', $this->_('Checking alt-text coverage…'), $this->base() . 'audit/?data=1');
		$out .= "<script type='module' src='" . $this->assetUrl('assets/js/async-panel.js') . "'></script>";
		return $this->wrap($out);
	}

	/**
	 * Render audit content for async loading.
	 *
	 * @return string
	 */
	protected function renderAuditContent() {
		$result = $this->buildMediaRows('images', '');
		$rows = $result['rows'] ?? [];
		$total = count($rows);
		$missing = array_values(array_filter($rows, function($r) {
			return trim((string) $r['description']) === '';
		}));
		$covered = $total - count($missing);
		$pct = $total ? round($covered / $total * 100) : 100;

		$sanitizer = $this->wire()->sanitizer;
		$out = '';
		$out .= '<div class="uk-grid-small uk-child-width-1-3@m panorama-tiles" uk-grid>';
		$out .= $this->statTile('picture-o', $this->_('Images'), number_format($total), $this->_('total'));
		$out .= $this->statTile('universal-access', $this->_('With alt text'), number_format($covered), $pct . '%');
		$out .= $this->statTile('exclamation-triangle', $this->_('Missing alt text'), number_format(count($missing)), $this->_('need a description'));
		$out .= '</div>';

		$out .= $this->panelHeader($this->_('Alt-text coverage'), 'universal-access');
		$out .= "<progress class='uk-progress panorama-progress' value='{$pct}' max='100'></progress>";
		$out .= "<p class='panorama-note'>" . sprintf($this->_('%1$d of %2$d images have a description.'), $covered, $total) . "</p></div>";

		$out .= $this->panelHeader($this->_('Images missing alt text'), 'exclamation-triangle');
		if(!$missing) {
			$out .= "<p class='panorama-empty'>" . $this->_('Every image has a description.') . "</p></div>";
			return $out;
		}
		$out .= '<ul class="panorama-gallery">';
		$display = array_slice($missing, 0, 48);
		foreach($display as $r) {
			$filename = $sanitizer->entities($r['filename']);
			$out .= "<li class='panorama-card'>"
				. "<div class='panorama-card-fig'><a class='panorama-card-thumb' href='" . $sanitizer->entities($r['image_url']) . "' target='_blank'>"
				. "<img src='" . $sanitizer->entities($r['thumb_url']) . "' alt='' loading='lazy'></a></div>"
				. "<a class='panorama-card-name' href='" . $sanitizer->entities($r['edit_url']) . "' title='$filename'>$filename</a>"
				. "<div class='panorama-card-meta'>"
				. "<span class='panorama-card-meta-item'>" . $this->formatBytes($r['filesize']) . "</span>"
				. "</div></li>";
		}
		$out .= '</ul>';
		if(count($missing) > count($display)) {
			$out .= '<p class="panorama-note">' . sprintf($this->_('Showing the first %1$d of %2$d.'), count($display), count($missing)) . '</p>';
		}
		return $out . '</div>';
	}

}
