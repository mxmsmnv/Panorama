<?php namespace ProcessWire;

require_once __DIR__ . '/src/PanoramaCommon.php';
require_once __DIR__ . '/src/PanoramaIcons.php';
require_once __DIR__ . '/src/PanoramaDashboard.php';
require_once __DIR__ . '/src/PanoramaExplorer.php';
require_once __DIR__ . '/src/PanoramaCleanup.php';
require_once __DIR__ . '/src/PanoramaWarmup.php';
require_once __DIR__ . '/src/PanoramaDuplicates.php';
require_once __DIR__ . '/src/PanoramaAudit.php';
require_once __DIR__ . '/src/PanoramaBulkActions.php';
require_once __DIR__ . '/src/PanoramaMediaUtilities.php';

/**
 * Panorama
 *
 * A panoramic view of all media (images and files) across a ProcessWire site.
 *
 * Tabs:
 *  - Dashboard  : totals, disk usage, breakdown by file type and field, largest /
 *                 recent media.
 *  - Explorer   : browse media by page, by template, or as a gallery; click an
 *                 image to see its owner page and variation sizes, with per-image
 *                 variation cleanup, bulk actions and CSV export.
 *  - Duplicates : identical files grouped by content hash.
 *  - Audit      : images missing alt text (description).
 *  - Cleanup    : broken references, orphaned originals and orphaned variations.
 *  - Warmup     : background generation of image variations.
 *
 * @author Maxim Semenov
 *
 * @property string $defaultMediaType
 * @property string $defaultViewMode
 * @property int $listCount
 */
class Panorama extends Process implements ConfigurableModule {

	use PanoramaCommon;
	use PanoramaIcons;
	use PanoramaDashboard;
	use PanoramaExplorer;
	use PanoramaCleanup;
	use PanoramaWarmup;
	use PanoramaDuplicates;
	use PanoramaAudit;
	use PanoramaBulkActions;
	use PanoramaMediaUtilities;

	/**
	 * Module info
	 *
	 * @return array
	 */
	public static function getModuleInfo() {
		return [
			'title' => 'Panorama',
			'summary' => __('Media audit and maintenance toolkit for ProcessWire images and files: inspect usage, find duplicates, clean orphaned media, audit alt text and warm image caches.'),
			'version' => 110,
			'author' => 'Maxim Semenov',
			'icon' => 'tachometer',
			'requires' => 'ProcessWire>=3.0.227, PHP>=8.3.0',
			'autoload' => 'template=admin',
			'page' => [
				'name' => 'panorama',
				'title' => 'Panorama',
				'parent' => 'setup',
			],
			'permission' => 'panorama',
			'permissions' => [
				'panorama' => __('Use the Panorama media dashboard and explorer'),
			],
		];
	}

	/**
	 * Column labels
	 */
	protected $columnLabels = [];

	/**
	 * Other labels
	 */
	protected $otherLabels = [];

	/**
	 * The "gridSize" of the admin thumbnail
	 */
	protected $thumbsize;

	/**
	 * Default number of items shown in the dashboard "largest"/"recent" lists
	 */
	protected $defaultListCount = 12;

	/**
	 * Construct
	 */
	public function __construct() {
		$this->columnLabels = [
			'page' => $this->_('Page'),
			'field' => $this->_('Field'),
			'filename' => $this->_('Filename'),
			'uploadname' => $this->_('Upload name'),
			'thumbnail' => $this->_('Thumbnail'),
			'description' => $this->_('Description'),
			'filedata' => $this->_('Filedata'),
			'tags' => $this->_('Tags'),
			'filesize' => $this->_('Filesize'),
			'width' => $this->_('Width'),
			'height' => $this->_('Height'),
			'ratio' => $this->_('Ratio'),
			'modified' => $this->_('Modified'),
			'created' => $this->_('Created'),
			'sort' => $this->_('Sort'),
		];
		$this->otherLabels = [
			'images' => $this->_('Images'),
			'files' => $this->_('Files'),
			'small_thumbs' => $this->_('Small thumbnails'),
			'large_thumbs' => $this->_('Large thumbnails'),
			'table' => $this->_('Table'),
		];
		$thumbsize = $this->wire()->config->adminThumbOptions('gridSize') ?: 130;
		$this->thumbsize = $thumbsize * 2;
		$this->set('defaultMediaType', 'images');
		$this->set('defaultViewMode', 'gallery');
		$this->set('listCount', $this->defaultListCount);
		parent::__construct();
	}

