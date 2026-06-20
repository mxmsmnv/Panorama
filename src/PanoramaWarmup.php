<?php namespace ProcessWire;

trait PanoramaWarmup {
	/* ============================================================ *
	 *  WARMUP — background image variation generation
	 * ============================================================ */

	public function ___executeWarmup() {
		$config = $this->wire()->config;
		$input = $this->wire()->input;

		if($input->get('action')) {
			$action = (string) $input->get('action');
			header('Content-Type: application/json; charset=utf-8');
			if($action === 'start') echo json_encode($this->startWarmupJob());
			elseif($action === 'batch') echo json_encode($this->runWarmupBatch());
			else {
				http_response_code(400);
				echo json_encode(['success' => false, 'message' => $this->_('Unknown action.')]);
			}
			exit;
		}

		$this->headline($this->_('Warmup'));
		$this->browserTitle($this->wire()->page->title . ' - ' . $this->_('Warmup'));

		$config->styles->add($this->assetUrl('assets/css/Panorama.css'));
		$session = $this->wire()->session;
		$config->js('PanoramaWarmup', [
			'startUrl' => $this->base() . 'warmup/?action=start',
			'batchUrl' => $this->base() . 'warmup/?action=batch',
			'token' => [
				'name' => $session->CSRF->getTokenName(),
				'value' => $session->CSRF->getTokenValue(),
			],
			'labels' => [
				'running' => $this->_('Warming images…'),
				'done' => $this->_('Warmup complete.'),
				'error' => $this->_('Warmup failed.'),
				'requestFailed' => $this->_('Request failed.'),
				'generated' => $this->_('Generated'),
				'skipped' => $this->_('Skipped'),
				'failed' => $this->_('Failed'),
			],
		]);

		$out = $this->renderTabs('warmup');
		$out .= $this->renderWarmupPanel();
		$out .= "<script type='module' src='" . $this->assetUrl('assets/js/warmup.js') . "'></script>";
		return $this->wrap($out);
	}

	protected function renderWarmupPanel() {
		$templates = $this->wire()->templates;
		$fields = $this->wire()->fields;
		$modules = $this->wire()->modules;

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->id = 'panorama-warmup-form';
		$form->method = 'post';

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->name = 'template_id';
		$f->label = $this->_('Template');
		$f->columnWidth = 50;
		$firstTemplateId = 0;
		foreach($templates as $template) {
			if(defined(Template::class . '::flagSystem') && ($template->flags & Template::flagSystem)) continue;
			$id = (int) $template->id;
			if(!$firstTemplateId) $firstTemplateId = $id;
			$f->addOption($id, $template->getLabel() ?: $template->name);
			if($template->name === 'product') $f->value = $id;
		}
		if(!$f->value && $firstTemplateId) $f->value = $firstTemplateId;
		$form->add($f);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->name = 'field_name';
		$f->label = $this->_('Image field');
		$f->columnWidth = 50;
		$firstFieldName = '';
		foreach($fields as $field) {
			if(!$field->type instanceof FieldtypeImage) continue;
			if($firstFieldName === '') $firstFieldName = $field->name;
			$f->addOption($field->name, $field->getLabel() ?: $field->name);
			if($field->name === 'images') $f->value = $field->name;
		}
		if(!$f->value && $firstFieldName !== '') $f->value = $firstFieldName;
		$form->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->name = 'width';
		$f->label = $this->_('Width');
		$f->inputType = 'number';
		$f->min = 1;
		$f->max = 4096;
		$f->value = 500;
		$f->columnWidth = 33;
		$form->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->name = 'height';
		$f->label = $this->_('Height');
		$f->inputType = 'number';
		$f->min = 1;
		$f->max = 4096;
		$f->value = 500;
		$f->columnWidth = 33;
		$form->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->name = 'batch';
		$f->label = $this->_('Batch size');
		$f->inputType = 'number';
		$f->min = 1;
		$f->max = 100;
		$f->value = 25;
		$f->columnWidth = 34;
		$form->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'force';
		$f->label = $this->_('Force regenerate existing variations');
		$f->attr('value', 1);
		$f->columnWidth = 100;
		$form->add($f);

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->name = 'start_warmup';
		$f->value = $this->_('Start warmup');
		$f->icon = 'bolt';
		$form->add($f);

		return $this->panelHeader($this->_('Image cache warmup'), 'bolt')
			. '<p class="panorama-note">' . $this->_('Generate image variations in background batches. Defaults match product → images → 500×500.') . '</p>'
			. $form->render()
			. '<div class="panorama-warmup-status" hidden>'
			. '<div class="panorama-warmup-line"><strong data-panorama-warmup-title></strong><span data-panorama-warmup-count></span></div>'
			. '<progress class="uk-progress panorama-progress" value="0" max="100" data-panorama-warmup-progress></progress>'
			. '<div class="panorama-warmup-stats uk-text-meta" data-panorama-warmup-stats></div>'
			. '</div></div>';
	}

