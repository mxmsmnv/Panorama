# Panorama instructions for AI agents

Panorama owns media inspection, cleanup, and image-cache warmup. Read the parent
module-repository `AGENTS.md` before changing this module.

## Large-site reliability

- Never generate a large set of image variations during a frontend render, SEO
  audit, import HTTP request, or other unbounded web request.
- Count first with `--dry-run`, test a small `--limit`, then run bounded slices.
- Use the Panorama CLI from the ProcessWire root:

```bash
php site/modules/Panorama/bin/panorama.php \
  --template=product --field=images --width=500 --height=500 \
  --processor=squareimages --mode=contain --dry-run
```

- Product cards using SquareImages must be warmed with
  `--processor=squareimages`; core `Pageimage::size()` variations are a
  different cache.
- Do not add `--force` unless the user explicitly wants existing matching
  variants invalidated and regenerated.
- Use `--offset` and `--limit` for production slices and `--json` for
  automation. Loaded Page objects must be uncached after each item.
- New maintenance operations must be bounded, resumable where practical,
  idempotent, and report progress, failures, elapsed time, and peak memory.
