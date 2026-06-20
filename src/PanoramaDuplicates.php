<?php namespace ProcessWire;

trait PanoramaDuplicates {
	/* ============================================================ *
	 *  DUPLICATES
	 * ============================================================ */

	/**
	 * Find duplicate files by content hash
	 *
	 * @return string
	 */
	public function ___executeDuplicates() {
		$config = $this->wire()->config;
		$input = $this->wire()->input;

		if($input->get('data')) {
			header('Content-Type: application/json; charset=utf-8');
			$duplicateData = $this->getDuplicateData((bool) $input->get('refresh'));
			echo json_encode([
				'success' => true,
				'needsScan' => !empty($duplicateData['needsScan']),
				'html' => $this->renderDuplicatesContent($duplicateData),
				'stats' => $this->duplicateStats($duplicateData),
				'groups' => $this->duplicateGroupsPayload($duplicateData),
			]);
			exit;
		}

		$config->styles->add($this->assetUrl('assets/css/Panorama.css'));
		$config->js('Panorama', [
			'duplicates' => [
				'dataUrl' => $this->base() . 'duplicates/?data=1',
				'refreshUrl' => $this->base() . 'duplicates/?data=1&refresh=1',
				'loading' => $this->_('Loading duplicate report…'),
				'scanning' => $this->_('Scanning files for duplicates…'),
				'working' => $this->_('Still working in the background…'),
				'error' => $this->_('Could not load duplicate report.'),
				'search' => $this->_('Search duplicates…'),
				'lastScan' => $this->_('Last scan'),
				'refresh' => $this->_('Refresh scan'),
				'duplicateFiles' => $this->_('Duplicate files'),
				'duplicateSets' => $this->_('Duplicate sets'),
				'identicalFiles' => $this->_('identical files'),
				'extraCopies' => $this->_('Extra copies'),
				'copies' => $this->_('copies'),
				'copiesLabel' => $this->_('Copies'),
				'sizeEach' => $this->_('Size each'),
				'reclaimable' => $this->_('reclaimable'),
				'reclaimableLabel' => $this->_('Reclaimable'),
				'noDuplicates' => $this->_('No duplicate files found.'),
				'showMore' => $this->_('Show more'),
				'open' => $this->_('Open file'),
				'edit' => $this->_('Edit page'),
				'actions' => $this->_('Actions'),
				'never' => $this->_('never'),
				'loadingFallback' => $this->_('Loading…'),
				'timeout' => $this->_('Request timed out.'),
				'preparing' => $this->_('Preparing data in the background.'),
			],
		]);
		$this->headline($this->_('Duplicates'));
		$this->browserTitle($this->wire()->page->title . ' - ' . $this->_('Duplicates'));

		$out = $this->renderTabs('duplicates');
		$out .= '<div id="panorama-duplicates" class="panorama-async-panel">'
			. '<div class="uk-card uk-card-default uk-card-small uk-card-body panorama-panel">'
			. '<div class="uk-text-center uk-padding-small"><span uk-spinner></span><p class="uk-text-meta">' . $this->_('Loading duplicate report…') . '</p></div>'
			. '</div></div>';
		$out .= "<script type='module' src='" . $this->assetUrl('assets/js/duplicates.js') . "'></script>";
		return $this->wrap($out);
	}

	/**
	 * Summary numbers for duplicate report.
	 *
	 * @param array $duplicateData
	 * @return array
	 */
	protected function duplicateStats(array $duplicateData) {
		$groups = $duplicateData['groups'] ?? [];
		$wasted = 0;
		$copies = 0;
		foreach($groups as $group) {
			$wasted += (int) ($group['wasted'] ?? 0);
			$copies += max(0, count($group['members'] ?? []) - 1);
		}
		return [
			'sets' => count($groups),
			'extraCopies' => $copies,
			'wasted' => $wasted,
			'wastedHuman' => $this->formatBytes($wasted),
			'created' => (int) ($duplicateData['created'] ?? 0),
			'lastScan' => !empty($duplicateData['created']) ? wireRelativeTimeStr($duplicateData['created']) : $this->_('never'),
		];
	}

