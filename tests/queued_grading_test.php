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
 * Tests for queued AI grading in the queued_for_aitext behaviour.
 *
 * @package    qbehaviour_queued_for_aitext
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbehaviour_queued_for_aitext;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use qbehaviour_queued_for_aitext\external\get_grading_status;
use qbehaviour_queued_for_aitext\task\grade_response;
use question_attempt;
use question_engine;
use question_state;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

// This test file intentionally declares a test-double class alongside the testcase.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

/**
 * Grading task that returns a fixed result instead of calling the LLM.
 *
 * @package    qbehaviour_queued_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_grade_response extends grade_response {
    /** @var array|null result returned by grade(). */
    public static $result = null;

    /** @var int number of times grade() was called. */
    public static $calls = 0;

    /** @var callable|null run inside grade(), standing in for work done elsewhere during the LLM call. */
    public static $duringgrade = null;

    /** @var \Throwable|null thrown by grade(), standing in for an unexpected backend error. */
    public static $exception = null;

    /** @var int number of apply_grade() calls still to fail, standing in for a lock timeout. */
    public static $failapply = 0;

    #[\Override]
    protected function grade(question_attempt $qa): ?array {
        self::$calls++;
        if (self::$duringgrade) {
            $callback = self::$duringgrade;
            self::$duringgrade = null;
            $callback();
        }
        if (self::$exception) {
            throw self::$exception;
        }
        return self::$result;
    }

    #[\Override]
    protected function apply_grade(int $qubaid, int $slot, array $vars): void {
        if (self::$failapply > 0) {
            self::$failapply--;
            throw new \moodle_exception('locktimeout');
        }
        parent::apply_grade($qubaid, $slot, $vars);
    }
}

/**
 * Tests for queued AI grading.
 *
 * @package    qbehaviour_queued_for_aitext
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_queued_for_aitext
 * @covers     \qbehaviour_queued_for_aitext\task\grade_response
 * @covers     \qbehaviour_queued_for_aitext\external\get_grading_status
 * @covers     \qbehaviour_queued_for_aitext\local\graded_notification
 */
final class queued_grading_test extends \advanced_testcase {
    /** @var \stdClass the quiz. */
    private $quiz;

