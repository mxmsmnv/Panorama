<?php namespace ProcessWire;

trait PanoramaCleanup {
	/* ============================================================ *
	 *  CLEANUP — broken refs, orphaned originals, orphaned variations
	 * ============================================================ */

	/**
	 * Cleanup centre: find (and delete) broken references, orphaned originals
	 * and orphaned image variations.
	 *
	 * @return string
	 */
	public function ___executeCleanup() {
		$config = $this->wire()->config;
		$session = $this->wire()->session;
		$input = $this->wire()->input;

		$this->headline($this->_('Cleanup'));
		$this->browserTitle($this->wire()->page->title . ' - ' . $this->_('Cleanup'));

		$action = $input->post('cleanup_action');
		if($action) {
			$cleanup = $this->getCleanupData();
			if($session->CSRF->getTokenValue() === $input->post($session->CSRF->getTokenName())) {
				if($action === 'variations') {
					$this->deleteFiles($cleanup['variations']);
				} elseif($action === 'origins') {
					$this->deleteFiles($cleanup['origins']);
				} elseif($action === 'broken') {
					$this->deleteBrokenRefs($cleanup['broken']);
				}
			} else {
				$this->error($this->_('Form submission failed CSRF validation.'));
			}
			$session->location($this->base() . 'cleanup/');
		}

		if($input->get('data')) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode([
				'success' => true,
				'html' => $this->renderCleanupContent($this->getCleanupData()),
			]);
			exit;
		}

