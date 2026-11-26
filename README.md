# KnowKit

KnowKit is a PHP monorepo for document ingestion, immutable evidence retrieval,
and grounded answers, with an optional offline Python PDF/OCR worker.
Repository: <https://github.com/axiomasoft/knowkit>.

| Component | Purpose |
| --- | --- |
| `packages/core` | PHP >=8.3 domain, application, ports, registry, local TXT/Markdown ingestion, PostgreSQL catalog, lexical retrieval and worker supervision; no third-party runtime Composer dependencies |
| `packages/answer` | Bounded grounded answers and explicit model transport bindings |
| `packages/pgvector` | Optional embedding receipts and PostgreSQL vector adapters |
| `packages/http`, `packages/laravel` | HTTP transport and Laravel 12 integration |
| `packages/chatom` | Direct Chatom integration with its pinned Laravel 13 host; PHP >=8.4 |
| `packages/agentools-bridge` | Optional pinned AgenTools compatibility adapter; PHP >=8.4 |
| `python/knowkit-worker` | Python 3.12, Docling PDF/structure extraction and explicit English/Russian Tesseract OCR profiles |
| `deploy`, `tools` | Local artifact assembly, sandbox runtime builds, qualification and evaluation commands |

Start with [development and checks](docs/development.md),
[installation](docs/installation.md), [worker setup](deploy/worker/README.md),
and [third-party qualification](docs/architecture/third-party-policy.md).
Optional packages have separate Composer projects and lockfiles. Worker profiles
require exact dependencies and qualified local assets; source presence alone
does not establish readiness. Core TXT/Markdown use does not require Python,
model keys, a GPU, Laravel, Chatom or AgenTools.

The source repository is public. The license remains proprietary; a public
checkout is not a relicensing. Workflows in `.github/workflows` verify the
checkout. CI does not publish releases.
