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
 * Renderer for the queued_for_aitext question behaviour.
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/deferred_for_aitext/renderer.php');

/**
 * Renderer for the queued_for_aitext question behaviour.
 *
 * Adds a notice, and the progress banner, while a response waits for the
 * grade_response task. Everything else is rendered as by
 * qbehaviour_deferred_for_aitext.
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_queued_for_aitext_renderer extends qbehaviour_deferred_for_aitext_renderer {
    /**
     * Show a notice while the response is waiting for AI grading.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    public function feedback(question_attempt $qa, question_display_options $options) {
        $output = parent::feedback($qa, $options);
        if (
            $qa->get_state() === question_state::$needsgrading
            && $qa->get_last_step()->has_behaviour_var('_aiqueued')
        ) {
            $output .= html_writer::div(
                get_string('aigradingqueued', 'qbehaviour_queued_for_aitext'),
                'alert alert-info qbehaviour_queued_for_aitext-queued'
            );
            if ($qa->get_usage_id()) {
                // The module ignores repeat calls for the same usage, one per question.
                $this->page->requires->js_call_amd(
                    'qbehaviour_queued_for_aitext/grading_status',
                    'init',
                    [(int) $qa->get_usage_id()]
                );
            }
        }
        return $output;
    }
}
