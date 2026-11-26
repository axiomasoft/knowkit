The exact profile `docling-pdf-text-v1` uses Docling 2.148.0, CPU layout Heron
8f39ad3c0b4c58e9c2d2c84a38465abf757272d8, and PDFium via pypdfium2 5.15.2.
`licenses/qualifications/pdf.json` qualifies this narrow private local profile;
it does not enable the broader blocked candidate records or grant redistribution.
The identical packaged qualification record, dependency versions, model bytes,
configuration files and runtime BOM are checked before capability activation.

Run the worker venv's `build_pdf_runtime.py --fetch-models --model-root <private-dir>`
only in a controlled build with network access. Downloads have exact revision,
size and SHA-256 checks. Then run the same script with `--model-root <private-dir>
--destination <new-runtime-dir>` offline. It extends the P6.1 runtime with the
dependency closure including extras, native libraries, notices and model BOM.
The trusted runtime inventory has its own 4 GiB / 65,536-entry ceiling and admits
wheel/template filenames. Job output retains the original stricter path and byte
limits. No host home directories are copied into the PDF runtime.
Jobs receive no model path or effective-options override. Missing/corrupt assets
keep the profile unavailable; dependency/option/schema mismatch is incompatible.
The PHP supervisor must establish the qualified sandbox and resource limits.

IR text comes from Docling's native reading order, never PDF operator order or
Markdown. UTF-8 byte spans and half-even integer millionth geometry address
physical pages starting at 1. Printed labels remain independent (for example xii).
Rotated reading order and table/formula reconstruction remain explicitly incomplete
when encountered. Full-page PNG previews retain raster and vector content;
they are named previews and never claimed as original embedded-image bytes.
Purely blank pages are empty; visual pages without text remain distinct from
extraction failures. An image-only page has unknown OCR needs, never fabricated
text or a claim of complete text coverage. OCR capability stays false for P6.4.
`ok` always has nonempty text. A vector-only page has `empty` text status with
`vector_only_page` diagnostics and a preview; a raster-only page is `skipped`
with `image_only_ocr_unknown` and missing OCR coverage. `empty` does not assert
that a page contains no visual content. P6.4 must preserve this distinction.
A native caption on a raster-containing page also leaves OCR coverage unknown;
the profile cannot prove that text within that raster is fully extracted.

Rendering requires immutable source bytes, the exact revision reference, matching
IR digest, tenant/source/version and parser signature. It never resolves a latest
source. The worker has no current access authority; the host must recheck lifecycle
and access immediately before disclosing output. The renderer caps dimensions,
decoded source images and output at 16 million pixels; active JavaScript and
attachments are rejected before inference. The supervisor bounds CPU/memory/time.

The v1 corpus is synthetic, deterministic and rights-documented, including font
notices. Its semantic assertions precede digest comparisons; digest updates require
a version rationale. Tests use the real backend and fail if it or model assets are
missing. Set `KNOWKIT_PDF_MODELS` for tests or use the default private local build
directory; runtime jobs cannot select it. R13-A01 exercises actual TXT/Markdown
parsers and the shared segment contract; no optional EPUB adapter is claimed.
