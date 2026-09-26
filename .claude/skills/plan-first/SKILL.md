---
name: plan-first
description: Write an implementation plan and get the user's approval BEFORE writing or changing any code. Use this before every feature, fix, refactor, dependency install, or config change in this project — no exceptions, even for small changes.
---

# Plan first

The user wants to understand and approve everything before it is built. Never edit code, install packages, or run migrations until the plan below has been shown and the user has approved it.

## Steps

1. **Understand the request.** Re-read it. If something that changes the outcome is unclear, ask before planning.
2. **Research before deciding.**
   - Read `.claude/lessons.md` and apply any lesson that is relevant.
   - For every library/framework involved (Laravel, PHP, Guzzle, SQLite, etc.), check the current docs through the **Context7** MCP tools. Do not rely on memory for APIs, versions, or config syntax.
   - Look at the existing code so the plan matches what is already there.
3. **Write the plan** using the template below, in plain language. Explain every technical term the first time it appears.
4. **Save the plan** to `.claude/plans/YYYY-MM-DD-<short-slug>.md` so there is a history of decisions.
5. **Stop and ask for approval.** Do not start implementing in the same turn.
6. **After implementing**, report what was actually done, what differs from the plan and why, and how it was verified. If a mistake happened along the way, add it to `.claude/lessons.md`.

## Plan template

```markdown
# Plan: <title>

## Goal
What we are building and why, in 1–3 sentences.

## What I will do
Numbered steps. For each: what, and why this way.

## Files
- `path/to/file` — new / changed — what it contains

## Best practices applied
Which conventions/patterns are used and why (with the Context7 source checked, e.g. "Laravel 12 docs: Task Scheduling").

## Things to know / risks
Trade-offs, limits, anything that could break.

## How we'll verify it works
Commands or checks that prove it works.

## Needs from you
Decisions or info (credentials, preferences) the user must provide. "Nothing" if none.
```

## Rules
- One plan per logical change. If the work is large, split it into phases and plan one phase at a time.
- If the user changes the request mid-way, update the plan and re-confirm before continuing.
- Keep the plan honest: if something is uncertain or untested, say so.
