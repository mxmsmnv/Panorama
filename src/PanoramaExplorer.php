<?php namespace ProcessWire;

trait PanoramaExplorer {
	/* ============================================================ *
	 *  EXPLORER (by page / by template / gallery)
	 * ============================================================ */

	/**
	 * Explorer — browse media by page, by template, or as a gallery,
	 * with per-image variation info and cleanup.
	 *
	 * @return string
	 */
	public function ___executeExplorer() {
		$config = $this->wire()->config;
		$session = $this->wire()->session;
		$base = $this->base();

		$this->headline($this->_('Explorer'));
		$this->browserTitle($this->wire()->page->title . ' - ' . $this->_('Explorer'));

		// Assets — modern vanilla ESM stack (no jQuery plugins)
		$config->styles->add($this->assetUrl('assets/css/Panorama.css'));
		$config->styles->add($this->assetUrl('assets/libs/photoswipe/photoswipe.css'));

		$js_settings = [
			'resultsUrl' => $base . 'results/',
			'exportUrl' => $base . 'export/',
			'bulkUrl' => $base . 'bulk/',
			'variationsUrl' => $base . 'variations/',
			'defaultMode' => in_array($this->defaultViewMode, ['page', 'template', 'gallery']) ? $this->defaultViewMode : 'gallery',
			'labels' => array_merge($this->columnLabels, [
				'delete' => $this->_('Delete'),
				'regen' => $this->_('Regenerate variations'),
				'tagAdd' => $this->_('Add tag'),
				'tagRemove' => $this->_('Remove tag'),
				'selected' => $this->_('selected'),
				'tagPrompt' => $this->_('Tag name:'),
				'deleteConfirm' => $this->_('Delete the selected files? This cannot be undone.'),
				'attachedTo' => $this->_('Attached to'),
				'template' => $this->_('Template'),
				'variations' => $this->_('Variations'),
				'noVariations' => $this->_('No variations'),
				'cleanVariations' => $this->_('Clean variations'),
				'cleanConfirm' => $this->_('Delete all variations of this image?'),
				'search' => $this->_('Search filename, size, dimensions…'),
				'noMedia' => $this->_('No media found.'),
				'open' => $this->_('Open'),
				'edit' => $this->_('Edit page'),
				'loading' => $this->_('Loading…'),
				'byPage' => $this->_('By page'),
				'byTemplate' => $this->_('By template'),
				'gallery' => $this->_('Gallery'),
				'working' => $this->_('Still working in the background…'),
				'timeout' => $this->_('Request timed out.'),
				'error' => $this->_('Error'),
				'preparing' => $this->_('Preparing data in the background.'),
				'items' => $this->_('items'),
				'skippedItems' => $this->_('Some items were skipped.'),
			]),
			'token' => [
				'name' => $session->CSRF->getTokenName(),
				'value' => $session->CSRF->getTokenValue(),
			],
		];
		$config->js('Panorama', $js_settings);

		$out = $this->renderTabs('explorer');

		$out .= '<div class="panorama-explorer-shell">';
		$out .= '<aside class="panorama-explorer-sidebar">';
		$out .= $this->renderExplorerControls();
		$out .= '<a href="#" class="uk-button uk-button-default panorama-export-link panorama-export-workspace">' . $this->icon('download') . ' ' . $this->_('Export CSV') . '</a>';
		$out .= '</aside>';
		$out .= '<main class="panorama-explorer-main"><div id="panorama-results" class="panorama-results"></div></main>';
		$out .= '</div>';
		$out .= "<script type='module' src='" . $this->assetUrl('assets/js/explorer.js') . "'></script>";
		return $this->wrap($out);
	}

