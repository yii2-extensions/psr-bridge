# Use the PSR bridge agent skill

The editable source is [`skills/yii2-psr-bridge-worker/SKILL.md`](../skills/yii2-psr-bridge-worker/SKILL.md) in this
package. It helps an agent integrate request isolation, lazy sessions, and the response emission/finalization lifecycle.
The documentation site consumes the package guides and skill; its published files are generated outputs.

## Install in an application

Run the pinned installer from the application's root, selecting the clients you use:

```bash
npx skills@1.7.2 add /absolute/path/to/psr-bridge --list
npx skills@1.7.2 add /absolute/path/to/psr-bridge \
  --skill yii2-psr-bridge-worker --agent claude-code codex opencode
```

The source can be a package checkout or `./vendor/yii2-extensions/psr-bridge` when that installed release includes
the skill. For published GitHub releases, list before installing:

```bash
npx skills@1.7.2 add yii2-extensions/psr-bridge --list
npx skills@1.7.2 add yii2-extensions/psr-bridge \
  --skill yii2-psr-bridge-worker --agent claude-code codex opencode
```

Use the GitHub command only after listing confirms the skill: unpublished local changes are not on the default
branch. Keep project scope. The installer uses links by default; `--copy` creates an installation snapshot that
must be refreshed when upgrading. Installed copies are outputs, not another editable source.

Installing the Composer package alone does not expose skills in `vendor/` to an agent opened at the application
root. Install the skill explicitly. Guides excluded from Composer archives remain available as published Markdown.

## Paths and activation

| Client                                                | Project path                                                             | Explicit activation                                            |
| ----------------------------------------------------- | ------------------------------------------------------------------------ | -------------------------------------------------------------- |
| [Claude Code](https://code.claude.com/docs/en/skills) | `.claude/skills/yii2-psr-bridge-worker/SKILL.md`                         | `/yii2-psr-bridge-worker`                                      |
| [Codex](https://learn.chatgpt.com/docs/build-skills)  | `.agents/skills/yii2-psr-bridge-worker/SKILL.md`                         | `$yii2-psr-bridge-worker` or the skill picker                  |
| [OpenCode](https://opencode.ai/docs/skills/)          | `.opencode/skills/yii2-psr-bridge-worker/SKILL.md`, or `.agents/skills/` | Ask it to load `yii2-psr-bridge-worker` through its skill tool |

The verified `skills` 1.7.2 installer uses `.agents/skills/` for Codex and OpenCode, and `.claude/skills/` for Claude.
For manual installation, copy or link the complete skill directory, retaining `<name>/SKILL.md`. Avoid separately
edited copies or multiple installations of the same name within a client's search paths.

This source checkout exposes links in `.agents/skills/` and `.claude/skills/` to the canonical `skills/` directory.
No per-client version of the instructions is maintained. When using OpenCode, verify how the installed version
handles the two aliases; the installer needs only its `.agents/skills/` installation.

## Verify each stage

1. **Installation:** the directory and frontmatter exist in the chosen client path.
2. **Discovery:** open a fresh client session at the application root and check its skill list or picker.
3. **Activation:** invoke the skill and verify the client loaded its body. Automatic selection is model-dependent;
   reading `SKILL.md` manually is separate evidence.
4. **Selective consultation:** use the relevant local guide, or the skill's direct published Markdown links, rather
   than loading the entire documentation index.
5. **Functional result:** test sequential visitors, missing/malformed cookies, upload reset, and successful/failed emission.

`--list` verifies the installer's discovery only; it does not run Claude, Codex, or OpenCode. Application instructions
and permissions still apply when a skill is activated.
