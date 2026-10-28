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

namespace qbehaviour_queued_for_aitext\local;

use context;
use context_module;
use core_user;
use mod_quiz\question\display_options;
use mod_quiz\quiz_attempt;
use question_display_options;

/**
 * Send the quiz's "attempt graded" notification as soon as AI grading finishes.
 *
 * mod_quiz already notifies students when an attempt that needed grading has
 * been graded, from the hourly quiz_notify_attempt_manual_grading_completed
 * task and only after the quiz/notifyattemptgradeddelay setting (5 hours by
 * default). This class sends the same message, with the same checks, straight
 * away, and marks it as sent so the core task does not send it again. Unlike
 * the core message it links straight to the attempt review, as a link that can
 * be clicked.
 *
 * Attempts that are not sent here, for example because the review options hide
 * comments at the moment, are left for the core task to pick up later.
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class graded_notification {
    /** @var string The capability mod_quiz requires to receive the notification. */
    const CAPABILITY = 'mod/quiz:emailnotifyattemptgraded';

    /**
     * Whether sending the notification straight away is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('qbehaviour_queued_for_aitext', 'notify');
    }

    /**
     * Whether a student will be notified when grading of their attempt finishes.
     *
     * @param context $context the quiz context.
     * @param int $userid the student.
     * @return bool
     */
    public static function will_notify(context $context, int $userid): bool {
        return self::is_enabled() && has_capability(self::CAPABILITY, $context, $userid, false);
    }

    /**
     * Send the notification if the attempt has just become fully graded.
     *
     * @param \stdClass $attempt the quiz_attempts row, after its sumgrades has been updated.
     * @return bool true if a notification was sent.
     */
    public static function send_if_graded(\stdClass $attempt): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if (
            !self::is_enabled()
            || $attempt->state !== quiz_attempt::FINISHED
            || $attempt->preview
            || $attempt->sumgrades === null
            || $attempt->gradednotificationsenttime !== null
        ) {
            return false;
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        if (!has_capability(self::CAPABILITY, $context, $attempt->userid, false)) {
            return false;
        }

        $quiz = quiz_update_effective_access($quiz, $attempt->userid);
        $options = display_options::make_from_quiz($quiz, quiz_attempt_state($quiz, $attempt));
        if ($options->manualcomment == question_display_options::HIDDEN) {
            // The student cannot see the feedback yet. The core task will send it once they can.
            return false;
        }

        $attemptobj = new quiz_attempt($attempt, $quiz, $cm, get_course($quiz->course), false);
        if (!message_send(self::make_message($attemptobj, core_user::get_user($attempt->userid)))) {
            return false;
        }
        $DB->set_field('quiz_attempts', 'gradednotificationsenttime', time(), ['id' => $attempt->id]);
        $attemptobj->fire_attempt_manual_grading_completed_event();
        return true;
    }

    /**
     * Build the quiz's "attempt graded" message.
     *
     * Same message type and subject as quiz_send_notify_manual_graded_message(),
     * which only sends plain text and links to the quiz page. This message links
     * straight to the attempt review, and has an HTML version in which that
     * link can be clicked.
     *
     * @param quiz_attempt $attemptobj the graded attempt.
     * @param \stdClass $userto the student.
     * @return \core\message\message
     */
    public static function make_message(quiz_attempt $attemptobj, \stdClass $userto): \core\message\message {
        $reviewurl = new \moodle_url('/mod/quiz/review.php', [
            'attempt' => $attemptobj->get_attemptid(),
            'cmid' => $attemptobj->get_cmid(),
        ]);

        $a = new \stdClass();
        $a->courseid = $attemptobj->get_courseid();
        $a->coursename = format_string($attemptobj->get_course()->fullname);
        $a->quizname = format_string($attemptobj->get_quiz_name());
        $a->reviewurl = $reviewurl->out(false);
        $a->attempttimefinish = userdate($attemptobj->get_attempt()->timefinish);
        $a->studentidnumber = $userto->idnumber;
        $a->studentname = fullname($userto);

        // The HTML version escapes every value and turns the review URL into a link.
        $ahtml = new \stdClass();
        foreach ((array) $a as $key => $value) {
            $ahtml->$key = s($value);
        }
        $ahtml->reviewurl = \html_writer::link($reviewurl, s($a->reviewurl));

        $message = new \core\message\message();
        $message->component = 'mod_quiz';
        $message->name = 'attempt_grading_complete';
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $userto;
        $message->courseid = $a->courseid;
        $message->subject = get_string('emailmanualgradedsubject', 'quiz', $a);
        $message->fullmessage = get_string('gradedmessagebody', 'qbehaviour_queued_for_aitext', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html(
            get_string('gradedmessagebody', 'qbehaviour_queued_for_aitext', $ahtml),
            null,
            false
        );
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $a->reviewurl;
        $message->contexturlname = get_string('reviewattempt', 'quiz');
        return $message;
    }
}
