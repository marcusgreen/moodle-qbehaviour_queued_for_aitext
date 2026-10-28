# Queued AI grading — user guide

This question behaviour is used by the AI Text question type (`qtype_aitext`).
It works like *Deferred feedback for AI grading*
(`qbehaviour_deferred_for_aitext`), except that the AI does not grade the
responses while the student submits the attempt. Instead, each response is
graded by a background task run by Moodle's cron.

Teachers do not choose this behaviour in the quiz settings. Quizzes use
*Deferred feedback* as usual. When this plugin is enabled, AI Text questions in
new attempts select it automatically.

## Enabling it

Go to *Site administration > Plugins > Question behaviours > Queued AI grading*
and turn on **Grade AI Text responses in the background**.

The behaviour is recorded on each attempt when it starts. Turning the setting
on or off only affects attempts started afterwards. Attempts already in
progress, and regrades of existing attempts, keep the behaviour they started
with.

## How it works

1. The student clicks "Submit all and finish". The attempt is submitted at
   once, without waiting for the AI.
2. Each AI Text response is marked **Requires grading** and a background task
   is queued for it.
3. The review page shows a notice on each waiting question, and a progress
   banner at the top, for example: *"Your answers have been submitted. 2 of 5
   graded by AI so far."* The page checks for progress every 10 seconds.
4. Moodle's cron runs the tasks, which call the AI backend. The number of
   tasks running at once is limited by the concurrency setting.
5. When a response has been graded, the attempt history shows a "Graded by AI"
   step. The quiz attempt total and the student's gradebook grade are updated.
6. When all responses have been graded, the review page reloads by itself and
   shows the grades and feedback.

Students can also close the page and come back later. Their grades will be
there once the tasks have run.

## Settings

| Setting | Description |
|---------|-------------|
| Grade AI Text responses in the background | Turns background grading on or off. Off by default. Only attempts started after the setting is changed are affected. |
| Maximum concurrent AI grading tasks | The maximum number of grading tasks that run at the same time, which is also the maximum number of requests sent to the AI backend at once. The default is 2. 0 means no limit. Keep this below the site's *Ad hoc task runner limit* (`task_adhoc_concurrency_limit`) so other background tasks are not held up. |

## Before you start

- **Cron must run frequently.** Responses stay in the "Requires grading" state
  until cron picks up their tasks. If cron only runs every few minutes,
  students will wait at least that long for feedback.
- The review page stops checking for progress after 30 minutes. After that,
  students have to reload the page themselves.

## What happens if grading fails

If the AI backend does not return a grade, for example because of a timeout or
a rate limit, the task is retried automatically, with an increasing delay
between attempts. After 5 failed attempts the response is left in the
"Requires grading" state, so a teacher can grade it manually.

If a teacher grades a response manually while it is still waiting for the AI,
the teacher's grade is kept and the AI result is discarded when it arrives.

The background tasks run as the student who submitted the attempt, so any
per-user permissions or quotas configured for the AI backend still apply.

## Running the AI on slow hardware

When the model runs on a CPU rather than a GPU, for example with Ollama on a
local server, a single grade can take several minutes. Queued grading is
required in this case: immediate grading would make every student wait that
long when they submit.

**Expect results to take a while.** The time until the last response is graded
is roughly:

> students × AI Text questions × minutes per grade ÷ concurrent tasks

For example, 30 students answering 5 questions at 3 minutes per grade, one task
at a time, takes about 7.5 hours to grade. Tell students and teachers when to
expect results.

**Check the timeouts first.** If a request takes longer than a timeout between
Moodle and the model, the request fails and is retried. Each retry spends
minutes of processing time and fails the same way. After 5 attempts the
response is left for manual grading.

| | Local AI manager backend | Moodle AI subsystem backend (Ollama provider) |
|---|---|---|
| Moodle timeout | *Timeout for request to the AI endpoints* (`local_ai_manager/requesttimeout`). The default is **60 seconds**. Raise it above your slowest grade, for example to 600. | None. Moodle waits as long as the model takes. |
| Other timeouts | A reverse proxy in front of the model, for example nginx `proxy_read_timeout`, which defaults to 60 seconds. | Same. |
| Rate limits | Per-user and per-tenant quotas in the AI manager. | The provider's optional per-user and global rate limits are counted per hour. A request refused by the global limit still uses up one of the task's attempts, and all 5 retries usually fall within the same hour. Turn the global limit off, or set it well above the number of responses graded per hour. |
| Limiting answer length | Depends on the tool settings. | Add Ollama options to the model's extra parameters, for example `{"num_predict": 400}`. Shorter answers finish sooner. |
| Timing of each request | Not recorded. Use the cron log (see below). | Recorded in the `ai_action_register` table (`timecreated`, `timecompleted`, `success`, `errormessage`). |

The time a request spends waiting covers everything before the answer comes
back: waiting in the model server's own queue, loading the model, reading the
prompt and writing the answer. Allow for all of it when setting timeouts.

**Ollama settings.**

- Set `OLLAMA_KEEP_ALIVE` (for example to `-1` or `24h`) so the model stays
  loaded during exams. By default Ollama unloads it after 5 idle minutes, and
  reloading it on a CPU adds time to the next request.
- Set *Maximum concurrent AI grading tasks* to match `OLLAMA_NUM_PARALLEL`. If
  Moodle sends more requests than Ollama processes at once, the extra requests
  wait inside Ollama and that waiting counts towards the timeout.
- Try running 1, 2 and 4 requests at once and compare how many grades finish
  per hour. On many CPUs, running a few requests together gets more done than
  running one at a time, but this depends on the hardware and model.

**Measuring grading time.** Every grading call writes a line to the cron log,
for example:

```
AI grading usage 1234 slot 2 attempt 1/5: 187.3s, graded (model llama3.2)
AI grading usage 1235 slot 1 attempt 2/5: 60.0s, no grade
```

Use the times to estimate how long an exam will take to grade. Failures that
all stop at the same time, such as exactly 60 seconds, mean a timeout is
cutting requests off.

## Benefits

- **No load spike at the end of an exam.** When a whole class submits at the
  same moment, immediate grading sends every request to the AI backend at
  once. This can cause timeouts, rate limit errors and failed submissions.
  Queued grading spreads the requests over time, up to the configured
  concurrency limit.
- **Fast submission for students.** "Submit all and finish" no longer waits for
  the AI, so the request completes quickly even with many questions, a slow
  model or a busy backend. Students are no longer left looking at a page that
  appears to hang.
- **Automatic recovery.** Temporary AI failures are retried instead of losing
  the grade, and a response that cannot be graded falls back to manual grading.
- **Regrades do not call the AI again.** The AI result is saved as part of the
  attempt history, so a quiz regrade reuses it rather than sending a new
  request, which would cost money and could give a different grade.
- **Predictable costs and quotas.** The concurrency limit gives administrators
  direct control over how hard the AI backend is used.


## Requirements

- Moodle 4.5 or later
- The AI Text question type (`qtype_aitext`), with support for this behaviour
- The *Deferred feedback for AI grading* behaviour (`qbehaviour_deferred_for_aitext`)
- A frequently running cron
