<?php namespace ProcessWire;

trait PanoramaMediaUtilities {
	/* ============================================================ *
	 *  DISK / DB SCANNING (shared by Cleanup & Duplicates)
	 * ============================================================ */

	/**
	 * Walk the assets/files directory once, classifying every file.
	 *
	 * @return array [
	 *   'diskByPage'      => [pageId => [filename => path]],
	 *   'originals'       => [ ['path','page_id','filename','bytes'] ],
	 *   'orphanVariations'=> [ ['path','page_id','filename','bytes'] ],
	 * ]
	 */
	protected function scanDisk() {
		$result = ['diskByPage' => [], 'originals' => [], 'orphanVariations' => [], 'variationsByPage' => []];
		$filesPath = $this->wire()->config->paths->files;
		if(!is_dir($filesPath)) return $result;

		$dirs = [];
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($filesPath, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach($iterator as $file) {
				if($file->isFile()) $dirs[$file->getPath()][] = $file->getFilename();
			}
		} catch(\Exception $e) {
			return $result;
		}

		foreach($dirs as $dir => $filenames) {
			$pageId = $this->pageIdFromPath($dir);
			$stems = [];
			$variations = [];
			foreach($filenames as $name) {
				$path = $dir . DIRECTORY_SEPARATOR . $name;
				$bytes = is_file($path) ? (int) filesize($path) : 0;
				$result['diskByPage'][$pageId][$name] = $path;
				$base = $this->variationBase($name);
				if($base === null) {
					$stems[pathinfo($name, PATHINFO_FILENAME)] = true;
					$result['originals'][] = ['path' => $path, 'page_id' => $pageId, 'filename' => $name, 'bytes' => $bytes];
				} else {
					$variations[] = ['path' => $path, 'page_id' => $pageId, 'filename' => $name, 'bytes' => $bytes, 'base' => $base];
				}
			}
			foreach($variations as $v) {
				// Per-image variation summary (count + bytes), keyed by original stem
				if(!isset($result['variationsByPage'][$pageId][$v['base']])) {
					$result['variationsByPage'][$pageId][$v['base']] = ['count' => 0, 'bytes' => 0];
				}
				$result['variationsByPage'][$pageId][$v['base']]['count']++;
				$result['variationsByPage'][$pageId][$v['base']]['bytes'] += $v['bytes'];
				if(!isset($stems[$v['base']])) {
					unset($v['base']);
					$result['orphanVariations'][] = $v;
				}
			}
		}

		usort($result['orphanVariations'], function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		usort($result['originals'], function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		return $result;
	}

	/**
	 * Index every media file recorded in the database.
	 *
	 * @return array ['byPage' => [pageId => [filename => true]], 'rows' => [ ['pages_id','field','filename','bytes'] ]]
	 */
	protected function getDbFileIndex() {
		$database = $this->wire()->database;
		$flds = $this->wire()->fields->find('type=FieldtypeImage|FieldtypeFile');
		$index = ['byPage' => [], 'rows' => []];
		foreach($flds as $field) {
			$table = $database->escapeTable("field_{$field->name}");
			$query = $database->query("SELECT pages_id, data, filesize FROM `$table`");
			foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$pid = (int) $row['pages_id'];
				$index['byPage'][$pid][$row['data']] = true;
				$index['rows'][] = ['pages_id' => $pid, 'field' => $field->name, 'filename' => $row['data'], 'bytes' => (int) $row['filesize']];
			}
		}
		return $index;
	}

	/**
	 * Resolve page references (title + edit URL) for a set of page ids,
	 * walking repeater pages up to their editable root owner.
	 *
	 * @param array $pageIds
	 * @return array [pageId => ['title' => string, 'edit' => string]]
	 */
	protected function pageRefs(array $pageIds) {
		$pageIds = array_unique(array_filter(array_map('intval', $pageIds)));
		if(!$pageIds) return [];
		$owners = $this->getRepeaterConnections()['owners'];
		$rootOf = [];
		$rootIds = [];
		foreach($pageIds as $pid) {
			$root = isset($owners[$pid]) ? (int) reset($owners[$pid]) : $pid;
			$rootOf[$pid] = $root;
			$rootIds[$root] = true;
		}
		$titles = [];
		if($rootIds) {
			$ids = implode(',', array_map('intval', array_keys($rootIds)));
			$titles = $this->wire()->database->query("SELECT pages_id, data FROM field_title WHERE pages_id IN($ids)")->fetchAll(\PDO::FETCH_KEY_PAIR);
		}
		$adminUrl = $this->wire()->config->urls->admin;
		$refs = [];
		foreach($pageIds as $pid) {
			$root = $rootOf[$pid];
			$refs[$pid] = [
				'id' => $root,
				'title' => isset($titles[$root]) ? $titles[$root] : ('#' . $root),
				'edit' => "{$adminUrl}page/edit/?id=$root",
			];
		}
		return $refs;
	}

	/**
	 * A small stat tile used across the Cleanup / Duplicates / Audit tabs
	 */
	protected function statTile($icon, $label, $value, $sub) {
		return "<div><div class='uk-card uk-card-default uk-card-small uk-card-body panorama-tile'>"
			. "<div class='uk-flex uk-flex-between uk-flex-middle'><span class='uk-text-meta panorama-tile-label'>$label</span>" . $this->icon($icon) . "</div>"
			. "<div class='uk-text-large uk-text-bold panorama-tile-value'>$value</div>"
			. "<div class='uk-text-meta panorama-tile-sub'>$sub</div>"
			. "</div></div>";
	}

	/**
	 * Return the base original name for a variation filename, or null if not a variation
	 *
	 * @param string $name
	 * @return string|null
	 */
	protected function variationBase($name) {
		if(preg_match('/^(.+?)\.\d+x\d+(?:[-.][a-zA-Z0-9]+)*\.[a-zA-Z0-9]+$/', $name, $m)) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Best-effort page id from a files directory path
	 *
	 * @param string $dir
	 * @return int
	 */
	protected function pageIdFromPath($dir) {
		$base = basename($dir);
		if(ctype_digit($base)) return (int) $base;
		// Extended paths: digits are spread across nested folders
		$digits = preg_replace('/\D/', '', str_replace($this->wire()->config->paths->files, '', $dir));
		return ctype_digit($digits) ? (int) $digits : 0;
	}

	/* ============================================================ *
	 *  SHARED HELPERS
	 * ============================================================ */

	/**
	 * Get owner connections for all Repeater-type pages
	 */
	protected function getRepeaterConnections() {
		$data = ['owners' => [], 'repeaters' => []];
		$repeater_fields = $this->wire()->fields->find('type=FieldtypeRepeater|FieldtypeRepeaterMatrix|FieldtypeFieldsetPage');
		if(!$repeater_fields->count) return $data;

		$queries = [];
		foreach($repeater_fields as $repeater_field) {
			$table = $this->wire()->database->escapeTable("field_{$repeater_field->name}");
			// Repeater tables can retain different text collations after site
			// migrations. Compare their numeric ID lists as binary data so the
			// UNION does not require MySQL to reconcile those collations.
			$queries[] = "SELECT pages_id, CAST(data AS BINARY) AS data FROM `$table`";
		}
		$results = $this->wire()->database->query(implode(' UNION ALL ', $queries));
		$rows = $results->fetchAll(\PDO::FETCH_ASSOC);

		$connections = [];
		foreach($rows as $item) {
			$repeater_ids = explode(',', $item['data']);
			foreach($repeater_ids as $id) {
				$id = trim($id);
				if($id === '' || !ctype_digit($id)) continue;
				$connections[$id] = (int) $item['pages_id'];
			}
		}
		foreach($connections as $repeater_id => $owner_id) {
			$data['owners'][$repeater_id] = [$owner_id];
			$id = $owner_id;
			while(isset($connections[$id])) {
				$id = $connections[$id];
				array_unshift($data['owners'][$repeater_id], $id);
			}
		}
		foreach($data['owners'] as $repeater_id => $owner_ids) {
			$owner_id = array_shift($owner_ids);
			if(!isset($data['repeaters'][$owner_id])) $data['repeaters'][$owner_id] = [];
			$data['repeaters'][$owner_id][$repeater_id] = '';
			foreach($owner_ids as $id) $data['repeaters'][$owner_id][$id] = '';
		}
		foreach($data['repeaters'] as $key => $value) {
			$data['repeaters'][$key] = array_keys($value);
		}
		return $data;
	}

	/**
	 * Convert an array to roughly-YAML text
	 */
	protected function arrayToText($data, $level = 0) {
		$prefix = str_repeat('  ', $level);
		$yaml = '';
		foreach($data as $key => $value) {
			if(is_array($value)) {
				$yaml .= "{$prefix}{$key}:\n";
				$yaml .= $this->arrayToText($value, $level + 1);
			} else {
				$yaml .= "{$prefix}{$key}: $value\n";
			}
		}
		return $yaml;
	}

	/**
	 * Build a ProcessWire variation filename without corrupting dotted basenames.
	 *
	 * @param string $filename Original filename
	 * @param string $suffix Variation suffix, e.g. ".260x0."
	 * @return string
	 */
	protected function variationFilename($filename, $suffix) {
		$info = pathinfo($filename);
		$base = $info['filename'] ?? $filename;
		$ext = $info['extension'] ?? '';
		return $ext !== '' ? $base . $suffix . $ext : $base . rtrim($suffix, '.');
	}

	/**
	 * Return an existing thumbnail URL without generating a new image variation.
	 *
	 * @param Pageimage $image
	 * @param int $size
	 * @return string
	 */
	protected function existingImageThumbUrl(Pageimage $image, $size = 80) {
		if(strtolower($image->ext) === 'svg') return $image->url;
		$name = basename($image->filename);
		$dir = dirname($image->filename) . DIRECTORY_SEPARATOR;
		$urlDir = rtrim(dirname($image->url), '/') . '/';
		$candidates = [
			$this->variationFilename($name, ".{$size}x{$size}."),
			$this->variationFilename($name, ".{$size}x0."),
			$this->variationFilename($name, ".0x{$size}."),
		];
		foreach($candidates as $candidate) {
			if(is_file($dir . $candidate)) return $urlDir . rawurlencode($candidate);
		}
		return $image->url;
	}

	/**
	 * Return an existing thumbnail URL for a disk file without loading Pageimage.
	 *
	 * @param int $pageId
	 * @param string $filename
	 * @param int $size
	 * @return string
	 */
	protected function existingDiskThumbUrl($pageId, $filename, $size = 80) {
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$pageId = (int) $pageId;
		if(!$pageId || !$filename) return '';
		if($ext === 'svg') return $this->wire()->config->urls->files . $pageId . '/' . rawurlencode($filename);
		$dir = $this->wire()->config->paths->files . $pageId . '/';
		$urlDir = $this->wire()->config->urls->files . $pageId . '/';
		$candidates = [
			$this->variationFilename($filename, ".{$size}x{$size}."),
			$this->variationFilename($filename, ".{$size}x0."),
			$this->variationFilename($filename, ".0x{$size}."),
			$this->variationFilename($filename, ".260x0."),
			$this->variationFilename($filename, ".0x260."),
		];
		foreach($candidates as $candidate) {
			if(is_file($dir . $candidate)) return $urlDir . rawurlencode($candidate);
		}
		return '';
	}

	/**
	 * Normalize and validate a client-supplied bulk item.
	 *
	 * @param mixed $item
	 * @return array|null
	 */
	protected function normalizeBulkItem($item) {
		if(!is_array($item)) return null;
		$sanitizer = $this->wire()->sanitizer;
		$id = (int) ($item['id'] ?? 0);
		$field = $sanitizer->fieldName((string) ($item['field'] ?? ''));
		$name = $this->safeBasename($item['name'] ?? '');
		if(!$id || !$field || !$name) return null;
		return ['id' => $id, 'field' => $field, 'name' => $name];
	}

	/**
	 * Whether the current user may view a file on this page/field.
	 */
	protected function canViewPageFile(Page $page, $fieldName) {
		if(!$page->id || !$fieldName) return false;
		if($this->wire()->user->isSuperuser()) return true;
		$owner = $this->rootOwner($page);
		if(!$owner || !$owner->id || !$owner->viewable()) return false;
		if($page->viewable($fieldName)) return true;
		return $page instanceof RepeaterPage && $owner->viewable();
	}

	/**
	 * Whether the current user may mutate a file on this page/field.
	 */
	protected function canEditPageFile(Page $page, $fieldName) {
		if(!$page->id || !$fieldName) return false;
		if($this->wire()->user->isSuperuser()) return true;
		$owner = $this->rootOwner($page);
		if(!$owner || !$owner->id || !$owner->editable()) return false;
		if($page->editable($fieldName)) return true;
		return $page instanceof RepeaterPage && $owner->editable();
	}

	/**
	 * Resolve the editable root owner of a page (walks up out of repeater pages)
	 */
	protected function rootOwner(Page $page) {
		$guard = 0;
		while($page instanceof RepeaterPage && $guard++ < 20) {
			$page = $page->getForPage();
		}
		return $page;
	}
}
