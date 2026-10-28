<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for the queued_for_aitext question behaviour.
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['aifailedaction'] = 'AI grading failed';
$string['aigradeaction'] = 'Graded by AI';
$string['aigradingfailed'] = 'This response could not be graded by AI. Your teacher will grade it.';
$string['aigradingfinished'] = 'AI grading has finished. Loading your feedback...';
$string['aigradingnotify'] = 'You can leave this page. You will get a notification when grading has finished.';
$string['aigradingprogress'] = 'Your answers have been submitted. {$a->graded} of {$a->total} graded by AI so far. This page will refresh when grading has finished.';
$string['aigradingqueued'] = 'This response is queued for AI grading. The grade and feedback will appear here once grading has finished.';
$string['concurrency'] = 'Maximum concurrent AI grading tasks';
$string['concurrency_desc'] = 'The maximum number of AI grading tasks that may run at the same time, which is the maximum number of grading requests sent to the AI backend at once. Keep this below the "Ad hoc task runner limit" (task_adhoc_concurrency_limit) so other background tasks are not blocked. 0 means no limit.';
$string['enabled'] = 'Grade AI Text responses in the background';
$string['enabled_desc'] = 'When enabled, AI Text questions in deferred feedback quizzes are graded by a background task instead of during the request that submits the attempt. Students can submit at once, and the requests to the AI backend are spread over time. Requires cron to run frequently. Only attempts started after this is changed are affected.';
$string['err_aigradingfailed'] = 'The AI backend did not return a grade. The task will be retried.';
$string['gradedmessagebody'] = 'Hi {$a->studentname},

Your answers to \'{$a->quizname}\' in course \'{$a->coursename}\' at {$a->attempttimefinish} have now been graded.

You can view your score and feedback by visiting {$a->reviewurl}';
$string['notify'] = 'Notify students straight away';
$string['notify_desc'] = 'When the AI has graded the last response in a quiz attempt, send the student the quiz\'s "Your attempt has been graded" notification at once, instead of waiting for the hourly quiz task and the "Attempt graded notification delay". Students only receive it if they have the mod/quiz:emailnotifyattemptgraded capability, which no role has by default, and if the quiz review options let them see comments.';
$string['pluginname'] = 'Queued AI grading';
$string['privacy:metadata'] = 'The Queued AI grading behaviour plugin does not store any personal data. AI-generated grading data is stored as question attempt step data, which is managed by the core question engine privacy provider.';
$string['task_graderesponse'] = 'Grade AI text response';
