<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Panorama CLI can only run from the command line.\n");
	exit(1);
}

$args = getopt('', [
	'root::', 'template:', 'field:', 'width::', 'height::', 'processor::',
	'mode::', 'quality::', 'webp', 'webp-quality::', 'offset::', 'limit::',
	'force', 'all-images', 'dry-run', 'json', 'help',
]);

if (isset($args['help']) || !isset($args['template'], $args['field'])) {
	echo <<<TXT
Panorama image cache warmup

Run from the ProcessWire root:
  php site/modules/Panorama/bin/panorama.php --template=product --field=images --width=500 --height=500

Options:
  --processor=processwire|squareimages  Generator (default: processwire)
  --mode=crop|contain                 SquareImages mode (default: crop)
  --quality=N                         Core image quality, 1–100 (default: 90)
  --webp                              Also warm a WebP sibling (core processor)
  --webp-quality=N                    WebP quality, 1–100 (default: 85)
  --all-images                       Process every image in multi-image fields
  --offset=N --limit=N               Bound or resume a run
  --force                            Delete and regenerate matching variants
  --dry-run                          Count matching pages without generating
  --json                             Machine-readable result
  --root=/path/to/site               ProcessWire root when not using cwd

TXT;
	exit(isset($args['help']) ? 0 : 2);
}

$root = isset($args['root']) ? (string)$args['root'] : getcwd();
$root = $root !== false ? rtrim($root, DIRECTORY_SEPARATOR) : '';
$bootstrap = $root . DIRECTORY_SEPARATOR . 'wire' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'ProcessWire.php';
if ($root === '' || !is_file($root . DIRECTORY_SEPARATOR . 'index.php') || !is_file($bootstrap)) {
	fwrite(STDERR, "ProcessWire index.php not found. Run from the site root or pass --root.\n");
	exit(2);
}

require_once $bootstrap;
$config = \ProcessWire\ProcessWire::buildConfig($root);
$processWire = new \ProcessWire\ProcessWire($config);
$modules = $processWire->wire('modules');
$panorama = $modules ? $modules->getModule('Panorama', ['noPermissionCheck' => true]) : null;
if (!$panorama || !method_exists($panorama, 'warmupImages')) {
	fwrite(STDERR, "Panorama is not installed or its warmup API is unavailable.\n");
	exit(3);
}

$json = isset($args['json']);
$options = [
	'template' => (string)$args['template'],
	'field' => (string)$args['field'],
	'width' => (int)($args['width'] ?? 500),
	'height' => (int)($args['height'] ?? ($args['width'] ?? 500)),
	'processor' => (string)($args['processor'] ?? 'processwire'),
	'mode' => (string)($args['mode'] ?? 'crop'),
	'quality' => (int)($args['quality'] ?? 90),
	'webp' => isset($args['webp']),
	'webp_quality' => (int)($args['webp-quality'] ?? 85),
	'offset' => (int)($args['offset'] ?? 0),
	'limit' => (int)($args['limit'] ?? 0),
	'force' => isset($args['force']),
	'all_images' => isset($args['all-images']),
	'dry_run' => isset($args['dry-run']),
];
if (!$json && !$options['dry_run']) {
	$options['progress'] = static function(array $state, int $pageId): void {
		fwrite(STDOUT, sprintf(
			"\rProcessed %d/%d (generated %d, skipped %d, failed %d), page %d",
			$state['processed'],
			$state['total'],
			$state['generated'],
			$state['skipped'],
			$state['failed'],
			$pageId
		));
	};
}

try {
	$result = $panorama->warmupImages($options);
	if (!$json && !$options['dry_run']) fwrite(STDOUT, "\n");
	echo $json
		? json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES) . "\n"
		: ($options['dry_run'] ? "Matching pages: {$result['total']}\n" : json_encode($result, JSON_PRETTY_PRINT) . "\n");
	exit($result['failed'] > 0 ? 4 : 0);
} catch (\Throwable $error) {
	if ($json) {
		echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";
	} else {
		fwrite(STDERR, "Panorama warmup failed: {$error->getMessage()}\n");
	}
	exit(4);
}