	/**
	 * Structured duplicate groups for the Explorer-like client UI.
	 *
	 * @param array $duplicateData
	 * @return array
	 */
	protected function duplicateGroupsPayload(array $duplicateData) {
		$groups = $duplicateData['groups'] ?? [];
		if(!$groups) return [];

		$assetUrl = $this->wire()->config->urls->files;
		$pageIds = [];
		foreach($groups as $group) {
			foreach($group['members'] as $member) $pageIds[] = $member['page_id'];
		}
		$refs = $this->pageRefs($pageIds);
		$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
		$out = [];

		foreach($groups as $index => $group) {
			$first = $group['members'][0];
			$filename = $first['filename'];
			$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
			$isImage = in_array($ext, $imageExts, true);
			$thumbUrl = $isImage ? $this->existingDiskThumbUrl($first['page_id'], $filename, 160) : '';
			$members = [];
			foreach($group['members'] as $member) {
				$ref = $refs[$member['page_id']] ?? null;
				$fileUrl = $assetUrl . $member['page_id'] . '/' . rawurlencode($member['filename']);
				$members[] = [
					'page_id' => (int) $member['page_id'],
					'filename' => $member['filename'],
					'page' => $ref ? $ref['title'] : ('#' . $member['page_id']),
					'edit_url' => $ref ? $ref['edit'] : '',
					'file_url' => $fileUrl,
				];
			}
			$out[] = [
				'id' => $index,
				'filename' => $filename,
				'ext' => $ext,
				'is_image' => $isImage,
				'thumb_url' => $thumbUrl,
				'file_url' => $assetUrl . $first['page_id'] . '/' . rawurlencode($filename),
				'bytes' => (int) $group['bytes'],
				'bytesHuman' => $this->formatBytes($group['bytes']),
				'wasted' => (int) $group['wasted'],
				'wastedHuman' => $this->formatBytes($group['wasted']),
				'copies' => count($group['members']),
				'members' => $members,
				'search' => strtolower($filename . ' ' . $this->formatBytes($group['bytes']) . ' ' . $this->formatBytes($group['wasted']) . ' ' . implode(' ', array_column($members, 'page'))),
			];
		}

		return $out;
	}

	/**
	 * Build groups of duplicate files (matched by size then SHA-1)
	 *
	 * @return array
	 */
	protected function findDuplicates() {
		$disk = $this->scanDisk();
		// Group originals by filesize first (cheap), hash only within size collisions
		$bySize = [];
		foreach($disk['originals'] as $o) {
			if($o['bytes'] > 0) $bySize[$o['bytes']][] = $o;
		}
		$byHash = [];
		foreach($bySize as $bytes => $items) {
			if(count($items) < 2) continue;
			foreach($items as $o) {
				if(!is_file($o['path'])) continue;
				$hash = @sha1_file($o['path']);
				if(!$hash) continue;
				$byHash[$hash]['bytes'] = $bytes;
				$byHash[$hash]['members'][] = $o;
			}
		}
		$groups = [];
		foreach($byHash as $h) {
			if(count($h['members']) < 2) continue;
			$h['wasted'] = (count($h['members']) - 1) * $h['bytes'];
			$groups[] = $h;
		}
		usort($groups, function($a, $b) { return $b['wasted'] <=> $a['wasted']; });
		return $groups;
	}