	protected function renderExplorerControls() {
		$sanitizer = $this->wire()->sanitizer;
		$mediaType = in_array($this->defaultMediaType, ['images', 'files'], true) ? $this->defaultMediaType : 'images';
		$selector = $sanitizer->entities('template=, title%=');
		$out = '<form id="panorama-filters" class="panorama-control-panel" method="get">';

		$out .= '<div id="wrap_Inputfield_media_type" class="panorama-control-group">'
			. '<div class="panorama-control-label">' . $this->_('Media type') . '</div>'
			. '<div class="panorama-choice-row">';
		foreach(['images' => $this->otherLabels['images'], 'files' => $this->otherLabels['files']] as $value => $label) {
			$checked = $mediaType === $value ? ' checked' : '';
			$active = $mediaType === $value ? ' is-active' : '';
			$out .= "<label class='panorama-choice$active'>"
				. "<input type='radio' name='media_type' value='$value'$checked>"
				. '<span>' . $sanitizer->entities($label) . '</span>'
				. '</label>';
		}
		$out .= '</div></div>';

		$out .= '<div id="Inputfield_view_mode" class="panorama-control-group">'
			. '<div class="panorama-control-label">' . $this->_('View') . '</div>'
			. '<div class="panorama-choice-row panorama-view-row">'
			. '<button type="button" class="panorama-choice" data-mode="page">' . $this->_('By page') . '</button>'
			. '<button type="button" class="panorama-choice" data-mode="template">' . $this->_('By template') . '</button>'
			. '<button type="button" class="panorama-choice" data-mode="gallery">' . $this->_('Gallery') . '</button>'
			. '</div></div>';

		$out .= '<label id="wrap_Inputfield_pages_selector" class="panorama-control-group">'
			. '<span class="panorama-control-label">' . $this->_('From pages matching') . '</span>'
			. "<textarea id='Inputfield_pages_selector' name='pages_selector' class='uk-textarea panorama-selector-input' rows='3'>$selector</textarea>"
			. '</label>';

		return $out . '</form>';
	}

	/**
	 * AJAX results as JSON for the Explorer (rows enriched with template and
	 * a per-image variation summary).
	 *
	 * @return string
	 */
	public function ___executeResults() {
		if(!$this->wire()->config->ajax) return '';
		$type = $this->wire()->input->post('type');
		$selector_str = $this->wire()->input->post('selector');
		$result = $this->buildMediaRows($type, $selector_str);

		// Enrich with template names. Variation details are loaded per image by
		// the dedicated variations endpoint so result loading stays lightweight.
		$rows = $result['rows'] ?? [];
		if($rows) {
			$templates = $this->templatesForRows($rows);
			foreach($rows as &$row) {
				$row['template'] = $templates[$row['owner_id']] ?? '';
			}
			unset($row);
			$result['rows'] = $rows;
		}

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($result);
		exit;
	}

	/**
	 * Map each row's owner page id to its template label
	 *
	 * @param array $rows
	 * @return array [owner pageId => templateLabel]
	 */
	protected function templatesForRows(array $rows) {
		$ids = array_unique(array_map('intval', array_column($rows, 'owner_id')));
		if(!$ids) return [];
		$ids = implode(',', $ids);
		$result = $this->wire()->database->query("SELECT id, templates_id FROM pages WHERE id IN($ids)");
		$map = [];
		foreach($result->fetchAll(\PDO::FETCH_ASSOC) as $r) {
			$tpl = $this->wire()->templates->get((int) $r['templates_id']);
			$map[(int) $r['id']] = $tpl ? ($tpl->getLabel() ?: $tpl->name) : '';
		}
		return $map;
	}

