# Development environment

KnowKit uses the same local ecosystem as AgenTools: workspace `axioma`,
marketplace delivery through Swiss Knife Man, scoped mAInd MCP, Serena, and
Perplexity MCP. Project memory is scoped to `project:knowkit`; topology namespaces
are `axioma` and `knowkit`. Shared memory access is not enabled.

The selected Swiss Knife Man plugins are `core`, `oss-dev`, and `planner` for
Claude Code and Codex. No language-specific stack or agent pack is selected
because the upstream repository is empty. Serena has no language servers selected;
configure these when source code and the stack are introduced.

The tracked `.swissknifeman/project.json` identifies this project. The local
`plans/` and `tasks/` symlinks point into the workspace and are excluded through
`.git/info/exclude`. Machine settings live in the workspace project's
`local/config.toml`; `.swissknifeman/config.example.toml` records their portable
bootstrap values. MCP configurations with machine paths remain ignored;
`.mcp.json.example` documents their shape.

The local Claude Code permission mode and auto-approval mode match AgenTools.
Installed hook files are supplied by the local harness installation.

Run these commands from the checkout to inspect or refresh the environment:

```sh
skiller status --json
skiller budget --first-turn
harness doctor
maind health --cwd
swissknifeman status knowkit
maind sync --project knowkit --only mcp,ecosystem-skill,setup-skill,gitignore
```

Setup verification on 2026-11-05 confirmed all three MCP servers initialize and
list their tools: mAInd (11), Serena (21), and Perplexity (4). A scoped topology
graph request for KnowKit succeeded. Codex's offline prompt inspection loaded
the project instructions and the three selected plugins. Repeated MCP sync
reported no pending changes. The memory SessionStart hook exited successfully
when the shared daemon was unavailable.

When changing the Codex native plugin selection, run
`skiller connect --plugins core,oss-dev,planner --native-codex-only` followed by
`maind sync --project knowkit --only mcp --mode repair`. The native selection
writer reformats the MCP block; the repair restores the owning renderer's format
while preserving the plugin selection.

## Infrastructure limitation observed during setup

On 2026-11-05, the shared mAInd memory daemon was unavailable for AgenTools and
KnowKit. `maind start --only memory` refuses to start the existing LAN listener
because it has neither `AGENTMEMORY_SECRET` nor `memory.secret_file`. Starting
memory requires repairing the shared host's authentication configuration; this
bootstrap does not change shared credentials or network binding. Until repaired,
`maind health --cwd` reports `partial`, and persistent memory calls are unavailable.
Repository work, planning files, and the topology graph remain usable.

## Installer observations

The 2026-11-05 onboarding run selected all buckets for this empty repository even
though the onboarding guide describes a minimal fallback. The project now has an
explicit plugin selection, and surplus Claude Code entries are disabled.

The first onboarding pass also reported deployment while provider preflight
refused the initial Context7-only `.mcp.json`. The generated initial files were
archived under the workspace project's ignored local backups, then MCP was
provisioned from an absent target. Subsequent native Codex selection was followed
by the owning MCP repair. These are follow-up items for the skiller integration
and maind deployment modules; no library source was changed during project setup.