	/**
	 * Render duplicate report content for async loading.
	 *
	 * @param array $duplicateData
	 * @return string
	 */
	protected function renderDuplicatesContent(array $duplicateData) {
		$groups = $duplicateData['groups'] ?? [];
		$sanitizer = $this->wire()->sanitizer;
		$assetUrl = $this->wire()->config->urls->files;
		$wasted = 0;
		$pageIds = [];
		foreach($groups as $g) {
			$wasted += $g['wasted'];
			foreach($g['members'] as $m) $pageIds[] = $m['page_id'];
		}
		$refs = $this->pageRefs($pageIds);

		$out = '<div class="uk-grid-small uk-child-width-1-2@m panorama-tiles" uk-grid>';
		$out .= $this->statTile('clone', $this->_('Duplicate sets'), number_format(count($groups)), $this->_('identical files'));
		$out .= $this->statTile('hdd-o', $this->_('Reclaimable space'), $this->formatBytes($wasted), $this->_('if de-duplicated'));
		$out .= '</div>';

		$out .= $this->panelHeader($this->_('Duplicate files'), 'clone');
		$lastScan = !empty($duplicateData['created']) ? wireRelativeTimeStr($duplicateData['created']) : $this->_('never');
		$out .= '<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap panorama-dupe-tools">'
			. '<p class="panorama-note">' . sprintf($this->_('Files with identical content. Last scan: %s.'), $lastScan) . '</p>'
			. "<button type='button' class='uk-button uk-button-default' data-panorama-refresh-duplicates>" . $this->icon('refresh') . ' ' . $this->_('Refresh scan') . '</button>'
			. '</div>';

		if(!empty($duplicateData['needsScan'])) {
			return $out . "<div class='uk-placeholder uk-text-center panorama-empty'>" . $this->_('No duplicate scan has been cached yet. A scan will run in the background.') . "</div></div>";
		}
		if(!$groups) return $out . $this->emptyNote() . '</div>';

		$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
		$initialLimit = 10;
		if(count($groups) > $initialLimit) {
			$out .= '<p class="panorama-note">' . sprintf($this->_('Showing the first %1$d of %2$d duplicate sets.'), $initialLimit, count($groups)) . '</p>';
		}
		$out .= '<div class="panorama-dupe-list-wrap">';
		foreach($groups as $index => $g) {
			$first = $g['members'][0];
			$ext = strtolower(pathinfo($first['filename'], PATHINFO_EXTENSION));
			if(in_array($ext, $imageExts)) {
				$thumbUrl = $this->existingDiskThumbUrl($first['page_id'], $first['filename'], 80);
				$preview = $thumbUrl
					? "<img src='" . $sanitizer->entities($thumbUrl) . "' alt='' loading='lazy'>"
					: "<span class='panorama-dupe-icon'>" . $this->icon('image') . "</span>";
			} else {
				$preview = "<span class='panorama-dupe-icon'>" . $this->icon('file') . "</span>";
			}
			$hidden = $index >= $initialLimit ? ' hidden' : '';
			$out .= "<div class='panorama-dupe'$hidden>";
			$out .= "<div class='panorama-dupe-head'>"
				. $preview
				. "<div class='panorama-dupe-main'><strong>" . $sanitizer->entities($first['filename']) . "</strong>"
				. "<span class='uk-text-meta'>" . sprintf($this->_('%1$d copies · %2$s each · %3$s reclaimable'), count($g['members']), $this->formatBytes($g['bytes']), $this->formatBytes($g['wasted'])) . "</span>"
				. "</div></div>";
			$members = [];
			foreach($g['members'] as $m) {
				$ref = $refs[$m['page_id']] ?? null;
				$pageCell = $ref ? "<a href='{$ref['edit']}'>" . $sanitizer->entities($ref['title']) . "</a>" : "#{$m['page_id']}";
				$fileUrl = $assetUrl . $m['page_id'] . '/' . rawurlencode($m['filename']);
				$members[] = "<li>$pageCell &mdash; <a href='" . $sanitizer->entities($fileUrl) . "' target='_blank'>" . $sanitizer->entities($m['filename']) . "</a></li>";
			}
			$out .= "<ul class='panorama-dupe-list'>";
			foreach(array_slice($members, 0, 3) as $member) $out .= $member;
			if(count($members) > 3) {
				$out .= "<li><details class='panorama-dupe-more'><summary>" . sprintf($this->_('Show %d more copies'), count($members) - 3) . "</summary><ul>";
				foreach(array_slice($members, 3) as $member) $out .= $member;
				$out .= "</ul></details></li>";
			}
			$out .= "</ul></div>";
		}
		$out .= '</div>';
		if(count($groups) > $initialLimit) {
			$out .= "<button type='button' class='uk-button uk-button-default panorama-show-more' data-panorama-show-more>"
				. sprintf($this->_('Show %d more duplicate sets'), count($groups) - $initialLimit)
				. "</button>";
		}
		return $out . '</div>';
	}

	/**
	 * Cached duplicate scan payload.
	 *
	 * @param bool $refresh
	 * @return array
	 */
	protected function getDuplicateData($refresh = false) {
		$cache = $this->wire()->cache;
		$key = 'Panorama.duplicates';
		$data = !$refresh ? $cache->get($key) : null;
		if(is_array($data) && isset($data['groups'], $data['created'])) return $data;
		if(!$refresh) {
			return [
				'groups' => [],
				'created' => 0,
				'needsScan' => true,
			];
		}
		$data = [
			'groups' => $this->findDuplicates(),
			'created' => time(),
		];
		$cache->save($key, $data, 86400);
		return $data;
	}

}
