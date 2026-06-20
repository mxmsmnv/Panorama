<?php namespace ProcessWire;

trait PanoramaBulkActions {
	/* ============================================================ *
	 *  BULK ACTIONS (from the lister)
	 * ============================================================ */

	/**
	 * Perform a bulk action on selected media items
	 *
	 * Expects POST: action, items (JSON of [{id, field, name}]), value, CSRF token.
	 * Responds with JSON, except the "zip" action which streams a download.
	 */
	public function ___executeBulk() {
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		$pages = $this->wire()->pages;
		$sanitizer = $this->wire()->sanitizer;

		$action = $input->post('action');
		$items = json_decode((string) $input->post('items'), true) ?: [];
		$value = $sanitizer->text($input->post('value'));
		$valid = $session->CSRF->getTokenValue() === $input->post($session->CSRF->getTokenName());
		$actions = ['delete', 'variations', 'tag-add', 'tag-remove', 'zip'];

		if(!in_array($action, $actions, true)) {
			http_response_code(400);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['success' => false, 'message' => $this->_('Unknown action.')]);
			exit;
		}

		if($action === 'zip') {
			if($valid && $items) $this->streamZip($items);
			http_response_code(400);
			exit;
		}

		header('Content-Type: application/json; charset=utf-8');
		if(!$valid) { echo json_encode(['success' => false, 'message' => $this->_('CSRF validation failed.')]); exit; }

		$byPage = [];
		foreach($items as $it) {
			$pid = (int) ($it['id'] ?? 0);
			if($pid) $byPage[$pid][] = $it;
		}

		$count = 0;
		$skipped = 0;
		foreach($byPage as $pid => $list) {
			$page = $pages->get($pid);
			if(!$page->id) continue;
			$page->of(false);
			$changed = false;
			foreach($list as $it) {
				$it = $this->normalizeBulkItem($it);
				if(!$it) { $skipped++; continue; }
				if(!$this->canEditPageFile($page, $it['field'])) { $skipped++; continue; }
				$pf = $page->get($it['field']);
				if(!($pf instanceof Pagefiles)) continue;
				$file = $pf->getFile($it['name']);
				if(!$file) continue;
				switch($action) {
					case 'delete': $pf->delete($file); $changed = true; $count++; break;
					case 'variations': if($file instanceof Pageimage) { $file->removeVariations(); $count++; } break;
					case 'tag-add': if($value) { $file->addTag($value); $changed = true; $count++; } break;
					case 'tag-remove': if($value) { $file->removeTag($value); $changed = true; $count++; } break;
				}
			}
			if($changed) $page->save();
		}
		echo json_encode(['success' => true, 'count' => $count, 'skipped' => $skipped]);
		exit;
	}

	/**
	 * Stream a ZIP of the selected files as a download
	 *
	 * @param array $items
	 */
	protected function streamZip(array $items) {
		$pages = $this->wire()->pages;
		$files = $this->wire()->files;
		$paths = [];
		foreach($items as $it) {
			$it = $this->normalizeBulkItem($it);
			if(!$it) continue;
			$page = $pages->get($it['id']);
			if(!$page->id) continue;
			if(!$this->canViewPageFile($page, $it['field'])) continue;
			$pf = $page->get($it['field']);
			if(!($pf instanceof Pagefiles)) continue;
			$file = $pf->getFile($it['name']);
			if($file && is_file($file->filename)) $paths[$file->filename] = $file->filename;
		}
		if(!$paths) { http_response_code(404); exit; }
		$zipPath = $this->wire()->config->paths->cache . 'panorama-' . date('Ymd-His') . '.zip';
		$files->zip($zipPath, array_values($paths));
		if(!is_file($zipPath)) { http_response_code(500); exit; }
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="panorama-media.zip"');
		header('Content-Length: ' . filesize($zipPath));
		readfile($zipPath);
		@unlink($zipPath);
		exit;
	}

}