		$config->styles->add($this->assetUrl('assets/css/Panorama.css'));
		$out = $this->renderTabs('cleanup');
		$out .= $this->asyncPlaceholder('panorama-cleanup', $this->_('Scanning cleanup candidates…'), $this->base() . 'cleanup/?data=1');
		$out .= "<script type='module' src='" . $this->assetUrl('assets/js/async-panel.js') . "'></script>";
		return $this->wrap($out);
	}

	/**
	 * Build cleanup report data.
	 *
	 * @return array
	 */
	protected function getCleanupData() {
		$disk = $this->scanDisk();
		$db = $this->getDbFileIndex();

		$broken = [];
		foreach($db['rows'] as $r) {
			if(!isset($disk['diskByPage'][$r['pages_id']][$r['filename']])) $broken[] = $r;
		}

		$origins = [];
		foreach($disk['originals'] as $o) {
			if(!isset($db['byPage'][$o['page_id']][$o['filename']])) $origins[] = $o;
		}

		return [
			'broken' => $broken,
			'origins' => $origins,
			'variations' => $disk['orphanVariations'],
		];
	}

	/**
	 * Render cleanup content for async loading.
	 *
	 * @param array $data
	 * @return string
	 */
	protected function renderCleanupContent(array $data) {
		$broken = $data['broken'] ?? [];
		$origins = $data['origins'] ?? [];
		$variations = $data['variations'] ?? [];

		$refs = $this->pageRefs(array_merge(
			array_column($broken, 'pages_id'),
			array_column($origins, 'page_id'),
			array_column($variations, 'page_id')
		));

		$out = '';
		$out .= '<div class="uk-grid-small uk-child-width-1-3@m panorama-tiles" uk-grid>';
		$out .= $this->statTile('chain-broken', $this->_('Broken references'), number_format(count($broken)), $this->_('file missing on disk'));
		$out .= $this->statTile('file-o', $this->_('Orphaned originals'), number_format(count($origins)), $this->formatBytes(array_sum(array_column($origins, 'bytes'))));
		$out .= $this->statTile('clone', $this->_('Orphaned variations'), number_format(count($variations)), $this->formatBytes(array_sum(array_column($variations, 'bytes'))));
		$out .= '</div>';

		$out .= '<div class="uk-grid-small uk-child-width-1-2@m panorama-grid" uk-grid>';
		$out .= $this->renderCleanupSection(
			$this->_('Broken references'), 'chain-broken',
			$this->_('Database entries whose file is missing on disk. Deleting removes the broken entry from the page (via the API).'),
			$broken, 'broken', $refs, true
		);
		$out .= $this->renderCleanupSection(
			$this->_('Orphaned variations'), 'clone',
			$this->_('Resized/cropped variations whose original no longer exists. Safe to delete — ProcessWire regenerates variations on demand.'),
			$variations, 'variations', $refs, false
		);
		$out .= '</div>';

		$out .= '<div class="uk-grid-small uk-child-width-1-1 panorama-grid" uk-grid>';
		$out .= $this->renderCleanupGallerySection(
			$this->_('Orphaned originals'), 'file-o',
			$this->_('Files on disk that are no longer referenced by any page (e.g. the page was deleted). Deleting removes the files from disk.'),
			$origins, 'origins', $refs
		);
		$out .= '</div>';
		return $out;
	}

	/**
	 * Render one cleanup section: table of items + a delete form
	 *
	 * @param string $title
	 * @param string $icon
	 * @param string $note
	 * @param array $items
	 * @param string $action
	 * @param array $refs Page references keyed by page id
	 * @param bool $showField Whether to show the field column
	 * @return string
	 */
	protected function renderCleanupSection($title, $icon, $note, array $items, $action, array $refs, $showField) {
		$sanitizer = $this->wire()->sanitizer;
		$out = $this->panelHeader($title, $icon);
		$out .= '<p class="panorama-note">' . $note . '</p>';
		if(!$items) return $out . $this->emptyNote() . '</div>';

		$display = array_slice($items, 0, 48);
		$out .= "<div class='uk-overflow-auto'><table class='uk-table uk-table-divider uk-table-small uk-table-middle panorama-table'><thead><tr><th>" . $this->columnLabels['page'] . "</th>";
		if($showField) $out .= "<th>" . $this->columnLabels['field'] . "</th>";
		$out .= "<th>" . $this->columnLabels['filename'] . "</th><th class='panorama-num'>" . $this->columnLabels['filesize'] . "</th></tr></thead><tbody>";
		foreach($display as $it) {
			$pid = (int) ($it['pages_id'] ?? $it['page_id'] ?? 0);
			$ref = $refs[$pid] ?? null;
			$pageCell = $ref ? "<a href='{$ref['edit']}'>" . $sanitizer->entities($ref['title']) . "</a>" : ($pid ? "#$pid" : '&mdash;');
			$out .= "<tr><td>$pageCell</td>";
			if($showField) $out .= "<td>" . $sanitizer->entities($it['field'] ?? '') . "</td>";
			$out .= "<td>" . $sanitizer->entities($it['filename']) . "</td>";
			$out .= "<td class='panorama-num'>" . $this->formatBytes($it['bytes'] ?? 0) . "</td></tr>";
		}
		$out .= '</tbody></table></div>';
		if(count($items) > count($display)) {
			$out .= '<p class="panorama-note">' . sprintf($this->_('Showing the first %1$d of %2$d items. The action below applies to all of them.'), count($display), count($items)) . '</p>';
		}

		$session = $this->wire()->session;
		$tn = $session->CSRF->getTokenName();
		$tv = $session->CSRF->getTokenValue();
		$confirm = $sanitizer->entities1($this->_('Delete all listed items? This cannot be undone.'));
		$out .= "<form method='post' action='./' class='uk-margin-small-top' onsubmit=\"return confirm('$confirm');\">"
			. "<input type='hidden' name='$tn' value='$tv'>"
			. "<button type='submit' name='cleanup_action' value='$action' class='uk-button uk-button-danger'>"
			. $this->icon('delete') . ' ' . $this->_('Delete all listed') . "</button></form>";
		return $out . '</div>';
	}

	/**
	 * Render cleanup files as Explorer-style cards.
	 *
	 * @param string $title
	 * @param string $icon
	 * @param string $note
	 * @param array $items
	 * @param string $action
	 * @param array $refs Page references keyed by page id
	 * @return string
	 */
	protected function renderCleanupGallerySection($title, $icon, $note, array $items, $action, array $refs) {
		$sanitizer = $this->wire()->sanitizer;
		$assetUrl = $this->wire()->config->urls->files;
		$out = $this->panelHeader($title, $icon);
		$out .= '<p class="panorama-note">' . $note . '</p>';
		if(!$items) return $out . $this->emptyNote() . '</div>';

		$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
		$display = array_slice($items, 0, 48);
		$out .= '<ul class="panorama-gallery">';
		foreach($display as $it) {
			$pageId = (int) ($it['page_id'] ?? $it['pages_id'] ?? 0);
			$filenameRaw = (string) ($it['filename'] ?? '');
			$filename = $sanitizer->entities($filenameRaw);
			$ext = strtolower(pathinfo($filenameRaw, PATHINFO_EXTENSION));
			$isImage = in_array($ext, $imageExts, true);
			$fileUrl = $pageId ? $assetUrl . $pageId . '/' . rawurlencode($filenameRaw) : '';
			$thumbUrl = $isImage ? $this->existingDiskThumbUrl($pageId, $filenameRaw, 160) : '';
			$thumb = $thumbUrl
				? "<img src='" . $sanitizer->entities($thumbUrl) . "' alt='' loading='lazy'>"
				: "<span class='panorama-card-icon'>" . $this->icon($isImage ? 'image' : 'file') . '</span>';
			$thumbLink = $fileUrl
				? "<a class='panorama-card-thumb' href='" . $sanitizer->entities($fileUrl) . "' target='_blank'>$thumb</a>"
				: "<span class='panorama-card-thumb'>$thumb</span>";
			$ref = $refs[$pageId] ?? null;
			$pageTitle = $ref ? $sanitizer->entities($ref['title']) : ($pageId ? "#$pageId" : $this->_('Unknown page'));
			$pageLink = $ref
				? "<a class='panorama-card-meta-item panorama-card-page' href='{$ref['edit']}'>" . $this->icon('edit') . " $pageTitle</a>"
				: "<span class='panorama-card-meta-item'>$pageTitle</span>";
			$name = $fileUrl
				? "<a class='panorama-card-name' href='" . $sanitizer->entities($fileUrl) . "' target='_blank' title='$filename'>$filename</a>"
				: "<span class='panorama-card-name' title='$filename'>$filename</span>";
			$out .= "<li class='panorama-card'>"
				. "<div class='panorama-card-fig'>$thumbLink</div>"
				. $name
				. "<div class='panorama-card-meta'>"
				. "<span class='panorama-card-meta-item'>" . $this->formatBytes($it['bytes'] ?? 0) . '</span>'
				. $pageLink
				. '</div></li>';
		}
		$out .= '</ul>';
		if(count($items) > count($display)) {
			$out .= '<p class="panorama-note">' . sprintf($this->_('Showing the first %1$d of %2$d items. The action below applies to all of them.'), count($display), count($items)) . '</p>';
		}

		$session = $this->wire()->session;
		$tn = $session->CSRF->getTokenName();
		$tv = $session->CSRF->getTokenValue();
		$confirm = $sanitizer->entities1($this->_('Delete all listed items? This cannot be undone.'));
		$out .= "<form method='post' action='./' class='uk-margin-small-top' onsubmit=\"return confirm('$confirm');\">"
			. "<input type='hidden' name='$tn' value='$tv'>"
			. "<button type='submit' name='cleanup_action' value='$action' class='uk-button uk-button-danger'>"
			. $this->icon('delete') . ' ' . $this->_('Delete all listed') . '</button></form>';
		return $out . '</div>';
	}

}
