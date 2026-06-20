<?php namespace ProcessWire;

trait PanoramaDashboard {
	/* ============================================================ *
	 *  DASHBOARD
	 * ============================================================ */

	/**
	 * Main dashboard
	 *
	 * @return string
	 */
	public function ___execute() {
		$config = $this->wire()->config;
		$input = $this->wire()->input;

		if($input->get('media')) {
			$stats = $this->getDashboardStats((bool) $input->get('refresh'));
			$media = (string) $input->get('media');
			$html = $media === 'recent'
				? $this->renderDashboardMedia($this->_('Recent uploads'), 'clock-o', $stats['recent'])
				: $this->renderDashboardMedia($this->_('Largest files'), 'hdd-o', $stats['largest']);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['success' => true, 'html' => $html]);
			exit;
		}

		if($input->get('data')) {
			$stats = $this->getDashboardStats((bool) $input->get('refresh'));
			$chart = [];
			foreach(array_slice($stats['extensions'], 0, 8, true) as $ext => $r) {
				$chart[] = ['label' => '.' . $ext, 'value' => $r['bytes'], 'human' => $this->formatBytes($r['bytes'])];
			}
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode([
				'success' => true,
				'html' => $this->renderDashboardContent($stats),
				'chart' => $chart,
			]);
			exit;
		}

		$stats = $this->getDashboardStats();
		$chart = [];
		foreach(array_slice($stats['extensions'], 0, 8, true) as $ext => $r) {
			$chart[] = ['label' => '.' . $ext, 'value' => $r['bytes'], 'human' => $this->formatBytes($r['bytes'])];
		}

		$config->styles->add($this->assetUrl('assets/css/Panorama.css'));
		$config->js('Panorama', [
			'dashboard' => [
				'chart' => $chart,
			],
		]);

