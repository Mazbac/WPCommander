# Copy-ready ChatGPT prompts

These prompts are intentionally short. The repository carries the durable rules, standards, and current state through `AGENTS.md` and `docs/`; the prompt only needs to identify the repo and your current intent.

## Start a new project

Use this after you have created a new GitHub repository from the `Mazbac/ai-project-starter` template.

```text
Work on this repository:

[REPO NAME OR GITHUB URL]

Use Remote Desktop Commander and GitHub.

Start by following AGENTS.md and the repository cold-start protocol. Read the current product/state/UI/architecture/decision docs, inspect git status and recent commits, and then help me turn the following raw idea into the product and start building it.

Keep the live preview running early, make professional defaults yourself unless there is a genuine product decision for me, verify the work, and keep the repository context accurate.

My raw idea:

[PASTE MESSY IDEA HERE]
```

## Continue an existing project in a fresh chat

```text
Continue this repository:

[REPO NAME OR GITHUB URL]

Use Remote Desktop Commander and GitHub.

Do the normal repository cold-start first: follow AGENTS.md, read docs/STATE.md and any relevant product/UI/architecture/decision docs, inspect git status and recent commits, then continue from the actual current state.

Do not ask me to re-explain the project unless the repository genuinely does not contain the answer.

What I want to work on:

[PASTE REQUEST HERE]
```

## Quick change in an existing project

```text
Work on this repo:

[REPO]

Please make this change:

[WHAT I WANT]

Use AGENTS.md and the repository's current state as context. Inspect the existing implementation first, reuse established patterns, keep the preview running when relevant, verify the result, update repo context only where necessary, and commit coherent verified work.
```
