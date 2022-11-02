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
 * Question behaviour where the student can submit questions one at a
 * time for immediate feedback.
 *
 * Question behaviour where the student can submit questions multiple times, if the answer is wrong.
 *
 * @package    qbehaviour
 * @subpackage interactivemoopt
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Question behaviour for the interactive model for MooPT questions.
 *
 * Each question has a submit button next to it which the student can use to
 * submit it. If the answer is wrong and there are tries left, the student can try again and change their answer.
 * For every time the student fails, there is a specific hint. When there are no more tries left
 * or the student got the answer right, full feedback is shown and there is no way of changing the response anymore.
 *
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_interactivemoopt extends question_behaviour_with_multiple_tries {
    /**
     * Constant used only in {@link adjust_display_options()} below and
     * {@link (qbehaviour_interactivemoopt_renderer}.
     * @var int
     */
    const TRY_AGAIN_VISIBLE = 0x10;
    /**
     * Constant used only in {@link adjust_display_options()} below and
     * {@link (qbehaviour_interactivemoopt_renderer}.
     * @var int
     */
    const TRY_AGAIN_VISIBLE_READONLY = 0x11;

    public function is_compatible_question(question_definition $question) {
        return $question instanceof qtype_moopt_question;
    }

    public function can_finish_during_attempt() {
        return true;
    }

    public function get_right_answer_summary() {
        return $this->question->get_right_answer_summary();
    }

    /**
     * @return bool are we are currently in the try_again state.
     */
    public function is_try_again_state() {
        $laststep = $this->qa->get_last_step();
        // try again state: last step was graded wrong with a submit and tries left
        // laststep gradingresult && tries left: last step graded wrong with a submit and tries left (see process_gradingresult())
        return $this->qa->get_state()->is_active() && $laststep->has_behaviour_var('gradingresult') &&
                $laststep->has_behaviour_var('_triesleft');
    }

    public function adjust_display_options(question_display_options $options) {
        // We only need different behaviour in try again states.
        if (!$this->is_try_again_state()) {
            parent::adjust_display_options($options);
            if ($this->qa->get_state() == question_state::$invalid &&
                    $options->marks == question_display_options::MARK_AND_MAX) {
                $options->marks = question_display_options::MAX_ONLY;
            }
            return;
        }

        // The question in in a try-again state. We need the to let the renderer know this.
        // The API for question-rendering is defined by the question engine, but we
        // don't want to add logic in the renderer, so we are limited in how we can do this.
        // However, when the question is in this state, all the question-type controls
        // need to be rendered read-only. Therefore, we can conveniently pass this information
        // by setting special true-like values in $options->readonly (but this is a bit of a hack).
        $options->readonly = $options->readonly ? self::TRY_AGAIN_VISIBLE_READONLY : self::TRY_AGAIN_VISIBLE;

        // Let the hint adjust the options.
        $hint = $this->get_applicable_hint();
        if (!is_null($hint)) {
            $hint->adjust_display_options($options);
        }

        // Now call the base class method, but protect some fields from being overwritten.
        $save = clone($options);
        parent::adjust_display_options($options);
        $options->feedback = $save->feedback;
        $options->numpartscorrect = $save->numpartscorrect;
    }

    public function get_applicable_hint() {
        if (!$this->is_try_again_state()) {
            return null;
        }
        return $this->question->get_hint(count($this->question->hints) -
                $this->qa->get_last_behaviour_var('_triesleft'), $this->qa);
    }

    public function get_expected_data() {
        if ($this->is_try_again_state()) {
            return array(
                'tryagain' => PARAM_BOOL,
            );
        } else if ($this->qa->get_state()->is_active()) {
            return array(
                'submit' => PARAM_BOOL,
            );
        }
        return parent::get_expected_data();
    }

    public function get_expected_qt_data() {
        $hint = $this->get_applicable_hint();
        if (!empty($hint->clearwrong)) {
            return $this->question->get_expected_data();
        }
        return parent::get_expected_qt_data();
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();
        if (!$state->is_active() || $state == question_state::$invalid) {
            return parent::get_state_string($showcorrectness);
        }

        return get_string('triesremaining', 'qbehaviour_interactivemoopt',
                $this->qa->get_last_behaviour_var('_triesleft'));
    }

    public function init_first_step(question_attempt_step $step, $variant) {
        parent::init_first_step($step, $variant);
        $step->set_behaviour_var('_triesleft', count($this->question->hints) + 1);
    }

    protected function adjust_fraction($fraction, $triesleft = null) {
        $totaltries = $this->qa->get_step(0)->get_behaviour_var('_triesleft');
        $triesleft = $triesleft ?? $this->qa->get_last_behaviour_var('_triesleft');

        $fraction -= ($totaltries - $triesleft) * $this->question->penalty;
        $fraction = max($fraction, 0);
        return $fraction;
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        }
        if ($this->is_try_again_state()) {
            if ($pendingstep->has_behaviour_var('tryagain')) {
                return $this->process_try_again($pendingstep);
            } else {
                return question_attempt::DISCARD;
            }
        } else {
            if ($pendingstep->has_behaviour_var('comment')) {
                return $this->process_comment($pendingstep);
            } else if ($pendingstep->has_behaviour_var('submit')) {
                return $this->process_submit($pendingstep);
            } else if ($pendingstep->has_behaviour_var('gradingresult')) {
                return $this->process_gradingresult($pendingstep);
            } else if ($pendingstep->has_behaviour_var('graderunavailable')) {
                return $this->process_graderunavailable($pendingstep);
            } else {
                return $this->process_save($pendingstep);
            }
        }
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            if ($step->get_state()->is_graded()) {
                return get_string('finished', 'qbehaviour_interactivemoopt',
                    get_string('alreadygradedsummary', 'qbehaviour_interactivemoopt'));
            } else {
                return get_string('finished', 'qbehaviour_interactivemoopt',
                    get_string('gradingsummary', 'qbehaviour_interactivemoopt'));
            }
        } else if ($step->has_behaviour_var('tryagain')) {
            return get_string('tryagain', 'qbehaviour_interactivemoopt');
        } else if ($step->has_behaviour_var('submit')) {
            return get_string('submitted', 'question',
                get_string('gradingsummary', 'qbehaviour_interactivemoopt'));
        } else if ($step->has_behaviour_var('gradingresult')) {
            return get_string('graded', 'qbehaviour_interactivemoopt',
                get_string('gradedsummary', 'qbehaviour_interactivemoopt'));
        } else if ($step->has_behaviour_var('graderunavailable')) {
            return get_string('grading', 'qbehaviour_interactivemoopt',
                get_string('graderunavailable', 'qbehaviour_interactivemoopt'));
        } else {
            return $this->summarise_save($step);
        }
    }

    public function process_try_again(question_attempt_pending_step $pendingstep) {
        $pendingstep->set_state(question_state::$todo);
        return question_attempt::KEEP;
    }

    public function process_submit(question_attempt_pending_step $pendingstep) {
        global $DB;

        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);

        } else {
            $response = $pendingstep->get_qt_data();
            if ($this->question->enablefilesubmissions) {
                $questionfilesaver = $pendingstep->get_qt_var('answer');
                if ($questionfilesaver instanceof question_file_saver) {
                    $responsefiles = $questionfilesaver->get_files();
                } else {
                    // We are in a regrade.
                    $record = $DB->get_record('question_usages', array('id' => $this->qa->get_usage_id()), 'contextid');
                    $qubacontextid = $record->contextid;
                    $responsefiles = $pendingstep->get_qt_files('answer', $qubacontextid);
                }
            }
            $freetextanswers = [];
            if ($this->question->enablefreetextsubmissions) {
                $autogeneratenames = $this->question->ftsautogeneratefilenames;
                for ($i = 0; $i < $this->question->ftsmaxnumfields; $i++) {
                    $text = $response["answertext$i"];
                    if ($text == '') {
                        continue;
                    }
                    $record = $DB->get_record('qtype_moopt_freetexts',
                        ['questionid' => $this->question->id, 'inputindex' => $i]);
                    $filename = $response["answerfilename$i"] ?? '';        // By default use submitted filename.
                    // Overwrite filename if necessary.
                    if ($record) {
                        if ($record->presetfilename) {
                            $filename = $record->filename;
                        } else if ($filename == '') {
                            $tmp = $i + 1;
                            $filename = "File$tmp.txt";
                        }
                    } else if ($autogeneratenames || $filename == '') {
                        $tmp = $i + 1;
                        $filename = "File$tmp.txt";
                    }
                    $freetextanswers[$filename] = $text;
                }
            }

            $state = $this->question->grade_response_asynch($this->qa, $responsefiles ?? [], $freetextanswers);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        global $DB;

        $laststep = $this->qa->get_last_step();
        if($laststep->has_behaviour_var('submit')){
            // Case 1: last answer has been submitted but the grading hasn't finished yet
            // (pressing finish while waiting for the gradingresult).
            $pendingstep->set_state(question_state::$finished);
            $pendingstep->set_fraction(0);
            return question_attempt::KEEP;
        }

        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if($laststep->has_behaviour_var('gradingresult') && $laststep->has_behaviour_var('_triesleft')){
            // Case 2: the last step has already been graded partially correct or wrong and there are tries left,
            // but the student decides to finish instead of trying again
            // (pressing finish in try again state).
            // We just want to reuse the last grading result instead of grading the same answer again.

            // get last grading result and calculate fraction
            if ($laststep->has_qt_var('score')) {
                $score = $laststep->get_qt_var('score');
                $maxmark = $this->qa->get_max_mark();
                if ($maxmark == 0) {
                    $fraction = 0;
                } else {
                    $fraction = $score / $maxmark;
                }
            } else {
                $fraction = 0;
            }

            $triesleft = $laststep->get_behaviour_var('_triesleft');

            // set adjusted fraction and a finished state
            // triesleft + 1 because its not a new try, since we just reuse the old grading result
            $pendingstep->set_state(question_state::graded_state_for_fraction($fraction));
            $pendingstep->set_fraction($this->adjust_fraction($fraction, $triesleft + 1));
            return question_attempt::KEEP;
        }

        $response = $this->qa->get_last_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            // Case 3: last answer hasn't been submitted yet, but is not gradable.
            $pendingstep->set_state(question_state::$gaveup);
            $pendingstep->set_fraction(0);
        } else {
            // Case 4: last answer hasn't been submitted yet and needs to be sent to the grader

            if ($this->question->enablefilesubmissions) {
                $record = $DB->get_record('question_usages', array('id' => $this->qa->get_usage_id()), 'contextid');
                $qubacontextid = $record->contextid;
                $responsefiles = $this->qa->get_last_qt_files('answer', $qubacontextid);
            }

            if ($this->question->enablefreetextsubmissions) {
                $autogeneratenames = $this->question->ftsautogeneratefilenames;
                for ($i = 0; $i < $this->question->ftsmaxnumfields; $i++) {
                    $text = $response["answertext$i"];
                    if ($text == '') {
                        continue;
                    }
                    $record = $DB->get_record('qtype_moopt_freetexts',
                        ['questionid' => $this->question->id, 'inputindex' => $i]);
                    $filename = $response["answerfilename$i"] ?? '';        // By default use submitted filename.
                    // Overwrite filename if necessary.
                    if ($record) {
                        if ($record->presetfilename) {
                            $filename = $record->filename;
                        } else if ($filename == '') {
                            $tmp = $i + 1;
                            $filename = "File$tmp.txt";
                        }
                    } else if ($autogeneratenames || $filename == '') {
                        $tmp = $i + 1;
                        $filename = "File$tmp.txt";
                    }
                    $freetextanswers[$filename] = $text;
                }
            }

            $state = $this->question->grade_response_asynch($this->qa, $responsefiles ?? [], $freetextanswers ?? []);
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    public function process_gradingresult(question_attempt_pending_step $pendingstep){
        global $DB;

        $processdbid = $pendingstep->get_qt_var('gradeprocessdbid');
        $exists = $DB->record_exists('qtype_moopt_gradeprocesses', ['id' => $processdbid]);
        if (!$exists) {
            // It's a regrade, discard this *old* result.
            return question_attempt::DISCARD;
        }

        if ($pendingstep->has_qt_var('score')) {
            $score = $pendingstep->get_qt_var('score');
            $maxmark = $this->qa->get_max_mark();
            if ($maxmark == 0) {
                $fraction = 0;
            } else {
                $fraction = $score / $maxmark;
            }
        } else {
            $fraction = 0;
        }

        $triesleft = $this->qa->get_last_behaviour_var('_triesleft');
        $laststep = $this->qa->get_last_step();
        $state = question_state::graded_state_for_fraction($fraction);

        // We need to know if submit or finish initiated this gradingresult. A wrong answer during a submit is improvable
        // when there are tries left. For this reason the step have to be in state todo.
        if ($laststep->has_behaviour_var('submit')) {
            if ($state == question_state::$gradedright || $triesleft == 1) {
                $pendingstep->set_state($state);
                $pendingstep->set_fraction($this->adjust_fraction($fraction));
            } else {
                $pendingstep->set_behaviour_var('_triesleft', $triesleft - 1);
                $pendingstep->set_state(question_state::$todo);
                $pendingstep->set_behaviour_var('_showGradedFeedback', 1);
            }
        } else {
            $pendingstep->set_state($state);
            $pendingstep->set_fraction($this->adjust_fraction($fraction));
        }

        $pendingstep->set_new_response_summary($this->question->summarise_response($pendingstep->get_all_data()));

        // If this is the real result for a regrade we should update the quiz_overview_regrades table
        // to properly display the new result.
        $regraderecord = $DB->get_record('quiz_overview_regrades',
            ['questionusageid' => $this->qa->get_usage_id(), 'slot' => $this->qa->get_slot()]);
        if ($regraderecord) {
            $regraderecord->newfraction = $this->adjust_fraction($fraction);
            $DB->update_record('quiz_overview_regrades', $regraderecord);
        }

        return question_attempt::KEEP;
    }

    public function process_graderunavailable(question_attempt_pending_step $pendingstep){
        global $DB;

        $processdbid = $pendingstep->get_qt_var('gradeprocessdbid');
        $exists = $DB->record_exists('qtype_moopt_gradeprocesses', ['id' => $processdbid]);
        if (!$exists) {
            // It's a regrade, discard this old step.
            return question_attempt::DISCARD;
        }

        $pendingstep->set_state(question_state::$needsgrading);

        return question_attempt::KEEP;
    }


    public function process_save(question_attempt_pending_step $pendingstep) {
        $status = parent::process_save($pendingstep);
        if ($status == question_attempt::KEEP &&
                $pendingstep->get_state() == question_state::$complete) {
            $pendingstep->set_state(question_state::$todo);
        }
        return $status;
    }

    /**
     * Only differs from parent implementation in that it sets a  flag on the first execution and
     * doesn't keep this step if the flag has already been set. This is important in the face of regrades.
     * When a submission is regraded the comment and the mark refer to the old version of the grading result,
     * therefore we don't include the comment and the mark in the regrading.
     * @global type $DB
     * @param \question_attempt_pending_step $pendingstep
     * @return bool
     */
    public function process_comment(\question_attempt_pending_step $pendingstep): bool {
        global $DB;
        if ($DB->record_exists('question_attempt_step_data',
            array('attemptstepid' => $pendingstep->get_id(), 'name' => '-_appliedFlag'))) {
            return question_attempt::DISCARD;
        }

        $parentreturn = parent::process_comment($pendingstep);

        $pendingstep->set_behaviour_var('_appliedFlag', '1');
        return $parentreturn;
    }
}
