<?php namespace ProcessWire;

/**
 * Panorama: Hooks
 *
 * Helper class (not a standalone module) attached by the Panorama module on admin
 * requests. When a page is opened for editing from the Panorama lister with a
 * `panorama_open` query parameter (used for media held in nested repeaters), this
 * makes sure those repeater items are expanded so the user lands on the relevant
 * field.
 *
 * Ported from Robin Sallis' MediaListerHooks.
 *
 * @author Maxim Semenov
 */
class RepeaterOpener extends Wire {

	protected $open = false;

	/**
	 * Attach the ProcessPageEdit hooks
	 */
	public function attach() {
		$this->addHookBefore('ProcessPageEdit::execute', $this, 'beforePageEdit');
		$this->addHookAfter('ProcessPageEdit::execute', $this, 'afterPageEdit');
	}

	/**
	 * Before ProcessPageEdit::execute
	 *
	 * @param HookEvent $event
	 */
	protected function beforePageEdit(HookEvent $event) {
		if($this->wire()->config->ajax) return;
		$open_ids = $this->wire()->sanitizer->intArray($this->wire()->input->get('panorama_open'));
		if(!$open_ids) return;
		$session = $this->wire()->session;
		$session->setFor('InputfieldRepeater', 'openIDs', $open_ids);
		$session->setFor('InputfieldRepeaterMatrix', 'openIDs', $open_ids);
		$this->open = true;
	}

	/**
	 * After ProcessPageEdit::execute
	 *
	 * @param HookEvent $event
	 */
	protected function afterPageEdit(HookEvent $event) {
		if(!$this->open) return;
		$session = $this->wire()->session;
		$session->setFor('InputfieldRepeater', 'openIDs', []);
		$session->setFor('InputfieldRepeaterMatrix', 'openIDs', []);
	}

}
