# qbehaviour_queued_for_aitext

Question behaviour plugin for Moodle that grades AI Text (`qtype_aitext`)
responses in a background task instead of while the student submits the
attempt.

See the [user guide](docs/userdocs.md) for how it works, its settings, and
advice for running the AI on slow hardware such as a CPU.

## How it works

Extends `qbehaviour_deferred_for_aitext`. When an attempt is finished,
`process_finish()` does not call the LLM. It leaves each response in the
`needsgrading` state, sets `_aiqueued` on the step, and queues a
`\qbehaviour_queued_for_aitext\task\grade_response` adhoc task.

The task grades the response from cron and submits the result as an
`-aigrade` action:

| Variable       | Content                                   |
|----------------|-------------------------------------------|
| `aigrade`      | Marks the step written by the task        |
| `aifraction`   | The AI grade, as a fraction               |
| `aicomment`    | AI-generated feedback (HTML)              |
| `aiprompt`     | Full prompt sent to the AI                |
| `aispellcheck` | Grammar/spelling correction (if enabled)  |

These are copied to the same cached variables (`_comment`, `_aiprompt`,
`_spellcheckresponse`) that `qbehaviour_deferred_for_aitext` writes, so
rendering and manual grading work unchanged. Regrades replay the `-aigrade`
step without calling the LLM again.

If the AI still fails after 5 attempts, the task writes an `-aifailed` step.
The response stays in `needsgrading` for the teacher but no longer counts as
waiting for the AI.

The review page polls the `qbehaviour_queued_for_aitext_get_grading_status`
web service and reloads once no queued response is still waiting.

## Requirements

- Moodle 4.5 or later
- `qtype_aitext`, with support for selecting this behaviour
- `qbehaviour_deferred_for_aitext`
- A frequently running cron

## Installation

Place in `question/behaviour/queued_for_aitext/`. Run Moodle upgrade, then
enable *Grade AI Text responses in the background* at
*Site administration > Plugins > Question behaviours > Queued AI grading*.

This behaviour is not archetypal and does not appear in quiz settings. When the
plugin is enabled, `qtype_aitext_question::make_behaviour()` selects it in place
of `qbehaviour_deferred_for_aitext` for new attempts.

## License

GNU GPL v3 or later — http://www.gnu.org/copyleft/gpl.html

Copyright 2026 Marcus Green