    /** @var \stdClass the student. */
    private $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'qbehaviour_queued_for_aitext');
        testable_grade_response::$result = [
            '-aigrade' => 1,
            '-aifraction' => 0.5,
            '-aicomment' => 'Half right.',
            '-aiprompt' => 'Grade this.',
        ];
        testable_grade_response::$calls = 0;
        testable_grade_response::$duringgrade = null;
        testable_grade_response::$exception = null;
        testable_grade_response::$failapply = 0;

        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'questionsperpage' => 0,
            'grade' => 10.0,
            'sumgrades' => 1.0,
            'preferredbehaviour' => 'deferredfeedback',
        ]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($this->quiz->cmid)->id,
        ]);
        $question = $questiongenerator->create_question('aitext', 'editor', ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $this->quiz, 0, 1.0);
    }

    /**
     * Start an attempt, answer the aitext questions and submit the attempt.
     *
     * @param int $numslots number of slots in the quiz to answer.
     * @return \stdClass the attempt record.
     */
    private function submit_attempt(int $numslots = 1): \stdClass {
        $quizobj = quiz_settings::create($this->quiz->id, $this->student->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $this->student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = quiz_attempt::create($attempt->id);
        $responses = [];
        for ($slot = 1; $slot <= $numslots; $slot++) {
            $responses[$slot] = ['answer' => 'The frog jumped over the log.', 'answerformat' => FORMAT_HTML];
        }
        $attemptobj->process_submitted_actions($timenow, false, $responses);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submit($timenow, false);
        $attemptobj->process_grade_submission($timenow);
        return $attempt;
    }

    /**
     * Run the queued grading task(s) with the LLM call stubbed.
     *
     * @param int $attemptsavailable attempts left on the task.
     */
    private function run_queued_tasks(int $attemptsavailable = grade_response::MAX_ATTEMPTS): void {
        $tasks = \core\task\manager::get_adhoc_tasks(grade_response::class);
        $this->assertNotEmpty($tasks);
        foreach ($tasks as $queued) {
            $task = new testable_grade_response();
            $task->set_custom_data($queued->get_custom_data());
            $task->set_userid($queued->get_userid());
            $task->set_attempts_available($attemptsavailable);
            $task->execute();
        }
    }

    /**
     * Load the question attempt for the aitext slot.
     *
     * @param \stdClass $attempt
     * @return question_attempt
     */
    private function load_qa(\stdClass $attempt): question_attempt {
        return question_engine::load_questions_usage_by_activity($attempt->uniqueid)->get_question_attempt(1);
    }

    public function test_finish_queues_task_and_leaves_needs_grading(): void {
        $attempt = $this->submit_attempt();

        $qa = $this->load_qa($attempt);
        $this->assertSame('queued_for_aitext', $qa->get_behaviour_name());
        $this->assertSame(question_state::$needsgrading, $qa->get_state());
        $this->assertTrue($qa->get_last_step()->has_behaviour_var('_aiqueued'));

        $tasks = \core\task\manager::get_adhoc_tasks(grade_response::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertEquals($attempt->uniqueid, $task->get_custom_data()->qubaid);
        $this->assertEquals(1, $task->get_custom_data()->slot);
        $this->assertEquals($this->student->id, $task->get_userid());
    }

    public function test_disabled_uses_deferred_behaviour(): void {
        set_config('enabled', 0, 'qbehaviour_queued_for_aitext');
        $attempt = $this->submit_attempt();

        $qa = $this->load_qa($attempt);
        $this->assertSame('deferred_for_aitext', $qa->get_behaviour_name());
        $this->assertFalse($qa->get_last_step()->has_behaviour_var('_aiqueued'));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(grade_response::class));
    }

    public function test_task_applies_grade_and_updates_quiz_grades(): void {
        global $DB;
        $attempt = $this->submit_attempt();
        $this->assertNull($DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]));

        $this->run_queued_tasks();

        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$gradedpartial, $qa->get_state());
        $this->assertEqualsWithDelta(0.5, $qa->get_fraction(), 0.0001);
        $this->assertSame('Half right.', $qa->get_last_behaviour_var('_comment'));
        $this->assertSame('Grade this.', $qa->get_last_behaviour_var('_aiprompt'));
        $this->assertSame('Graded by AI', $qa->get_behaviour()->summarise_action($qa->get_last_step()));

        $this->assertEqualsWithDelta(0.5, $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]), 0.0001);
        $grade = $DB->get_field('quiz_grades', 'grade', ['quiz' => $this->quiz->id, 'userid' => $this->student->id]);
        $this->assertEqualsWithDelta(5.0, $grade, 0.0001);
    }

    public function test_regrade_reuses_ai_grade_without_llm_call(): void {
        global $DB;
        $attempt = $this->submit_attempt();
        $this->run_queued_tasks();
        $this->assertSame(1, testable_grade_response::$calls);
        // Clear the queue so we can check the regrade does not queue another task.
        $DB->delete_records('task_adhoc');

        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->regrade_all_questions(true);
        question_engine::save_questions_usage_by_activity($quba);

        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$gradedpartial, $qa->get_state());
        $this->assertEqualsWithDelta(0.5, $qa->get_fraction(), 0.0001);
        $this->assertSame('Half right.', $qa->get_last_behaviour_var('_comment'));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $this->assertSame(1, testable_grade_response::$calls);
    }

    public function test_regrade_after_disabling_keeps_ai_grade(): void {
        global $DB;
        $attempt = $this->submit_attempt();
        $this->run_queued_tasks();
        $DB->delete_records('task_adhoc');

        // The behaviour is recorded on the attempt, so turning the plugin off
        // must not change how existing attempts are regraded.
        set_config('enabled', 0, 'qbehaviour_queued_for_aitext');
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->regrade_all_questions(true);
        question_engine::save_questions_usage_by_activity($quba);

        $qa = $this->load_qa($attempt);
        $this->assertSame('queued_for_aitext', $qa->get_behaviour_name());
        $this->assertSame(question_state::$gradedpartial, $qa->get_state());
        $this->assertEqualsWithDelta(0.5, $qa->get_fraction(), 0.0001);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $this->assertSame(1, testable_grade_response::$calls);
    }

    public function test_task_does_nothing_when_teacher_graded_first(): void {
        $attempt = $this->submit_attempt();

        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->manual_grade(1, 'Teacher says good.', 1.0, FORMAT_HTML);
        question_engine::save_questions_usage_by_activity($quba);

        $this->expectOutputRegex('/no longer waiting for AI grading/');
        $this->run_queued_tasks();

        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$mangrright, $qa->get_state());
        $this->assertSame(0, testable_grade_response::$calls);
    }

    public function test_teacher_comment_without_mark_keeps_response_queued(): void {
        $attempt = $this->submit_attempt();

        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->manual_grade(1, 'Teacher was here.', null, FORMAT_HTML);
        question_engine::save_questions_usage_by_activity($quba);

        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$needsgrading, $qa->get_state());
        $this->assertTrue($qa->get_last_step()->has_behaviour_var('_aiqueued'));

        $this->run_queued_tasks();

        $qa = $this->load_qa($attempt);
        $this->assertSame(1, testable_grade_response::$calls);
        $this->assertSame(question_state::$gradedpartial, $qa->get_state());
        $this->assertEqualsWithDelta(0.5, $qa->get_fraction(), 0.0001);
        // The teacher's comment is kept, and is shown in preference to the AI comment.
        $this->assertSame('Teacher was here.', $qa->get_manual_comment()[0]);
    }

    public function test_task_retries_then_leaves_for_manual_grading(): void {
        $attempt = $this->submit_attempt();
        testable_grade_response::$result = null;

        try {
            $this->run_queued_tasks(3);
            $this->fail('Expected the task to throw so cron retries it.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_aigradingfailed', $e->errorcode);
        }

        // Final attempt: no exception, response stays for the teacher.
        $this->expectOutputRegex('/left for manual grading/');
        $this->run_queued_tasks(1);
        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$needsgrading, $qa->get_state());
        $this->assertTrue($qa->get_last_step()->has_behaviour_var('aifailed'));
        $this->assertSame('AI grading failed', $qa->get_behaviour()->summarise_action($qa->get_last_step()));
    }

    public function test_failed_grading_no_longer_pending(): void {
        $attempt = $this->submit_attempt();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->quiz->course, 'student');
        $this->setUser($this->student);
        testable_grade_response::$result = null;

        $this->expectOutputRegex('/left for manual grading/');
        $this->run_queued_tasks(1);

        // The review page stops waiting, and the teacher can still grade the response.
        $this->assertSame(['total' => 1, 'pending' => 0], get_grading_status::execute($attempt->uniqueid));
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->manual_grade(1, 'Teacher says good.', 1.0, FORMAT_HTML);
        question_engine::save_questions_usage_by_activity($quba);
        $this->assertSame(question_state::$mangrright, $this->load_qa($attempt)->get_state());
    }

    public function test_exception_retried_then_left_for_manual_grading(): void {
        $attempt = $this->submit_attempt();
        testable_grade_response::$exception = new \RuntimeException('Connection reset');

        try {
            $this->run_queued_tasks(3);
            $this->fail('Expected the exception to reach cron so it retries the task.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Connection reset', $e->getMessage());
        }

        $this->expectOutputRegex('/threw an exception.*Connection reset.*left for manual grading/s');
        $this->run_queued_tasks(1);
        $this->assertTrue($this->load_qa($attempt)->get_last_step()->has_behaviour_var('aifailed'));
    }

    public function test_result_saved_by_new_task_when_last_save_fails(): void {
        global $DB;
        $attempt = $this->submit_attempt();
        $original = array_values(\core\task\manager::get_adhoc_tasks(grade_response::class))[0];
        testable_grade_response::$failapply = 1;

        $this->expectOutputRegex('/Could not save the AI result.*queued a task to save it/');
        $this->run_queued_tasks(1);
        $this->assertSame(question_state::$needsgrading, $this->load_qa($attempt)->get_state());

        // The follow-up task carries the result, so it stores it without calling the LLM again.
        $DB->delete_records('task_adhoc', ['id' => $original->get_id()]);
        $tasks = array_values(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $this->assertCount(1, $tasks);
        $this->assertEquals($this->student->id, $tasks[0]->get_userid());
        $this->run_queued_tasks();

        $qa = $this->load_qa($attempt);
        $this->assertSame(1, testable_grade_response::$calls);
        $this->assertSame(question_state::$gradedpartial, $qa->get_state());
        $this->assertSame('Half right.', $qa->get_last_behaviour_var('_comment'));
        $this->assertEqualsWithDelta(0.5, $this->get_sumgrades($attempt), 0.0001);
    }

    public function test_grade_logs_call_timing(): void {
        $attempt = $this->submit_attempt();
        $queued = array_values(\core\task\manager::get_adhoc_tasks(grade_response::class))[0];

        // Use the real task: under PHPUnit the question returns stub feedback without marks.
        $task = new grade_response();
        $task->set_custom_data($queued->get_custom_data());
        $task->set_attempts_available(1);

        $this->expectOutputRegex(
            '/AI grading usage ' . $attempt->uniqueid . ' slot 1 attempt 5\/5: \d+\.\ds, no grade \(model test\)/'
        );
        $task->execute();
    }

    public function test_notifies_once_when_last_response_graded(): void {
        global $DB;
        $this->allow_graded_notification();
        $this->add_second_question();
        $attempt = $this->submit_attempt(2);
        $tasks = array_values(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $sink = $this->redirectMessages();

        $first = new testable_grade_response();
        $first->set_custom_data($tasks[0]->get_custom_data());
        $first->execute();
        $this->assertEmpty($this->graded_messages($sink));

        $second = new testable_grade_response();
        $second->set_custom_data($tasks[1]->get_custom_data());
        $second->execute();
        $messages = $this->graded_messages($sink);
        $this->assertCount(1, $messages);
        $this->assertEquals($this->student->id, $messages[0]->useridto);

        // The message links straight to the attempt review, as a clickable link in the HTML version.
        $reviewurl = new \moodle_url('/mod/quiz/review.php', ['attempt' => $attempt->id, 'cmid' => $this->quiz->cmid]);
        $this->assertSame($reviewurl->out(false), $messages[0]->contexturl);
        $this->assertStringContainsString($reviewurl->out(false), $messages[0]->fullmessage);
        $this->assertStringContainsString('<a href="' . $reviewurl->out() . '">', $messages[0]->fullmessagehtml);

        // Marked as sent, so the core quiz task does not send it again.
        $this->assertNotNull($DB->get_field('quiz_attempts', 'gradednotificationsenttime', ['id' => $attempt->id]));
    }

    public function test_notification_escapes_names_once(): void {
        global $DB;
        $DB->set_field('quiz', 'name', 'Frogs & Logs', ['id' => $this->quiz->id]);
        $DB->set_field('course', 'fullname', 'Maths & Stats', ['id' => $this->quiz->course]);
        $this->allow_graded_notification();
        $this->submit_attempt();
        $sink = $this->redirectMessages();

        $this->run_queued_tasks();
        $messages = $this->graded_messages($sink);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString("'Frogs & Logs' in course 'Maths & Stats'", $messages[0]->fullmessage);
        $this->assertStringContainsString('Frogs &amp; Logs', $messages[0]->fullmessagehtml);
        $this->assertStringContainsString('Maths &amp; Stats', $messages[0]->fullmessagehtml);
        $this->assertStringNotContainsString('&amp;amp;', $messages[0]->fullmessagehtml);
    }

    public function test_no_notification_without_capability(): void {
        set_config('notify', 1, 'qbehaviour_queued_for_aitext');
        $this->submit_attempt();
        $sink = $this->redirectMessages();

        $this->run_queued_tasks();
        $this->assertEmpty($this->graded_messages($sink));
    }

    public function test_no_notification_when_disabled(): void {
        $this->allow_graded_notification();
        set_config('notify', 0, 'qbehaviour_queued_for_aitext');
        $attempt = $this->submit_attempt();
        $sink = $this->redirectMessages();

        $this->run_queued_tasks();
        $this->assertEmpty($this->graded_messages($sink));
        $this->assertNull($this->get_graded_notification_time($attempt));
    }

    public function test_notification_left_for_core_task_when_comments_hidden(): void {
        global $DB;
        $this->allow_graded_notification();
        $DB->set_field('quiz', 'reviewspecificfeedback', 0, ['id' => $this->quiz->id]);
        $attempt = $this->submit_attempt();
        $sink = $this->redirectMessages();

        $this->run_queued_tasks();
        $this->assertEmpty($this->graded_messages($sink));
        $this->assertNull($this->get_graded_notification_time($attempt));
    }

    public function test_no_notification_while_a_response_needs_the_teacher(): void {
        $this->allow_graded_notification();
        $attempt = $this->submit_attempt();
        testable_grade_response::$result = null;
        $sink = $this->redirectMessages();

        $this->expectOutputRegex('/left for manual grading/');
        $this->run_queued_tasks(1);
        $this->assertEmpty($this->graded_messages($sink));
        $this->assertNull($this->get_sumgrades($attempt));
    }

    public function test_concurrency_limit_from_setting(): void {
        $task = new grade_response();
        $this->assertSame(grade_response::DEFAULT_CONCURRENCY, $task->get_concurrency_limit());

        set_config('concurrency', 4, 'qbehaviour_queued_for_aitext');
        $this->assertSame(4, $task->get_concurrency_limit());
    }

    public function test_concurrent_tasks_leave_correct_quiz_total(): void {
        $this->add_second_question();
        $attempt = $this->submit_attempt(2);
        $tasks = array_values(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $this->assertCount(2, $tasks);

        // While the first task waits for the LLM, the task for the other slot finishes.
        $other = new testable_grade_response();
        $other->set_custom_data($tasks[1]->get_custom_data());
        testable_grade_response::$duringgrade = fn() => $other->execute();

        $first = new testable_grade_response();
        $first->set_custom_data($tasks[0]->get_custom_data());
        $first->execute();

        $sumgrades = $this->get_sumgrades($attempt);
        $this->assertNotNull($sumgrades);
        $this->assertEqualsWithDelta(1.0, (float) $sumgrades, 0.0001);
    }

    public function test_grading_status_reports_pending_then_done(): void {
        $attempt = $this->submit_attempt();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->quiz->course, 'student');
        $this->setUser($this->student);

        $status = get_grading_status::execute($attempt->uniqueid);
        $this->assertSame(['total' => 1, 'pending' => 1], $status);

        $this->run_queued_tasks();
        $status = get_grading_status::execute($attempt->uniqueid);
        $this->assertSame(['total' => 1, 'pending' => 0], $status);
    }

    public function test_grading_status_denied_for_other_student(): void {
        $attempt = $this->submit_attempt();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->quiz->course, 'student');
        $this->setUser($other);

        $this->expectException(\required_capability_exception::class);
        get_grading_status::execute($attempt->uniqueid);
    }

    /**
     * Enrol the student and let them receive the quiz's "attempt graded" notification.
     */
    private function allow_graded_notification(): void {
        global $DB;
        set_config('notify', 1, 'qbehaviour_queued_for_aitext');
        $this->getDataGenerator()->enrol_user($this->student->id, $this->quiz->course, 'student');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability(
            'mod/quiz:emailnotifyattemptgraded',
            CAP_ALLOW,
            $roleid,
            \context_module::instance($this->quiz->cmid)->id
        );
    }

    /**
     * Get the "attempt graded" notifications caught by a message sink.
     *
     * @param \phpunit_message_sink $sink
     * @return array
     */
    private function graded_messages(\phpunit_message_sink $sink): array {
        return array_values(array_filter(
            $sink->get_messages(),
            fn($m) => $m->component === 'mod_quiz' && $m->eventtype === 'attempt_grading_complete'
        ));
    }

    /**
     * Add a second aitext question, worth one mark, to the quiz.
     */
    private function add_second_question(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($this->quiz->cmid)->id,
        ]);
        $question = $questiongenerator->create_question('aitext', 'editor', ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $this->quiz, 0, 1.0);
    }

    /**
     * Read the stored attempt total.
     *
     * @param \stdClass $attempt
     * @return string|null
     */
    private function get_sumgrades(\stdClass $attempt): ?string {
        global $DB;
        return $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]);
    }

    /**
     * Read when the "attempt graded" notification was sent.
     *
     * @param \stdClass $attempt
     * @return string|null
     */
    private function get_graded_notification_time(\stdClass $attempt): ?string {
        global $DB;
        return $DB->get_field('quiz_attempts', 'gradednotificationsenttime', ['id' => $attempt->id]);
    }
}