	/**
	 * Ready — attach the page-edit hooks (module is autoloaded on admin)
	 */
	public function ready() {
		require_once __DIR__ . '/src/RepeaterOpener.php';
		$hooks = $this->wire(new RepeaterOpener());
		$hooks->attach();
	}

	/**
	 * Base URL of this process page (without URL segments)
	 *
	 * @return string
	 */
	protected function base() {
		return $this->wire()->page->url;
	}

	/**
	 * Render the tab navigation
	 *
	 * @param string $current  one of: dashboard, lister, variations
	 * @return string
	 */
	protected function renderTabs($current) {
		$base = $this->base();
		$tabs = [
			'dashboard' => ['', 'tachometer', $this->_('Dashboard')],
			'explorer' => ['explorer/', 'th', $this->_('Explorer')],
			'duplicates' => ['duplicates/', 'clone', $this->_('Duplicates')],
			'audit' => ['audit/', 'universal-access', $this->_('Audit')],
			'cleanup' => ['cleanup/', 'trash-o', $this->_('Cleanup')],
			'warmup' => ['warmup/', 'bolt', $this->_('Warmup')],
		];
		$out = '<ul class="uk-tab panorama-tabs" aria-label="' . $this->_('Panorama sections') . '">';
		foreach($tabs as $key => $tab) {
			list($seg, $icon, $label) = $tab;
			$class = $key === $current ? ' class="uk-active"' : '';
			$out .= "<li$class><a href='{$base}{$seg}'>" . $this->tabIcon($key) . " <span>$label</span></a></li>";
		}
		$out .= '</ul>';
		return $out;
	}

	/* ============================================================ *
	 *  CONFIG
	 * ============================================================ */

	/**
	 * Module config
	 *
	 * @param InputfieldWrapper $inputfields
	 */
	public function getModuleConfigInputfields($inputfields) {
		$modules = $this->wire()->modules;

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->name = 'listCount';
		$f->label = $this->_('Dashboard list size');
		$f->description = $this->_('How many items to show in the dashboard "Largest files" and "Recent uploads" lists.');
		$f->icon = 'list-ol';
		$f->inputType = 'number';
		$f->min = 1;
		$f->max = 100;
		$f->value = (int) $this->listCount ?: $this->defaultListCount;
		$f->columnWidth = 50;
		$inputfields->add($f);

		/** @var InputfieldRadios $f */
		$f = $modules->get('InputfieldRadios');
		$f->name = 'defaultMediaType';
		$f->label = $this->_('Default media type');
		$f->icon = 'file-o';
		$f->addOption('images', $this->otherLabels['images']);
		$f->addOption('files', $this->otherLabels['files']);
		$f->optionColumns = 1;
		$f->value = $this->defaultMediaType;
		$f->columnWidth = 50;
		$inputfields->add($f);

		/** @var InputfieldRadios $f */
		$f = $modules->get('InputfieldRadios');
		$f->name = 'defaultViewMode';
		$f->label = $this->_('Default Explorer view');
		$f->icon = 'eye';
		$f->addOption('page', $this->_('By page'));
		$f->addOption('template', $this->_('By template'));
		$f->addOption('gallery', $this->_('Gallery'));
		$f->optionColumns = 1;
		$f->value = in_array($this->defaultViewMode, ['page', 'template', 'gallery']) ? $this->defaultViewMode : 'gallery';
		$inputfields->add($f);
	}

}