	/**
	 * AJAX: list the variations of a single image (JSON)
	 *
	 * @return string
	 */
	public function ___executeVariations() {
		$input = $this->wire()->input;
		$config = $this->wire()->config;
		$pid = (int) $input->get('id');
		$filename = $this->safeBasename($input->get('name'));

		header('Content-Type: application/json; charset=utf-8');
		$out = ['variations' => [], 'bytes' => 0];
		if($pid && $filename) {
			$dir = $config->paths->files . $pid . '/';
			$stem = pathinfo($filename, PATHINFO_FILENAME);
			if(is_dir($dir)) {
				foreach(glob($dir . $stem . '.*') as $path) {
					$name = basename($path);
					if($name === $filename) continue; // the original
					if($this->variationBase($name) === null) continue; // not a variation
					$bytes = (int) filesize($path);
					$dims = preg_match('/\.(\d+)x(\d+)/', $name, $m) ? [(int) $m[1], (int) $m[2]] : [0, 0];
					$out['variations'][] = ['name' => $name, 'width' => $dims[0], 'height' => $dims[1], 'bytes' => $bytes];
					$out['bytes'] += $bytes;
				}
			}
		}
		usort($out['variations'], function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		echo json_encode($out);
		exit;
	}

	/**
	 * CSV export of the current selection
	 */
	public function ___executeExport() {
		$input = $this->wire()->input;
		$type = $input->get('type');
		$selector_str = $input->get('selector');
		$result = $this->buildMediaRows($type, $selector_str);

		$is_images = $result['is_images'];
		$cols = $is_images
			? ['page', 'field', 'filename', 'uploadname', 'description', 'filedata', 'tags', 'filesize', 'width', 'height', 'ratio', 'modified', 'created', 'sort']
			: ['page', 'field', 'filename', 'uploadname', 'description', 'filedata', 'tags', 'filesize', 'modified', 'created', 'sort'];

		$filename = 'panorama-' . ($is_images ? 'images' : 'files') . '-' . date('Ymd-His') . '.csv';
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		$fh = fopen('php://output', 'w');
		fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

		// Header row
		$header = [];
		foreach($cols as $col) $header[] = $this->columnLabels[$col];
		fputcsv($fh, $header);

		if(empty($result['error'])) {
			foreach($result['rows'] as $row) {
				$line = [];
				foreach($cols as $col) {
					if($col === 'page') {
						$line[] = $row['prefix'] . $row['title'];
						continue;
					}
					$line[] = isset($row[$col]) ? (string) $row[$col] : '';
				}
				fputcsv($fh, $line);
			}
		}
		fclose($fh);
		exit;
	}

	/**
	 * Build normalised media rows for a media type + page selector.
	 * Shared by the lister table and the CSV export.
	 *
	 * @param string $type 'images' or 'files'
	 * @param string $selector_str
	 * @return array  ['is_images'=>bool, 'rows'=>[], 'error'=>string]
	 */
	protected function buildMediaRows($type, $selector_str) {
		$config = $this->wire()->config;
		$fields = $this->wire()->fields;
		$database = $this->wire()->database;

		$is_images = $type === 'images';
		$out = ['is_images' => $is_images, 'rows' => [], 'error' => ''];

		$repeater_connections = $this->getRepeaterConnections();
		$repeaters = $repeater_connections['repeaters'];

		// Remove blank selector components (InputfieldSelector allowBlankValues)
		try {
			$selectors = new Selectors((string) $selector_str);
			foreach($selectors as $selector) {
				if($selector['value'] === '') $selectors->remove($selector);
			}
			$selector_str = (string) $selectors;
		} catch(\Exception $e) {
			$out['error'] = $this->_('The page selector is not valid.');
			return $out;
		}

		$where = '';
		if($selector_str) {
			try {
				$ids = $this->wire()->pages->findIDs($selector_str);
			} catch(\Exception $e) {
				$out['error'] = $this->_('The page selector is not valid.');
				return $out;
			}
			$to_merge = [];
			foreach($ids as $id) {
				if(isset($repeaters[$id])) $to_merge[] = $repeaters[$id];
			}
			$ids = array_merge($ids, ...$to_merge);
			if(!$ids) {
				$out['error'] = $this->_('No pages matched your filter.');
				return $out;
			}
			$where = ' WHERE pages_id IN(' . implode(',', array_map('intval', $ids)) . ')';
		}

		if($is_images) {
			$flds = $fields->find('type=FieldtypeImage');
			if(!$flds->count) { $out['error'] = $this->_('Site has no image fields.'); return $out; }
			$cols = 'pages_id, data, description, filedata, filesize, width, height, ratio, modified, created, sort';
		} else {
			$flds = $fields->find('type=FieldtypeFile');
			if(!$flds->count) { $out['error'] = $this->_('Site has no file fields.'); return $out; }
			$cols = 'pages_id, data, description, filedata, filesize, modified, created, sort';
		}

		$has_languages = !is_null($this->wire()->languages);
		$langs = $has_languages ? $this->wire()->languages->getAll()->explode('name', ['key' => 'id']) : [];
		$owners = $repeater_connections['owners'];
		$custom_fields = [];
		$data = [];
		$page_ids = [];
		foreach($flds as $field) {
			$template = $field->type->getFieldsTemplate($field);
			if($template) {
				foreach($template->fieldgroup as $fld) {
					$label = trim((string) $fld->label);
					$custom_fields[$field->name]['_' . $fld->id] = $label !== '' ? $label : $fld->name;
				}
			}
			$select_cols = $cols;
			if($field->useTags) $select_cols .= ', tags';
			$table = $database->escapeTable("field_{$field->name}");
			$results = $database->query("SELECT $select_cols FROM `$table`$where");
			$data[$field->name] = $results->fetchAll(\PDO::FETCH_ASSOC);
			$page_ids = $page_ids + array_flip(array_column($data[$field->name], 'pages_id'));
		}
		$page_ids = array_keys($page_ids);
		foreach($page_ids as $key => $page_id) {
			if(isset($owners[$page_id])) $page_ids[$key] = reset($owners[$page_id]);
		}
		if($page_ids) {
			$results = $database->query('SELECT pages_id, data FROM field_title WHERE pages_id IN(' . implode(',', array_map('intval', $page_ids)) . ')');
			$titles = $results->fetchAll(\PDO::FETCH_KEY_PAIR);
		} else {
			$titles = [];
		}

		$admin_url = $config->urls->admin;
		$asset_url = $config->urls->files;

		foreach($data as $field_name => $field_data) {
			foreach($field_data as $item) {
				$page_id = $item['pages_id'];
				$prefix = '';
				$edit_url = "{$admin_url}page/edit/?id=$page_id#find-$field_name";
				if(isset($owners[$page_id])) {
					$connections = $owners[$page_id];
					$root_id = array_shift($connections);
					$open = $connections ? implode(',', $connections) . ",$page_id" : $page_id;
					$edit_url = "{$admin_url}page/edit/?id=$root_id&panorama_open=$open#find-{$field_name}_repeater$page_id";
					$page_id = $root_id;
					$prefix = '*';
				}
				$title = isset($titles[$page_id]) ? $titles[$page_id] : (string) $page_id;

				// Description
				if($has_languages) {
					$description = wireDecodeJSON($item['description']);
					if(is_array($description)) {
						$description = array_filter($description);
						$lang_description = [];
						foreach($description as $key => $value) {
							if($key === 0) $lang_description['default'] = $value;
							elseif(isset($langs[$key])) $lang_description[$langs[$key]] = $value;
							else $lang_description[$key] = $value;
						}
						$description = $this->arrayToText($lang_description);
					} else {
						$description = $item['description'];
					}
				} else {
					$description = $item['description'];
				}

				// Filedata + uploadName
				$upload_name = '';
				$filedata = '';
				if($item['filedata']) {
					$filedata = [];
					$fdata = wireDecodeJSON($item['filedata']);
					foreach($fdata as $key => $value) {
						if(is_array($value)) $value = array_filter($value);
						if(!$value) continue;
						if($key === 'uploadName') {
							$upload_name = $value;
						} elseif(isset($custom_fields[$field_name][$key])) {
							if($has_languages && is_array($value)) {
								foreach($value as $k => $v) {
									if(is_numeric($k)) continue;
									unset($value[$k]);
									$k = str_replace('data', '', $k);
									if($k === '') $k = 'default';
									else $k = $langs[$k] ?? $k;
									$value[$k] = $v;
								}
							}
							$filedata[$custom_fields[$field_name][$key]] = $value;
						}
					}
					$filedata = $filedata ? $this->arrayToText($filedata) : '';
				}

				$row = [
					'title' => $title,
					'prefix' => $prefix,
					'edit_url' => $edit_url,
					'pages_id' => (int) $item['pages_id'],
					'owner_id' => (int) $page_id,
					'field' => $field_name,
					'filename' => $item['data'],
					'uploadname' => $upload_name,
					'description' => $description,
					'filedata' => $filedata,
					'tags' => $item['tags'] ?? '',
					'filesize' => (int) $item['filesize'],
					'modified' => $item['modified'],
					'created' => $item['created'],
					'sort' => (int) $item['sort'],
				];

				if($is_images) {
					$row['width'] = (int) $item['width'];
					$row['height'] = (int) $item['height'];
					$row['ratio'] = (float) $item['ratio'];
					// Thumbnail URLs
					$pieces = explode('.', $item['data']);
					$ext = strtolower(end($pieces));
					$image_url = $asset_url . $item['pages_id'] . '/' . rawurlencode($item['data']);
					if($ext === 'svg') {
						$thumb_url = $image_url;
					} else {
						if(!empty($item['ratio'])) {
							$thumb_size = $item['ratio'] < 1 ? ".{$this->thumbsize}x0." : ".0x{$this->thumbsize}.";
						} else {
							$thumb_size = ".0x{$this->thumbsize}.";
						}
						$thumb_name = $this->variationFilename($item['data'], $thumb_size);
						$thumb_url = $asset_url . $item['pages_id'] . '/' . rawurlencode($thumb_name);
						if(!is_file($config->paths->files . $item['pages_id'] . '/' . $thumb_name)) {
							$thumb_size = $thumb_size === ".{$this->thumbsize}x0." ? ".0x{$this->thumbsize}." : ".{$this->thumbsize}x0.";
							$thumb_name = $this->variationFilename($item['data'], $thumb_size);
							$thumb_url = $asset_url . $item['pages_id'] . '/' . rawurlencode($thumb_name);
						}
						if(!is_file($config->paths->files . $item['pages_id'] . '/' . $thumb_name)) $thumb_url = $image_url;
					}
					$row['image_url'] = $image_url;
					$row['thumb_url'] = $thumb_url;
				}

				$out['rows'][] = $row;
			}
		}
		return $out;
	}

}
