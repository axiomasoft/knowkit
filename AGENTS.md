# KnowKit

- This monorepo implements a PHP >=8.3 core, optional answer/pgvector/HTTP/Laravel
  packages, PHP >=8.4 Chatom and AgenTools integrations, and a Python 3.12 worker.
- Keep core free of third-party runtime Composer dependencies. Optional host
  dependencies belong to their own package projects and lockfiles; worker
  dependencies belong to `python/knowkit-worker/pyproject.toml` and `uv.lock`.
- Worker PDF, structure and OCR capabilities require exact qualification records
  and local assets. Preserve offline runtime behavior and sandbox boundaries.
- See `docs/development.md` for the environment and verification commands.
- Plans and tasks live in the `axioma` workspace through the local `plans/` and
  `tasks/` links; keep machine paths and credentials out of tracked files.
- Preserve unrelated changes and run checks appropriate to the code being changed.
- Publishing, releases, and pushing require the owner's instruction.

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — skills and task-commands are connected natively; index and priority rules are not duplicated here. -->
<!-- swissknifeman:registry sha=695f52f0312f skills=209 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