	protected function startWarmupJob() {
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		if($session->CSRF->getTokenValue() !== $input->post($session->CSRF->getTokenName())) {
			return ['success' => false, 'message' => $this->_('CSRF validation failed.')];
		}

		$templateValue = (string) $input->post('template_id');
		$template = null;
		if(ctype_digit($templateValue)) {
			foreach($this->wire()->templates as $tpl) {
				if((int) $tpl->id === (int) $templateValue) {
					$template = $tpl;
					break;
				}
			}
		} else {
			$template = $this->wire()->templates->get($this->wire()->sanitizer->name($templateValue));
		}
		$templateName = $template ? $template->name : '';
		$fieldName = $this->wire()->sanitizer->fieldName((string) $input->post('field_name'));
		$field = $this->wire()->fields->get($fieldName);
		if(!$template || !$field || !($field->type instanceof FieldtypeImage)) {
			return ['success' => false, 'message' => $this->_('Template or image field is not valid.')];
		}

		$width = max(1, min(4096, (int) $input->post('width')));
		$height = max(1, min(4096, (int) $input->post('height')));
		$batch = max(1, min(100, (int) $input->post('batch')));
		$force = (bool) $input->post('force');
		$ids = $this->warmupPageIds($templateName, $fieldName);
		$job = bin2hex(random_bytes(8));
		$data = [
			'ids' => $ids,
			'template' => $templateName,
			'field' => $fieldName,
			'width' => $width,
			'height' => $height,
			'batch' => $batch,
			'force' => $force,
			'offset' => 0,
			'processed' => 0,
			'generated' => 0,
			'skipped' => 0,
			'failed' => 0,
		];
		$this->wire()->cache->save("Panorama.warmup.$job", $data, 3600);
		return ['success' => true, 'job' => $job, 'total' => count($ids), 'batch' => $batch];
	}

	protected function warmupPageIds($templateName, $fieldName) {
		try {
			return $this->wire()->pages->findIDs("template=$templateName, $fieldName.count>0, include=all, limit=0");
		} catch(\Exception $e) {
			$ids = [];
			foreach($this->wire()->pages->find("template=$templateName, include=all, limit=0") as $page) {
				$value = $page->getUnformatted($fieldName);
				if($value instanceof Pageimage) $ids[] = (int) $page->id;
				elseif($value instanceof Pageimages && $value->count()) $ids[] = (int) $page->id;
			}
			return $ids;
		}
	}

	protected function warmupPageImage($pageId, $fieldName, $width, $height, $force) {
		$page = $this->wire()->pages->get((int) $pageId);
		if(!$page->id) return 'failed';
		$value = $page->getUnformatted($fieldName);
		$image = null;
		if($value instanceof Pageimage) $image = $value;
		elseif($value instanceof Pageimages) $image = $value->first();
		if(!$image instanceof Pageimage) return 'skipped';
		if(strtolower($image->ext) === 'svg') return 'skipped';

		$cachedName = $this->variationFilename(basename($image->filename), ".{$width}x{$height}.");
		$cachedPath = dirname($image->filename) . DIRECTORY_SEPARATOR . $cachedName;
		$exists = is_file($cachedPath);
		if($force && $exists) {
			@unlink($cachedPath);
			$exists = false;
		}

		try {
			$image->size($width, $height);
		} catch(\Exception $e) {
			return 'failed';
		}

		if($exists) return 'skipped';
		return is_file($cachedPath) ? 'generated' : 'failed';
	}

	protected function runWarmupBatch() {
		@set_time_limit(120);
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		if($session->CSRF->getTokenValue() !== $input->post($session->CSRF->getTokenName())) {
			return ['success' => false, 'message' => $this->_('CSRF validation failed.')];
		}

		$job = preg_replace('/[^a-f0-9]/', '', (string) $input->post('job'));
		$key = "Panorama.warmup.$job";
		$data = $job ? $this->wire()->cache->get($key) : null;
		if(!is_array($data) || empty($data['ids'])) return ['success' => false, 'message' => $this->_('Warmup job expired.')];

		$ids = $data['ids'];
		$total = count($ids);
		$batchIds = array_slice($ids, (int) $data['offset'], (int) $data['batch']);
		foreach($batchIds as $id) {
			$result = $this->warmupPageImage((int) $id, $data['field'], (int) $data['width'], (int) $data['height'], (bool) $data['force']);
			$data['processed']++;
			$data[$result]++;
		}
		$data['offset'] += count($batchIds);
		$done = $data['offset'] >= $total;
		if($done) $this->wire()->cache->delete($key);
		else $this->wire()->cache->save($key, $data, 3600);

		return [
			'success' => true,
			'done' => $done,
			'total' => $total,
			'processed' => (int) $data['processed'],
			'generated' => (int) $data['generated'],
			'skipped' => (int) $data['skipped'],
			'failed' => (int) $data['failed'],
		];
	}

	/**
	 * Delete a list of files from disk (items with a 'path' key)
	 *
	 * @param array $items
	 */
	protected function deleteFiles(array $items) {
		$deleted = 0;
		$freed = 0;
		foreach($items as $it) {
			if(!empty($it['path']) && is_file($it['path']) && @unlink($it['path'])) {
				$deleted++;
				$freed += (int) ($it['bytes'] ?? 0);
			}
		}
		$this->message(sprintf($this->_('Deleted %1$d files, freeing %2$s.'), $deleted, $this->formatBytes($freed)));
	}

	/**
	 * Remove broken file references from their pages via the API
	 *
	 * @param array $broken
	 */
	protected function deleteBrokenRefs(array $broken) {
		$pages = $this->wire()->pages;
		$byPage = [];
		foreach($broken as $b) $byPage[$b['pages_id']][] = $b;
		$removed = 0;
		$skipped = 0;
		foreach($byPage as $pid => $items) {
			$page = $pages->get((int) $pid);
			if(!$page->id) continue;
			$page->of(false);
			$changed = false;
			foreach($items as $it) {
				if(!$this->canEditPageFile($page, $it['field'])) { $skipped++; continue; }
				$pf = $page->get($it['field']);
				if($pf instanceof Pagefiles) {
					$file = $pf->getFile($it['filename']);
					if($file) { $pf->delete($file); $changed = true; $removed++; }
				}
			}
			if($changed) $page->save();
		}
		$this->message(sprintf($this->_('Removed %d broken references.'), $removed));
		if($skipped) $this->warning(sprintf($this->_('Skipped %d files because the current user cannot edit their page.'), $skipped));
	}

}