		$out = $this->renderTabs('dashboard');
		$out .= '<div id="panorama-dashboard">';
		$out .= $this->renderDashboardContent($stats, false);
		$out .= '<div class="uk-grid-small uk-child-width-1-2@m panorama-grid" uk-grid>';
		$out .= $this->asyncPlaceholder('panorama-dashboard-largest', $this->_('Loading largest files…'), $this->base() . '?media=largest');
		$out .= $this->asyncPlaceholder('panorama-dashboard-recent', $this->_('Loading recent uploads…'), $this->base() . '?media=recent');
		$out .= '</div></div>';
		$out .= "<script type='module' src='" . $this->assetUrl('assets/js/dashboard.js') . "'></script>";
		$out .= "<script type='module' src='" . $this->assetUrl('assets/js/async-panel.js') . "'></script>";
		return $this->wrap($out);
	}

	protected function renderDashboardContent(array $stats, $includeMedia = true) {
		$out = $this->renderSummary($stats);
		$out .= '<div class="uk-grid-small uk-child-width-1-2@m panorama-grid" uk-grid>';
		$out .= $this->renderFileTypes($stats);
		$out .= $this->renderChart();
		$out .= '</div>';
		$out .= '<div class="uk-grid-small uk-child-width-1-2@m panorama-grid" uk-grid>';
		$out .= $this->renderRankedMedia($this->_('Top pages by media'), 'files-o', $stats['topPages']);
		$out .= $this->renderRankedMedia($this->_('Top templates by media'), 'cubes', $stats['topTemplates']);
		$out .= '</div>';
		$out .= '<div class="uk-grid-small uk-child-width-1-1 panorama-grid" uk-grid>';
		$out .= $this->renderFields($stats);
		$out .= '</div>';
		if($includeMedia) {
			$out .= '<div class="uk-grid-small uk-child-width-1-2@m panorama-grid" uk-grid>';
			$out .= $this->renderDashboardMedia($this->_('Largest files'), 'hdd-o', $stats['largest']);
			$out .= $this->renderDashboardMedia($this->_('Recent uploads'), 'clock-o', $stats['recent']);
			$out .= '</div>';
		}
		return $out;
	}

	/**
	 * Cached dashboard statistics. The dashboard is a summary view, so a short
	 * cache keeps navigation snappy without hiding real changes for long.
	 *
	 * @param bool $refresh
	 * @return array
	 */
	protected function getDashboardStats($refresh = false) {
		$cache = $this->wire()->cache;
		$key = 'Panorama.dashboard.v6';
		$data = !$refresh ? $cache->get($key) : null;
		if(is_array($data) && isset($data['images'], $data['files'], $data['extensions'])) return $data;
		$data = $this->getStats();
		$cache->save($key, $data, 300);
		return $data;
	}

	/**
	 * Render the Chart.js doughnut panel
	 *
	 * @return string
	 */
	protected function renderChart() {
		$out = $this->panelHeader($this->_('Disk usage by type'), 'pie-chart');
		$out .= '<div class="panorama-chart-wrap"><canvas id="panorama-chart-types"></canvas></div>';
		return $out . '</div>';
	}

	/**
	 * Gather dashboard statistics from the database
	 *
	 * @return array
	 */
	protected function getStats() {
		$fields = $this->wire()->fields;
		$database = $this->wire()->database;

		$imageFields = $fields->find('type=FieldtypeImage');
		$fileFields = $fields->find('type=FieldtypeFile');

		$stats = [
			'images' => ['count' => 0, 'bytes' => 0, 'fields' => $imageFields->count],
			'files' => ['count' => 0, 'bytes' => 0, 'fields' => $fileFields->count],
			'extensions' => [],
			'byField' => [],
			'topPages' => [],
			'topTemplates' => [],
			'recentWindows' => [
				7 => ['count' => 0, 'bytes' => 0],
				30 => ['count' => 0, 'bytes' => 0],
			],
			'largest' => [],
			'recent' => [],
		];

		$count = max(1, (int) $this->listCount);
		$largest = [];
		$recent = [];

		$collect = function($fieldNames, $type) use ($database, &$stats, $count, &$largest, &$recent) {
			foreach($fieldNames as $field) {
				$table = $database->escapeTable("field_{$field->name}");

				$query = $database->query(
					"SELECT LOWER(SUBSTRING_INDEX(data, '.', -1)) AS ext, COUNT(*) AS cnt, COALESCE(SUM(filesize), 0) AS bytes
					FROM `$table` GROUP BY ext"
				);
				$fieldCount = 0;
				$fieldBytes = 0;
				foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$ext = $row['ext'] !== '' ? $row['ext'] : '?';
					$cnt = (int) $row['cnt'];
					$bytes = (int) $row['bytes'];
					$fieldCount += $cnt;
					$fieldBytes += $bytes;
					if(!isset($stats['extensions'][$ext])) $stats['extensions'][$ext] = ['count' => 0, 'bytes' => 0, 'type' => $type];
					$stats['extensions'][$ext]['count'] += $cnt;
					$stats['extensions'][$ext]['bytes'] += $bytes;
				}
				$stats[$type]['count'] += $fieldCount;
				$stats[$type]['bytes'] += $fieldBytes;
				$stats['byField'][] = [
					'id' => (int) $field->id,
					'name' => $field->name,
					'icon' => (string) $field->get('icon'),
					'type' => $type,
					'count' => $fieldCount,
					'bytes' => $fieldBytes,
				];

				$query = $database->query("SELECT pages_id, COUNT(*) AS cnt, COALESCE(SUM(filesize), 0) AS bytes FROM `$table` GROUP BY pages_id ORDER BY bytes DESC LIMIT 50");
				foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$pid = (int) $row['pages_id'];
					if(!$pid) continue;
					if(!isset($stats['topPages'][$pid])) $stats['topPages'][$pid] = ['id' => $pid, 'count' => 0, 'bytes' => 0];
					$stats['topPages'][$pid]['count'] += (int) $row['cnt'];
					$stats['topPages'][$pid]['bytes'] += (int) $row['bytes'];
				}

				$query = $database->query("SELECT p.templates_id, COUNT(*) AS cnt, COALESCE(SUM(f.filesize), 0) AS bytes FROM `$table` f LEFT JOIN pages p ON p.id=f.pages_id GROUP BY p.templates_id");
				foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$tid = (int) $row['templates_id'];
					if(!$tid) continue;
					if(!isset($stats['topTemplates'][$tid])) $stats['topTemplates'][$tid] = ['id' => $tid, 'count' => 0, 'bytes' => 0];
					$stats['topTemplates'][$tid]['count'] += (int) $row['cnt'];
					$stats['topTemplates'][$tid]['bytes'] += (int) $row['bytes'];
				}

				foreach([7, 30] as $days) {
					$query = $database->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(filesize), 0) AS bytes FROM `$table` WHERE created >= DATE_SUB(NOW(), INTERVAL $days DAY)");
					$row = $query->fetch(\PDO::FETCH_ASSOC);
					if($row) {
						$stats['recentWindows'][$days]['count'] += (int) $row['cnt'];
						$stats['recentWindows'][$days]['bytes'] += (int) $row['bytes'];
					}
				}

				$query = $database->query("SELECT pages_id, data, filesize FROM `$table` ORDER BY filesize DESC LIMIT $count");
				foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$largest[] = ['field' => $field->name, 'type' => $type, 'pages_id' => (int) $row['pages_id'], 'data' => $row['data'], 'filesize' => (int) $row['filesize'], 'sort' => (int) $row['filesize']];
				}
				$query = $database->query("SELECT pages_id, data, filesize, UNIX_TIMESTAMP(created) AS ts FROM `$table` ORDER BY created DESC LIMIT $count");
				foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$recent[] = ['field' => $field->name, 'type' => $type, 'pages_id' => (int) $row['pages_id'], 'data' => $row['data'], 'filesize' => (int) $row['filesize'], 'sort' => (int) $row['ts']];
				}
			}
		};

		$collect($imageFields, 'images');
		$collect($fileFields, 'files');

		$bySort = function($a, $b) { return $b['sort'] <=> $a['sort']; };
		usort($largest, $bySort);
		usort($recent, $bySort);
		$stats['largest'] = array_slice($largest, 0, $count);
		$stats['recent'] = array_slice($recent, 0, $count);

		uasort($stats['extensions'], function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		usort($stats['byField'], function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		uasort($stats['topTemplates'], function($a, $b) { return $b['bytes'] <=> $a['bytes']; });

		$refs = $this->pageRefs(array_keys($stats['topPages']));
		$topPages = [];
		foreach($stats['topPages'] as $pid => $row) {
			$ref = $refs[$pid] ?? null;
			$key = $ref ? $ref['edit'] : (string) $pid;
			if(!isset($topPages[$key])) {
				$topPages[$key] = [
					'id' => $pid,
					'root_id' => $ref ? (int) $ref['id'] : $pid,
					'label' => $ref ? $ref['title'] : ('#' . $pid),
					'url' => $ref ? $ref['edit'] : '',
					'count' => 0,
					'bytes' => 0,
				];
			}
			$topPages[$key]['count'] += $row['count'];
			$topPages[$key]['bytes'] += $row['bytes'];
		}
		uasort($topPages, function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		$stats['topPages'] = array_slice($topPages, 0, 8, true);
		$labels = [];
		foreach($stats['topPages'] as $row) {
			$labels[$row['label']] = ($labels[$row['label']] ?? 0) + 1;
		}
		foreach($stats['topPages'] as &$row) {
			if(($labels[$row['label']] ?? 0) > 1 && !empty($row['root_id'])) {
				$row['label'] .= ' #' . (int) $row['root_id'];
			}
		}
		unset($row);

		$stats['topTemplates'] = array_slice($stats['topTemplates'], 0, 8, true);
		foreach($stats['topTemplates'] as $tid => &$row) {
			$template = $this->wire()->templates->get((int) $tid);
			$row['label'] = $template ? ($template->getLabel() ?: $template->name) : ('#' . $tid);
			$row['url'] = $template ? $this->wire()->config->urls->admin . 'setup/template/edit?id=' . (int) $tid : '';
		}
		unset($row);

		return $stats;
	}

	/**
	 * Render the top summary tiles
	 */
	protected function renderSummary(array $stats) {
		$totalCount = $stats['images']['count'] + $stats['files']['count'];
		$totalBytes = $stats['images']['bytes'] + $stats['files']['bytes'];
		$avgBytes = $totalCount ? (int) round($totalBytes / $totalCount) : 0;
		$largest = $stats['largest'][0] ?? null;
		$largestName = $largest ? $this->wire()->sanitizer->entities($largest['data']) : $this->_('none');
		$tiles = [
			['tachometer', $this->_('Total media'), number_format($totalCount), $this->formatBytes($totalBytes)],
			['picture-o', $this->_('Images'), number_format($stats['images']['count']), $this->formatBytes($stats['images']['bytes'])],
			['file-o', $this->_('Files'), number_format($stats['files']['count']), $this->formatBytes($stats['files']['bytes'])],
			['database', $this->_('Media fields'), number_format($stats['images']['fields'] + $stats['files']['fields']), $this->_('image + file fields')],
			['balance-scale', $this->_('Average size'), $this->formatBytes($avgBytes), $this->_('per item')],
			['calendar-plus-o', $this->_('Uploaded 7 days'), number_format($stats['recentWindows'][7]['count']), $this->formatBytes($stats['recentWindows'][7]['bytes'])],
			['calendar', $this->_('Uploaded 30 days'), number_format($stats['recentWindows'][30]['count']), $this->formatBytes($stats['recentWindows'][30]['bytes'])],
			['hdd-o', $this->_('Largest file'), $largest ? $this->formatBytes($largest['filesize']) : '0 B', $largestName],
		];
		$out = '<div class="uk-grid-small uk-child-width-1-2 uk-child-width-1-4@m panorama-tiles" uk-grid>';
		foreach($tiles as $tile) {
			list($icon, $label, $value, $sub) = $tile;
			$out .= "<div><div class='uk-card uk-card-default uk-card-small uk-card-body panorama-tile'>"
				. "<div class='uk-flex uk-flex-between uk-flex-middle'><span class='uk-text-meta panorama-tile-label'>$label</span>" . $this->icon($icon) . "</div>"
				. "<div class='uk-text-large uk-text-bold panorama-tile-value'>$value</div>"
				. "<div class='uk-text-meta panorama-tile-sub'>$sub</div>"
				. "</div></div>";
		}
		return $out . '</div>';
	}

	/**
	 * Render the file-type breakdown panel with proportional bars
	 */
	protected function renderFileTypes(array $stats) {
		$out = $this->panelHeader($this->_('By file type'), 'pie-chart');
		if(!$stats['extensions']) return $out . $this->emptyNote() . '</div>';
		$maxBytes = max(array_column($stats['extensions'], 'bytes')) ?: 1;
		$out .= '<div class="panorama-bars">';
		foreach($stats['extensions'] as $ext => $row) {
			$pct = round($row['bytes'] / $maxBytes * 100);
			$ext = $this->wire()->sanitizer->entities($ext);
			$out .= "<div class='panorama-bar panorama-bar-{$row['type']}'>"
				. "<span class='panorama-bar-label'>.$ext</span>"
				. "<span class='panorama-bar-track'><span class='panorama-bar-fill' style='width:{$pct}%'></span></span>"
				. "<span class='panorama-bar-meta'>" . number_format($row['count']) . ' &middot; ' . $this->formatBytes($row['bytes']) . "</span>"
				. "</div>";
		}
		return $out . '</div></div>';
	}

	/**
	 * Render the per-field breakdown panel
	 */
	protected function renderFields(array $stats) {
		$out = $this->panelHeader($this->_('By field'), 'database');
		if(!$stats['byField']) return $out . $this->emptyNote() . '</div>';
		$adminUrl = $this->wire()->config->urls->admin;
		$maxBytes = max(array_column($stats['byField'], 'bytes')) ?: 1;
		$out .= "<div class='panorama-field-list'>";
		foreach($stats['byField'] as $row) {
			$name = $this->wire()->sanitizer->entities($row['name']);
			$fallbackIcon = $row['type'] === 'images' ? 'image' : 'file';
			$editUrl = "{$adminUrl}setup/field/edit?id=" . (int) ($row['id'] ?? 0);
			$type = $row['type'] === 'images' ? $this->_('Images') : $this->_('Files');
			$count = number_format((int) $row['count']);
			$bytes = (int) $row['bytes'];
			$pct = max(4, round($bytes / $maxBytes * 100));
			$out .= "<a class='panorama-field-row' href='$editUrl'>"
				. "<span class='panorama-field-icon'>" . $this->fieldIcon($row['icon'] ?? '', $fallbackIcon) . '</span>'
				. "<span class='panorama-field-main'>"
				. "<span class='panorama-field-name'>$name</span>"
				. "<span class='panorama-field-track'><span style='width:{$pct}%'></span></span>"
				. '</span>'
				. "<span class='panorama-field-meta'>"
				. "<span class='panorama-field-type'>$type</span>"
				. "<span>$count " . $this->_('items') . '</span>'
				. "<strong>" . $this->formatBytes($bytes) . '</strong>'
				. '</span>'
				. '</a>';
		}
		return $out . '</div></div>';
	}

	/**
	 * Render ranked dashboard rows with count and size.
	 *
	 * @param string $title
	 * @param string $icon
	 * @param array $items
	 * @return string
	 */
	protected function renderRankedMedia($title, $icon, array $items) {
		$sanitizer = $this->wire()->sanitizer;
		$out = $this->panelHeader($title, $icon);
		if(!$items) return $out . $this->emptyNote() . '</div>';
		$maxBytes = max(array_column($items, 'bytes')) ?: 1;
		$out .= '<div class="panorama-ranked">';
		foreach($items as $item) {
			$label = $sanitizer->entities($item['label'] ?? ('#' . ($item['id'] ?? '')));
			$url = !empty($item['url']) ? $sanitizer->entities($item['url']) : '';
			$count = number_format((int) ($item['count'] ?? 0));
			$bytes = (int) ($item['bytes'] ?? 0);
			$pct = max(4, round($bytes / $maxBytes * 100));
			$name = $url ? "<a href='$url'>$label</a>" : "<span>$label</span>";
			$out .= "<div class='panorama-ranked-row'>"
				. "<div class='panorama-ranked-main'><span class='panorama-ranked-label'>$name</span><span class='uk-text-meta'>$count · " . $this->formatBytes($bytes) . "</span></div>"
				. "<span class='panorama-ranked-track'><span style='width:{$pct}%'></span></span>"
				. "</div>";
		}
		return $out . '</div></div>';
	}

	/**
	 * Render a dashboard media grid (largest / recent)
	 */
	protected function renderDashboardMedia($title, $icon, array $items) {
		$out = $this->panelHeader($title, $icon);
		if(!$items) return $out . $this->emptyNote() . '</div>';
		$pages = $this->wire()->pages;
		$sanitizer = $this->wire()->sanitizer;
		$adminUrl = $this->wire()->config->urls->admin;

		$out .= '<ul class="panorama-gallery">';
		foreach($items as $item) {
			$page = $pages->get($item['pages_id']);
			$thumb = '';
			$assetUrl = '';
			if($page && $page->id) {
				$pagefiles = $page->getUnformatted($item['field']);
				$pagefile = ($pagefiles instanceof Pagefiles) ? $pagefiles->getFile($item['data']) : null;
					if($pagefile) {
						$assetUrl = $pagefile->url;
						if($item['type'] === 'images' && $pagefile instanceof Pageimage) {
							$thumbUrl = $this->existingImageThumbUrl($pagefile, 160);
							$thumb = "<img src='" . $sanitizer->entities($thumbUrl) . "' alt='' loading='lazy'>";
						}
					}
				}
			if(!$thumb) $thumb = "<span class='panorama-card-icon'>" . $this->icon('file') . "</span>";

			$rootPage = ($page && $page->id) ? $this->rootOwner($page) : null;
			$pageTitle = $rootPage ? $sanitizer->entities((string) $rootPage->get('title|name')) : ('#' . $item['pages_id']);
			$editUrl = $rootPage ? "{$adminUrl}page/edit/?id={$rootPage->id}" : '#';
			$filename = $sanitizer->entities($item['data']);
			$assetHref = $assetUrl ? $sanitizer->entities($assetUrl) : '';
			$thumbLink = $assetHref ? "<a href='$assetHref' target='_blank' class='panorama-card-thumb'>$thumb</a>" : "<span class='panorama-card-thumb'>$thumb</span>";
			$name = $assetHref ? "<a class='panorama-card-name' href='$assetHref' target='_blank' title='$filename'>$filename</a>" : "<span class='panorama-card-name' title='$filename'>$filename</span>";
			$quickOpen = $assetHref ? "<a class='panorama-card-icon-btn' href='$assetHref' target='_blank' aria-label='" . $this->_('Open file') . "'>" . $this->icon('search') . '</a>' : '';
			$out .= "<li class='panorama-card'><div class='panorama-card-fig'>$thumbLink$quickOpen</div>"
				. $name
				. "<div class='panorama-card-meta'>"
				. "<span class='panorama-card-meta-item'>" . $this->formatBytes($item['filesize']) . "</span>"
				. "<a class='panorama-card-meta-item panorama-card-page' href='$editUrl'>" . $this->icon('edit') . " $pageTitle</a>"
				. "</div></li>";
		}
		return $out . '</ul></div>';
	}

}
