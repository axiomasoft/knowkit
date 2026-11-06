# Third-party qualification and local inclusion

KnowKit is an unpublished implementation. The owner has not selected its
redistribution license. Existing proprietary Composer metadata does not grant
public distribution rights; no new root open-source LICENSE is introduced here.
The owner authorized local source reuse/adaptation on 2026-11-06. Its boundary is
recorded in [local reuse authority](../../licenses/local-reuse-authorization.md).

Before adding new code, read the assigned exact donor source and tests, verify
their hashes, and choose `reuse-as-is`, `adapt`, `dependency` or `reject` per
candidate. Explain concrete incompatibilities for rejection and gaps requiring
new glue. Reuse must preserve the approved package DAG, PHP8.3 vendor-free core,
immutable citations and authorization boundaries. Each later transfer adds its
own record and modification attribution; the existing owner instruction already
permits private adaptation and does not require repeated blanket approval.

P1.3 inspected all six assigned blobs and verified their expected SHA256 values.
The [reuse receipt](../../licenses/P1.3-reuse.json) binds each source revision,
URL, whole-file line span, digest, decision and target. Existing QA tooling is
reused for required cases, fixture integrity and subprocess failures. The new
offline PHP gate adapts the donor metadata/explicit graph and fresh-process
patterns to the qualification contract. Donor runtimes are not required.

DeepTutor's [root license](third-party/DeepTutor-LICENSE.txt) at
[`f7aed0744c36c5afef16a4261c31ebee1208cac2`](https://github.com/HKUDS/DeepTutor/blob/f7aed0744c36c5afef16a4261c31ebee1208cac2/LICENSE)
is Apache-2.0. The complete pinned [third-party notices](third-party/DeepTutor-THIRD_PARTY_NOTICES.md)
are retained, including MIT attributions for CSSwitch, Hermes Agent and
thinking-orbs; those unrelated components are not imported. This supersedes the
historical no-copy recommendation in `audits/init.md`. Root code licensing does
not qualify dependencies, model weights, OCR assets, vocabularies or datasets.
AgenTools remains proprietary at `2054acda51eb69eca1579c78900858c5ff3de01e`;
private local adaptation does not authorize public relicensing or copying its
whole workspace and dependencies.

`licenses/qualifications/<component>.json` implements the approved record:
`id`, `version`, `sourceUrl`, `sourceRevision`, `codeLicense`,
`licenseFileDigest`, optional `selectedFiles`, `notices`, `transitiveComponents`,
`modelAssets`, `datasets`, `networkAtBuild`, `networkAtRuntime`,
`distributionConstraints`, `enabledProfiles`, `verdict`, `reason`, `checkedAt`.
Source revisions are immutable Git SHA1 or content SHA256 identities; selected
file evidence additionally carries a pinned URL, SHA256 and line span. Existing
historical source references are never redirected to a newer revision.

Notices are local `{path,sha256}` evidence, including the exact license text.
`transitiveComponents` lists record IDs; the gate traverses every edge and rejects
missing records and cycles. Network declarations contain `required` (boolean),
`destinations` and `reason`. Blocked candidate records may retain explicit unknown
fields, but cannot qualify an enabled profile. Distribution constraints contain
`localUseAllowed`, `redistributionAllowed`, `requirements` and explanatory notes.
Each requirement needs `id`, `fulfilled: true` and digest-bound local evidence.
Proprietary code additionally requires the private owner authorization and a
`restricted` verdict with redistribution disabled.

Every selected model asset and dataset needs its own ID, version, bytes SHA256,
license, immutable `sourceRevision`, pinned `source` URL, local license `notice`,
and separate `localUseAllowed`/`redistributionAllowed` flags. Code license metadata
is never inherited by assets. Empty inventories mean no assets are selected for
that qualified scope, not clearance for future models or training data. Upload
possession is not proof of processing, indexing, egress, disclosure or
redistribution rights. Hosts must supply rights restrictions and enforce them at
each operation; static component qualification does not grant tenant access.
Runtime denied, unknown, unavailable and incompatible outcomes remain distinct.

The [catalog](../../licenses/catalog.json) explicitly selects local profiles and
qualification records. `allowed` or `restricted` records with fulfilled
constraints may participate; missing, unknown, unqualified or blocked components
make an enabled external profile fail. Disabled parser candidates remain visible
without entering the enabled bundle. Docling, MinerU and PyMuPDF have exact source
pins and retained code license evidence, but remain blocked pending dependency,
asset/profile and distribution qualification. MinerU's inspected license includes
commercial thresholds and service attribution; worker isolation does not remove
PyMuPDF's AGPL/commercial licensing choice. No parser, weight or dataset is
downloaded or enabled by this gate.

`local-text` and `local-markdown` represent the original core baseline and have no
external qualification dependencies. They stay selectable without Python,
Laravel, Agentools, GPU, model keys or optional parser records. P1.3 proves this
policy boundary with fixtures; the core currently contains a skeleton, so this
is not evidence of a running text parser or completed product ingestion.
Future runtime registration must use this inclusion gate at profile/build
qualification and supply parser conformance tests before advertising readiness.

Run `php tools/license-audit.php` for the offline inclusion report. To rebuild the
local inventory, run `php tools/license-audit.php --output licenses/generated`.
The existing output directory receives deterministic `sbom.json` and complete
`THIRD_PARTY_NOTICES.txt`; no network call or publication occurs. The custom local
SBOM is `license-audit:1`, not a claim of SPDX/CycloneDX conformance. It includes
all locked third-party development packages, exact versions/source or dist refs,
license texts, dependency edges, asset and network restrictions. Root/core's
internal path package is excluded from third-party enumeration. PHP extensions
and integration infrastructure are prerequisites, not bundled redistributable
components qualified by these records. Composer installation requires network;
the audit and installed unit tests do not.

The lockfile digest and each locked package's identity, license metadata and
require graph are checked against the inventory. License bytes were retained
from the installed package matching that locked reference; this is not a
separate archive/binary supply-chain verification. Updating dependencies requires
updating their records, notices, local SBOM and versioned fixture rationale.
Changing an expected fixture digest always requires a deliberate version/rationale
change, never a blind snapshot rewrite. The registered `contract:licensing`
group maps every required scenario to an executed PHPUnit case; empty, missing,
skipped or failing cases remain failures under the existing QA runner.
