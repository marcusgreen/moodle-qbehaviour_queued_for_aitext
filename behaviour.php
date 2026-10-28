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
 * Question behaviour for qtype_aitext questions graded by a background task.
 *
 * Works like qbehaviour_deferred_for_aitext, except that finishing the attempt
 * does not call the LLM. The response is left in the needsgrading state and a
 * grade_response adhoc task is queued, which grades it from cron and submits
 * the result as an -aigrade action.
 *
 * Behaviour variables, in addition to those of qbehaviour_deferred_for_aitext:
 *   _aiqueued         Set on the finish step when grading was queued to a task.
 *   aigrade           Marks the step written by the grade_response task.
 *   aifraction, aicomment, aiprompt, aispellcheck
 *                     The AI result, stored as submitted vars so regrades replay
 *                     it without another LLM call.
 *
 * Because the behaviour is recorded on each question attempt when it starts,
 * turning the plugin off only affects new attempts. Existing attempts keep
 * replaying their queued grade during regrades.
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/deferred_for_aitext/behaviour.php');

/**
 * Question behaviour for qtype_aitext questions graded by a background task.
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_queued_for_aitext extends qbehaviour_deferred_for_aitext {
    /**
     * Finish the attempt without grading and queue the AI grading task.
     *
     * Mirrors qbehaviour_deferredfeedback::process_finish() except that
     * grade_response() is not called. When the step is replayed during a regrade
     * (it already has an id) no new task is queued: the AI grade step that
     * follows it is replayed as well.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_step()->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
        } else {
            $pendingstep->set_state(question_state::$needsgrading);
            $pendingstep->set_behaviour_var('_aiqueued', 1);
            if (empty($pendingstep->get_id()) && $this->qa->get_usage_id()) {
                \qbehaviour_queued_for_aitext\task\grade_response::queue($this->qa);
            }
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    /**
     * Apply an AI grade computed by the grade_response task.
     *
     * The AI results arrive as submitted behaviour variables (-aifraction,
     * -aicomment, -aiprompt, -aispellcheck) so that a regrade replays them
     * without calling the LLM again. They are copied into the same cached
     * variables the parent behaviour writes, so the renderer needs no changes.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    protected function process_aigrade(question_attempt_pending_step $pendingstep): bool {
        if ($this->qa->get_state() !== question_state::$needsgrading) {
            // Already graded, e.g. a teacher got there first.
            return question_attempt::DISCARD;
        }

        $fraction = min(max(0.0, (float) $pendingstep->get_behaviour_var('aifraction')), 1.0);
        $pendingstep->set_fraction($fraction);
        $pendingstep->set_state(question_state::graded_state_for_fraction($fraction));

        $comment = $pendingstep->get_behaviour_var('aicomment');
        if ($comment !== null) {
            $pendingstep->set_behaviour_var('_comment', $comment);
            $pendingstep->set_behaviour_var('_commentformat', (string) FORMAT_HTML);
        }
        $prompt = $pendingstep->get_behaviour_var('aiprompt');
        if ($prompt !== null) {
            $pendingstep->set_behaviour_var('_aiprompt', $prompt);
        }
        $spellcheck = $pendingstep->get_behaviour_var('aispellcheck');
        if ($spellcheck !== null) {
            $pendingstep->set_behaviour_var('_spellcheckresponse', $spellcheck);
        }
        return question_attempt::KEEP;
    }

    /**
     * Dispatch the processing of a pending step to the appropriate handler.
     *
     * A pending step with an 'aigrade' behaviour variable carries the result of
     * the grade_response task and is handled by process_aigrade(). Everything
     * else, including teacher spellcheck edits, is handled by the parent.
     *
     * @param question_attempt_pending_step $pendingstep a partially initialised step
     *      containing all the information about the action being performed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('aigrade')) {
            return $this->process_aigrade($pendingstep);
        }
        return parent::process_action($pendingstep);
    }

    /**
     * Summarise what happened in a given step.
     *
     * @param question_attempt_step $step the step to summarise.
     * @return string a plain-text summary.
     */
    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('aigrade')) {
            return get_string('aigradeaction', 'qbehaviour_queued_for_aitext');
        }
        return parent::summarise_action($step);
    }
}
