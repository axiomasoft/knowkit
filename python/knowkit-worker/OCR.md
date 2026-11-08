# Qualified offline OCR

The text PDF profile always disables OCR. Separate exact profiles
`docling-pdf-ocr-eng-v1` and `docling-pdf-ocr-rus-v1` use Docling 2.148.0's
`TesseractCliOcrOptions` and its frozen CLI TSV/OSD adapter. They process raster
regions on their original physical pages, preserving native text as `source` and
recognized words as `ocr`. They never download models or select a fallback
engine/language. No external OCR adapter is implemented or qualified.

The qualification record pins Tesseract 5.5.4, its peeled source commit,
tessdata_fast 4.1.0's peeled commit, each eng/rus/osd SHA256, and a native build
BOM. `third_party/ocr/build-bom.json` records the build image, compiler, package
versions and exact executable/library inventory. Native assets must match the
record before OCR activation. Missing assets preserve native extraction with
partial coverage; changed assets are incompatible. Layout weights inherit the
existing PDF qualification. Qualification is private local use, not distribution.

Build native assets explicitly with `build_ocr_native.sh` inside the image pinned
in the BOM, mounting a private output directory at `/out` and passing
`OUTPUT_UID`/`OUTPUT_GID`. The script installs build tools only inside that image.
An optional public CA bundle can be mounted at `/build-ca.crt`. Build network
access is required; runtime network access is forbidden. Rebuilding must satisfy
the recorded exact inventory; qualification changes require a new profile and
fixture version with rationale.

After the existing locked venv sync and explicit PDF model fetch, package the
sandbox runtime offline:

```sh
.venv/bin/python build_ocr_runtime.py --model-root .local-checks/pdf-models \
  --native-root .local-checks/ocr-native --destination .local-checks/ocr-runtime
```

The PHP bubblewrap/cgroup supervisor remains mandatory. The host's registry must
register the exact qualified profile and independently authorize each processing
attempt. Local OCR is not egress consent. Any future external provider must use
the existing `PolicyChecks::transmit` gate with current Allow for the exact
destination, source version, operation and asset/content digests immediately
before transmitting bytes.

OCR block IDs encode physical page, ordinal, explicit recognition language, full model
fingerprint and extraction confidence in permille. Python `OcrMetadata` and PHP
`OcrMetadataReader` interpret them without changing IR 1.0. Every OCR span has
rotated-page millionths geometry and verbatim provenance referencing its original
page preview. Confidence is not a probability that a statement is true.
Crop coordinates are restored from actual rendered pixels before the single
half-even normalization, including regions whose PDF bounds fall between pixels.
The language is the requested recognition profile; OSD checks script compatibility
and does not certify the natural language of arbitrary source prose.

OSD checks orientation/script only; it never switches language. A script mismatch,
unverified language, rotated raster, low confidence or unreadable region remains
partial. Unreadable scans never become blank pages or dummy text. Blank and
vector-only pages remain distinct. Page limits preserve later physical pages as
skipped, rather than silently shortening the document. Table/rotation/layout gaps
from text extraction are retained. Default QualityPolicy blocks strict publication
of partial/unknown coverage; an explicit host policy controls partial publication.

Re-OCR uses the same immutable SourceVersion and allocates a new DocumentRevision
through the existing WorkerParserClient. Model/profile changes invalidate the
document/chunkset/index axes. Re-chunk and re-embed invalidate only their own
downstream axes. Historical citations retain their original revision, IR digest,
span and model profile; resolution never redirects to the latest revision.
