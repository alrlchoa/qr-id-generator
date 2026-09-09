# Card font (named seam — Phase 12)

`CardRenderer` looks for a TrueType font at `resources/fonts/CardFont.ttf`.
None is bundled — none could be sourced during Phase 12 — so text on a
rendered card currently falls back to GD's five built-in bitmap sizes
(legible, but a coarser five-step shrink rather than continuous per-pixel
sizing).

Drop a real, redistributably-licensed `.ttf` at this exact path
(`resources/fonts/CardFont.ttf`) to switch `CardRenderer` over to
`imagettftext()`-based rendering automatically — no code change needed.
See `CardRenderer::bundledFontPath()` and `drawText()`.
