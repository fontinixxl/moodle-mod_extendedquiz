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
 * Library of interface functions and constants for module extendedquiz
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 * All the extendedquiz specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package    mod_extendedquiz
 * @copyright  2011 Gerard Cuello
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->dirroot . '/calendar/lib.php');


/**#@+
 * Option controlling what options are offered on the quiz settings form.
 */
define('EXTENDEDQUIZ_MAX_ATTEMPT_OPTION', 10);
define('EXTENDEDQUIZ_MAX_QPP_OPTION', 50);
define('EXTENDEDQUIZ_MAX_DECIMAL_OPTION', 5);
define('EXTENDEDQUIZ_MAX_Q_DECIMAL_OPTION', 7);
/**#@-*/

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('EXTENDEDQUIZ_GRADEHIGHEST', '1');
define('EXTENDEDQUIZ_GRADEAVERAGE', '2');
define('EXTENDEDQUIZ_ATTEMPTFIRST', '3');
define('EXTENDEDQUIZ_ATTEMPTLAST',  '4');
/**#@-*/

/**
 * @var int If start and end date for the quiz are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define('EXTENDEDQUIZ_MAX_EVENT_LENGTH', 5*24*60*60); // 5 days.

/**#@+
 * Options for navigation method within quizzes.
 */
define('EXTENDEDQUIZ_NAVMETHOD_FREE', 'free');
define('EXTENDEDQUIZ_NAVMETHOD_SEQ',  'sequential');
/**#@-*/

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $quiz the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function extendedquiz_add_instance($quiz) {
    global $DB;
    $cmid = $quiz->coursemodule;

    // Process the options from the form.
    $quiz->created = time();
    $quiz->questions = '';
    $result = extendedquiz_process_options($quiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Try to store it in the database.
    $quiz->id = $DB->insert_record('extendedquiz', $quiz);

    // Do the processing required after an add or an update.
    extendedquiz_after_add_or_update($quiz);

    return $quiz->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $quiz the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function extendedquiz_update_instance($quiz, $mform) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');

    // Process the options from the form.
    $result = extendedquiz_process_options($quiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Get the current value, so we can see what changed.
    $oldquiz = $DB->get_record('extendedquiz', array('id' => $quiz->instance));

    // We need two values from the existing DB record that are not in the form,
    // in some of the function calls below.
    $quiz->sumgrades = $oldquiz->sumgrades;
    $quiz->grade     = $oldquiz->grade;

    // Repaginate, if asked to.
    if (!$quiz->shufflequestions && !empty($quiz->repaginatenow)) {
        $quiz->questions = extendedquiz_repaginate(extendedquiz_clean_layout($oldquiz->questions, true),
                $quiz->questionsperpage);
    }
    unset($quiz->repaginatenow);

    // Update the database.
    $quiz->id = $quiz->instance;
    $DB->update_record('extendedquiz', $quiz);

    // Do the processing required after an add or an update.
    extendedquiz_after_add_or_update($quiz);

    if ($oldquiz->grademethod != $quiz->grademethod) {
        extendedquiz_update_all_final_grades($quiz);
        extendedquiz_update_grades($quiz);
    }

    $quizdateschanged = $oldquiz->timelimit   != $quiz->timelimit
                     || $oldquiz->timeclose   != $quiz->timeclose
                     || $oldquiz->graceperiod != $quiz->graceperiod;
    if ($quizdateschanged) {
        extendedquiz_update_open_attempts(array('quizid' => $quiz->id));
    }

    // Delete any previous preview attempts.
    extendedquiz_delete_previews($quiz);

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id the id of the quiz to delete.
 * @return bool success or failure.
 */
function extendedquiz_delete_instance($id) {
    global $DB;

    $quiz = $DB->get_record('extendedquiz', array('id' => $id), '*', MUST_EXIST);

    extendedquiz_delete_all_attempts($quiz);
    extendedquiz_delete_all_overrides($quiz);

    $DB->delete_records('extendedquiz_q_instances', array('quiz' => $quiz->id));
    $DB->delete_records('extendedquiz_feedback', array('quizid' => $quiz->id));

    $events = $DB->get_records('event', array('modulename' => 'extendedquiz', 'instance' => $quiz->id));
    foreach ($events as $event) {
        $event = calendar_event::load($event);
        $event->delete();
    }

    extendedquiz_grade_item_delete($quiz);
    $DB->delete_records('extendedquiz', array('id' => $quiz->id));

    return true;
}

/**
 * Deletes a quiz override from the database and clears any corresponding calendar events
 *
 * @param object $quiz The quiz object.
 * @param int $overrideid The id of the override being deleted
 * @return bool true on success
 */
function extendedquiz_delete_override($quiz, $overrideid) {
    global $DB;

    $override = $DB->get_record('extendedquiz_overrides', array('id' => $overrideid), '*', MUST_EXIST);

    // Delete the events.
    $events = $DB->get_records('event', array('modulename' => 'extendedquiz',
            'instance' => $quiz->id, 'groupid' => (int)$override->groupid,
            'userid' => (int)$override->userid));
    foreach ($events as $event) {
        $eventold = calendar_event::load($event);
        $eventold->delete();
    }

    $DB->delete_records('extendedquiz_overrides', array('id' => $overrideid));
    return true;
}

/**
 * Deletes all quiz overrides from the database and clears any corresponding calendar events
 *
 * @param object $quiz The quiz object.
 */
function extendedquiz_delete_all_overrides($quiz) {
    global $DB;

    $overrides = $DB->get_records('extendedquiz_overrides', array('quiz' => $quiz->id), 'id');
    foreach ($overrides as $override) {
        extendedquiz_delete_override($quiz, $override->id);
    }
}

/**
 * Updates a quiz object with override information for a user.
 *
 * Algorithm:  For each quiz setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the quiz setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   quiz->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $quiz The quiz object.
 * @param int $userid The userid.
 * @return object $quiz The updated quiz object.
 */
function extendedquiz_update_effective_access($quiz, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('extendedquiz_overrides', array('quiz' => $quiz->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($quiz->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {extendedquiz_overrides}
                WHERE groupid $extra AND quiz = ?";
        $params[] = $quiz->id;
        $records = $DB->get_records_sql($sql, $params);

        // Combine the overrides.
        $opens = array();
        $closes = array();
        $limits = array();
        $attempts = array();
        $passwords = array();

        foreach ($records as $gpoverride) {
            if (isset($gpoverride->timeopen)) {
                $opens[] = $gpoverride->timeopen;
            }
            if (isset($gpoverride->timeclose)) {
                $closes[] = $gpoverride->timeclose;
            }
            if (isset($gpoverride->timelimit)) {
                $limits[] = $gpoverride->timelimit;
            }
            if (isset($gpoverride->attempts)) {
                $attempts[] = $gpoverride->attempts;
            }
            if (isset($gpoverride->password)) {
                $passwords[] = $gpoverride->password;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
        if (is_null($override->timelimit) && count($limits)) {
            if (in_array(0, $limits)) {
                $override->timelimit = 0;
            } else {
                $override->timelimit = max($limits);
            }
        }
        if (is_null($override->attempts) && count($attempts)) {
            if (in_array(0, $attempts)) {
                $override->attempts = 0;
            } else {
                $override->attempts = max($attempts);
            }
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with quiz defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $quiz->{$key} = $override->{$key};
        }
    }

    return $quiz;
}

/**
 * Delete all the attempts belonging to a quiz.
 *
 * @param object $quiz The quiz object.
 */
function extendedquiz_delete_all_attempts($quiz) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_extendedquiz($quiz->id));
    $DB->delete_records('extendedquiz_attempts', array('quiz' => $quiz->id));
    $DB->delete_records('extendedquiz_grades', array('quiz' => $quiz->id));
}

/**
 * Get the best current grade for a particular user in a quiz.
 *
 * @param object $quiz the quiz settings.
 * @param int $userid the id of the user.
 * @return float the user's current grade for this quiz, or null if this user does
 * not have a grade on this quiz.
 */
function extendedquiz_get_best_grade($quiz, $userid) {
    global $DB;
    $grade = $DB->get_field('extendedquiz_grades', 'grade',
            array('quiz' => $quiz->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 grades.
    if ($grade === false) {
        return null;
    }

    return $grade + 0; // Convert to number.
}
/**
 * Is this a graded quiz? If this method returns true, you can assume that
 * $quiz->grade and $quiz->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $quiz a row from the quiz table.
 * @return bool whether this is a graded quiz.
 */
function extendedquiz_has_grades($quiz) {
    return $quiz->grade >= 0.000005 && $quiz->sumgrades >= 0.000005;
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $quiz
 * @return object|null
 */
function extendedquiz_user_outline($course, $user, $mod, $quiz) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'extendedquiz', $quiz->id, $user->id);

    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass();
    $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

    // Datesubmitted == time created. dategraded == time modified or time overridden
    // if grade was last modified by the user themselves use date graded. Otherwise use
    // date submitted.
    // TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704.
    if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
        $result->time = $grade->dategraded;
    } else {
        $result->time = $grade->datesubmitted;
    }

    return $result;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $quiz
 * @return bool
 */
function extendedquiz_user_complete($course, $user, $mod, $quiz) {
    global $DB, $CFG, $OUTPUT;
    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');

    $grades = grade_get_grades($course->id, 'mod', 'extendedquiz', $quiz->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($attempts = $DB->get_records('extendedquiz_attempts',
            array('userid' => $user->id, 'quiz' => $quiz->id), 'attempt')) {
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'quiz', $attempt->attempt) . ': ';
            if ($attempt->state != extendedquiz_attempt::FINISHED) {
                echo extendedquiz_attempt_state_name($attempt->state);
            } else {
                echo extendedquiz_format_grade($quiz, $attempt->sumgrades) . '/' .
                        extendedquiz_format_grade($quiz, $quiz->sumgrades);
            }
            echo ' - '.userdate($attempt->timemodified).'<br />';
        }
    } else {
        print_string('noattempts', 'quiz');
    }

    return true;
}

/**
 * Quiz periodic clean-up tasks.
 */
function extendedquiz_cron() {
    global $CFG;

    require_once($CFG->dirroot . '/mod/extendedquiz/cronlib.php');
    mtrace('');

    $timenow = time();
    $overduehander = new mod_extendedquiz_overdue_attempt_updater();

    $processto = $timenow - get_config('quiz', 'graceperiodmin');

    mtrace('  Looking for quiz overdue quiz attempts...');

    list($count, $quizcount) = $overduehander->update_overdue_attempts($timenow, $processto);

    mtrace('  Considered ' . $count . ' attempts in ' . $quizcount . ' quizzes.');

    // Run cron for our sub-plugin types.
    cron_execute_plugin_type('extendedquiz', 'quiz reports');
    cron_execute_plugin_type('quizaccess', 'quiz access rules');                                    //?¿?¿?¿?¿?¿?¿?¿

    return true;
}

/**
 * @param int $quizid the quiz id.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this quiz. Returns an empty
 *      array if there are none.
 */
function extendedquiz_get_user_attempts($quizid, $userid, $status = 'finished', $includepreviews = false) {
    global $DB, $CFG;
    // TODO MDL-33071 it is very annoying to have to included all of locallib.php
    // just to get the quiz_attempt::FINISHED constants, but I will try to sort
    // that out properly for Moodle 2.4. For now, I will just do a quick fix for
    // MDL-33048.
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = extendedquiz_attempt::FINISHED;
            $params['state2'] = extendedquiz_attempt::ABANDONED;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = extendedquiz_attempt::IN_PROGRESS;
            $params['state2'] = extendedquiz_attempt::OVERDUE;
            break;
    }

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    $params['quizid'] = $quizid;
    $params['userid'] = $userid;
    return $DB->get_records_select('extendedquiz_attempts',
            'quiz = :quizid AND userid = :userid' . $previewclause . $statuscondition,
            $params, 'attempt ASC');
}

/**
 * Return grade for given user or all users.
 *
 * @param int $quizid id of quiz
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with quiz_format_grade for display.
 */
function extendedquiz_get_user_grades($quiz, $userid = 0) {
    global $CFG, $DB;

    $params = array($quiz->id);
    $usertest = '';
    if ($userid) {
        $params[] = $userid;
        $usertest = 'AND u.id = ?';
    }
    return $DB->get_records_sql("
            SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

            FROM {user} u
            JOIN {extendedquiz_grades} qg ON u.id = qg.userid
            JOIN {extendedquiz_attempts} qa ON qa.quiz = qg.quiz AND qa.userid = u.id

            WHERE qg.quiz = ?
            $usertest
            GROUP BY u.id, qg.grade, qg.timemodified", $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $quiz The quiz table row, only $quiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function extendedquiz_format_grade($quiz, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'quiz');
    }
    return format_float($grade, $quiz->decimalpoints);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $quiz The quiz table row, only $quiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function extendedquiz_format_question_grade($quiz, $grade) {
    if (empty($quiz->questiondecimalpoints)) {
        $quiz->questiondecimalpoints = -1;
    }
    if ($quiz->questiondecimalpoints == -1) {
        return format_float($grade, $quiz->decimalpoints);
    } else {
        return format_float($grade, $quiz->questiondecimalpoints);
    }
}

/**
 * Update grades in central gradebook
 *
 * @category grade
 * @param object $quiz the quiz settings.
 * @param int $userid specific user only, 0 means all users.
 * @param bool $nullifnone If a single user is specified and $nullifnone is true a grade item with a null rawgrade will be inserted
 */
function extendedquiz_update_grades($quiz, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($quiz->grade == 0) {
        extendedquiz_grade_item_update($quiz);

    } else if ($grades = extendedquiz_get_user_grades($quiz, $userid)) {
        extendedquiz_grade_item_update($quiz, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        extendedquiz_grade_item_update($quiz, $grade);

    } else {
        extendedquiz_grade_item_update($quiz);
    }
}

/**
 * Update all grades in gradebook.
 */
function extendedquiz_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
             FROM {extendedquiz} a, {course_modules} cm, {modules} m
             WHERE m.name='extendedquiz' AND m.id=cm.module AND cm.instance=a.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT a.*, cm.idnumber AS cmidnumber, a.course AS courseid
             FROM {extendedquiz} a, {course_modules} cm, {modules} m
             WHERE m.name='extendedquiz' AND m.id=cm.module AND cm.instance=a.id";
    $rs = $DB->get_recordset_sql($sql);
    if ($rs->valid()) {
        $pbar = new progress_bar('quizupgradegrades', 500, true);                       //?¿?¿?¿
        $i=0;
        foreach ($rs as $quiz) {
            $i++;
            upgrade_set_timeout(60*5); // Set up timeout, may also abort execution.
            extendedquiz_update_grades($quiz, 0, false);
            $pbar->update($i, $count, "Updating Quiz grades ($i/$count).");
        }
    }
    $rs->close();
}
//----------------------------------------------------------------------------------------------------------//canviat manualment^ 
/**
 * Create or update the grade item for given quiz
 *
 * @category grade
 * @param object $quiz object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function extendedquiz_grade_item_update($quiz, $grades = null) {
    global $CFG, $OUTPUT;
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (array_key_exists('cmidnumber', $quiz)) { // May not be always present.
        $params = array('itemname' => $quiz->name, 'idnumber' => $quiz->cmidnumber);
    } else {
        $params = array('itemname' => $quiz->name);
    }

    if ($quiz->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $quiz->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    // What this is trying to do:
    // 1. If the quiz is set to not show grades while the quiz is still open,
    //    and is set to show grades after the quiz is closed, then create the
    //    grade_item with a show-after date that is the quiz close date.
    // 2. If the quiz is set to not show grades at either of those times,
    //    create the grade_item as hidden.
    // 3. If the quiz is set to show grades, create the grade_item visible.
    $openreviewoptions = mod_extendedquiz_display_options::make_from_quiz($quiz,
            mod_extendedquiz_display_options::LATER_WHILE_OPEN);
    $closedreviewoptions = mod_extendedquiz_display_options::make_from_quiz($quiz,
            mod_extendedquiz_display_options::AFTER_CLOSE);
    if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks < question_display_options::MARK_AND_MAX) {
        $params['hidden'] = 1;

    } else if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks >= question_display_options::MARK_AND_MAX) {
        if ($quiz->timeclose) {
            $params['hidden'] = $quiz->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // Either
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after",
        //    grades are kept visible even after closing.
        $params['hidden'] = 0;
    }

    if (!$params['hidden']) {
        // If the grade item is not hidden by the quiz logic, then we need to
        // hide it if the quiz is hidden from students.
        $cm = get_coursemodule_from_instance('extendedquiz', $quiz->id);
        $params['hidden'] = !$cm->visible;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradebook_grades = grade_get_grades($quiz->course, 'mod', 'extendedquiz', $quiz->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            // NOTE: this is an extremely nasty hack! It is not a bug if this confirmation fails badly. --skodak.
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                if (!AJAX_SCRIPT) {
                    $message = get_string('gradeitemislocked', 'grades');
                    $back_link = $CFG->wwwroot . '/mod/extendedquiz/report.php?q=' . $quiz->id .
                            '&amp;mode=overview';
                    $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                    echo $OUTPUT->box_start('generalbox', 'notice');
                    echo '<p>'. $message .'</p>';
                    echo $OUTPUT->container_start('buttons');
                    echo $OUTPUT->single_button($regrade_link, get_string('regradeanyway', 'grades'));
                    echo $OUTPUT->single_button($back_link,  get_string('cancel'));
                    echo $OUTPUT->container_end();
                    echo $OUTPUT->box_end();
                }
                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/extendedquiz', $quiz->course, 'mod', 'extendedquiz', $quiz->id, 0, $grades, $params);
}

/**
 * Delete grade item for given quiz
 *
 * @category grade
 * @param object $quiz object
 * @return object quiz
 */
function extendedquiz_grade_item_delete($quiz) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/extendedquiz', $quiz->course, 'mod', 'extendedquiz', $quiz->id, 0,
            null, array('deleted' => 1));
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every quiz event in the site is checked, else
 * only quiz events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param int $courseid
 * @return bool
 */
function extendedquiz_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (!$quizzes = $DB->get_records('extendedquiz')) {
            return true;
        }
    } else {
        if (!$quizzes = $DB->get_records('extendedquiz', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($quizzes as $quiz) {
        extendedquiz_update_events($quiz);
    }

    return true;
}

/**
 * Returns all quiz graded users since a given time for specified quiz
 */
function extendedquiz_get_recent_mod_activity(&$activities, &$index, $timestart,
        $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $COURSE, $USER, $DB;
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $quiz = $DB->get_record('extendedquiz', array('id' => $cm->instance));

    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['timestart'] = $timestart;
    $params['quizid'] = $quiz->id;

    if (!$attempts = $DB->get_records_sql("
              SELECT qa.*,
                     u.firstname, u.lastname, u.email, u.picture, u.imagealt
                FROM {extendedquiz_attempts} qa
                     JOIN {user} u ON u.id = qa.userid
                     $groupjoin
               WHERE qa.timefinish > :timestart
                 AND qa.quiz = :quizid
                 AND qa.preview = 0
                     $userselect
                     $groupselect
            ORDER BY qa.timefinish ASC", $params)) {
        return;
    }

    $context         = context_module::instance($cm->id);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $context);
    $grader          = has_capability('mod/extendedquiz:viewreports', $context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    if (is_null($modinfo->groups)) {
        // Load all my groups and cache it in modinfo.
        $modinfo->groups = groups_get_user_groups($course->id);
    }

    $usersgroups = null;
    $aname = format_string($cm->name, true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // Grade permission required.
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                if (is_null($usersgroups)) {
                    $usersgroups = groups_get_all_groups($course->id,
                            $attempt->userid, $cm->groupingid);
                    if (is_array($usersgroups)) {
                        $usersgroups = array_keys($usersgroups);
                    } else {
                        $usersgroups = array();
                    }
                }
                if (!array_intersect($usersgroups, $modinfo->groups[$cm->id])) {
                    continue;
                }
            }
        }

        $options = extendedquiz_get_review_options($quiz, $attempt, $context);

        $tmpactivity = new stdClass();

        $tmpactivity->type       = 'extendedquiz';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = $aname;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = $attempt->timefinish;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->attempt   = $attempt->attempt;
        if (extendedquiz_has_grades($quiz) && $options->marks >= question_display_options::MARK_AND_MAX) {
            $tmpactivity->content->sumgrades = extendedquiz_format_grade($quiz, $attempt->sumgrades);
            $tmpactivity->content->maxgrade  = extendedquiz_format_grade($quiz, $quiz->sumgrades);
        } else {
            $tmpactivity->content->sumgrades = null;
            $tmpactivity->content->maxgrade  = null;
        }

        $tmpactivity->user = new stdClass();
        $tmpactivity->user->id        = $attempt->userid;
        $tmpactivity->user->firstname = $attempt->firstname;
        $tmpactivity->user->lastname  = $attempt->lastname;
        $tmpactivity->user->fullname  = fullname($attempt, $viewfullnames);
        $tmpactivity->user->picture   = $attempt->picture;
        $tmpactivity->user->imagealt  = $attempt->imagealt;
        $tmpactivity->user->email     = $attempt->email;

        $activities[$index++] = $tmpactivity;
    }
}

function extendedquiz_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user, array('courseid' => $courseid));
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo '<img src="' . $OUTPUT->pix_url('icon', $activity->type) . '" ' .
                'class="icon" alt="' . $modname . '" />';
        echo '<a href="' . $CFG->wwwroot . '/mod/extendedquiz/view.php?id=' .
                $activity->cmid . '">' . $activity->name . '</a>';
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string('attempt', 'quiz', $activity->content->attempt);
    if (isset($activity->content->maxgrade)) {
        $grades = $activity->content->sumgrades . ' / ' . $activity->content->maxgrade;
        echo ': (<a href="' . $CFG->wwwroot . '/mod/extendedquiz/review.php?attempt=' .
                $activity->content->attemptid . '">' . $grades . '</a>)';
    }
    echo '</div>';

    echo '<div class="user">';
    echo '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $activity->user->id .
            '&amp;course=' . $courseid . '">' . $activity->user->fullname .
            '</a> - ' . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';

    return;
}

/**
 * Pre-process the quiz options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @param object $quiz The variables set on the form.
 */
function extendedquiz_process_options($quiz) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');
    require_once($CFG->libdir . '/questionlib.php');

    $quiz->timemodified = time();

    // Quiz name.
    if (!empty($quiz->name)) {
        $quiz->name = trim($quiz->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $quiz->password = $quiz->quizpassword;
    unset($quiz->quizpassword);

    // Quiz feedback.
    if (isset($quiz->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($quiz->feedbacktext); $i += 1) {
            if (empty($quiz->feedbacktext[$i]['text'])) {
                $quiz->feedbacktext[$i]['text'] = '';
            } else {
                $quiz->feedbacktext[$i]['text'] = trim($quiz->feedbacktext[$i]['text']);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($quiz->feedbackboundaries[$i])) {
            $boundary = trim($quiz->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $quiz->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'quiz', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $quiz->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'quiz', $i + 1);
            }
            if ($i > 0 && $boundary >= $quiz->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'quiz', $i + 1);
            }
            $quiz->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($quiz->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($quiz->feedbackboundaries); $i += 1) {
                if (!empty($quiz->feedbackboundaries[$i]) &&
                        trim($quiz->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'quiz', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($quiz->feedbacktext); $i += 1) {
            if (!empty($quiz->feedbacktext[$i]['text']) &&
                    trim($quiz->feedbacktext[$i]['text']) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'quiz', $i + 1);
            }
        }
        // Needs to be bigger than $quiz->grade because of '<' test in quiz_feedback_for_grade().
        $quiz->feedbackboundaries[-1] = $quiz->grade + 1;
        $quiz->feedbackboundaries[$numboundaries] = 0;
        $quiz->feedbackboundarycount = $numboundaries;
    } else {
        $quiz->feedbackboundarycount = -1;
    }

    // Combing the individual settings into the review columns.
    $quiz->reviewattempt = extendedquiz_review_option_form_to_db($quiz, 'attempt');
    $quiz->reviewcorrectness = extendedquiz_review_option_form_to_db($quiz, 'correctness');
    $quiz->reviewmarks = extendedquiz_review_option_form_to_db($quiz, 'marks');
    $quiz->reviewspecificfeedback = extendedquiz_review_option_form_to_db($quiz, 'specificfeedback');
    $quiz->reviewgeneralfeedback = extendedquiz_review_option_form_to_db($quiz, 'generalfeedback');
    $quiz->reviewrightanswer = extendedquiz_review_option_form_to_db($quiz, 'rightanswer');
    $quiz->reviewoverallfeedback = extendedquiz_review_option_form_to_db($quiz, 'overallfeedback');
    $quiz->reviewattempt |= mod_extendedquiz_display_options::DURING;
    $quiz->reviewoverallfeedback &= ~mod_extendedquiz_display_options::DURING;
}

/**
 * Helper function for {@link quiz_process_options()}.
 * @param object $fromform the sumbitted form date.
 * @param string $field one of the review option field names.
 */
function extendedquiz_review_option_form_to_db($fromform, $field) {
    static $times = array(
        'during' => mod_extendedquiz_display_options::DURING,
        'immediately' => mod_extendedquiz_display_options::IMMEDIATELY_AFTER,
        'open' => mod_extendedquiz_display_options::LATER_WHILE_OPEN,
        'closed' => mod_extendedquiz_display_options::AFTER_CLOSE,
    );

    $review = 0;
    foreach ($times as $whenname => $when) {
        $fieldname = $field . $whenname;
        if (isset($fromform->$fieldname)) {
            $review |= $when;
            unset($fromform->$fieldname);
        }
    }

    return $review;
}

/**
 * This function is called at the end of extendedquiz_add_instance
 * and quiz_update_instance, to do the common processing.
 *
 * @param object $quiz the quiz object.
 */
function extendedquiz_after_add_or_update($quiz) {
    global $DB;
    $cmid = $quiz->coursemodule;

    // We need to use context now, so we need to make sure all needed info is already in db.
    $DB->set_field('course_modules', 'instance', $quiz->id, array('id'=>$cmid));
    $context = context_module::instance($cmid);

    // Save the feedback.
    $DB->delete_records('extendedquiz_feedback', array('quizid' => $quiz->id));

    for ($i = 0; $i <= $quiz->feedbackboundarycount; $i++) {
        $feedback = new stdClass();
        $feedback->quizid = $quiz->id;
        $feedback->feedbacktext = $quiz->feedbacktext[$i]['text'];
        $feedback->feedbacktextformat = $quiz->feedbacktext[$i]['format'];
        $feedback->mingrade = $quiz->feedbackboundaries[$i];
        $feedback->maxgrade = $quiz->feedbackboundaries[$i - 1];
        $feedback->id = $DB->insert_record('extendedquiz_feedback', $feedback);
        $feedbacktext = file_save_draft_area_files((int)$quiz->feedbacktext[$i]['itemid'],
                $context->id, 'mod_extendedquiz', 'feedback', $feedback->id,
                array('subdirs' => false, 'maxfiles' => -1, 'maxbytes' => 0),
                $quiz->feedbacktext[$i]['text']);
        $DB->set_field('extendedquiz_feedback', 'feedbacktext', $feedbacktext,
                array('id' => $feedback->id));
    }
    
    //extendedquiz mod
    extendedquiz_store_vars($quiz->id);
    //extendedquiz mod end

    // Store any settings belonging to the access rules.
    extendedquiz_access_manager::save_settings($quiz);

    // Update the events relating to this quiz.
    extendedquiz_update_events($quiz);

    // Update related grade item.
    extendedquiz_grade_item_update($quiz);
}

//extendedquiz mod 
/*
 * guardem les variables ( {$..} ) que assignem al formulari de configuracio (mod_form)
 * a la base de dades. Utilitza $_POST xq es generen amb Javascript i no són 
 * accessibles desde $quiz
 */
function extendedquiz_store_vars($quizid){
    global $DB;
    foreach ($_POST as $varname => $value) {        
        if (substr($varname, 0, 4) == 'var_') {
            $vardata = explode('_', $varname);      //varsdata = 0->vars 1->nvalues 2->vars
            $vars[$vardata[2]]->{$vardata[1]} = clean_param($value, PARAM_NUMBER);   // integer or float: vars[vars]->nvalues 
        }
        
        // Storing to insert/update after the vars insertion
        if (substr($varname, 0, 10) == 'concatvar_') {
        	$concatvars[$varname]->name = $varname;
        	$concatvars[$varname]->vars = programmedresp_serialize(optional_param($varname, false, PARAM_ALPHANUMEXT));
                $concatvars[$varname]->readablename = optional_param('n'.$varname, $varname, PARAM_ALPHANUM);
        }
    }
    
    // Inserting into DB
    if (!empty($vars)) {
        foreach ($vars as $varname => $var) {
            $var->quizid = $quizid;
            $var->varname = $varname;
           
            // Update
            if ($var->id = $DB->get_field('extendedquiz_var', 'id', array('quizid' => $var->quizid,'varname' => $var->varname))) {
                
                if (!$DB->update_record('extendedquiz_var', $var)) {
                    print_error('errordb', 'qtype_programmedresp');
                }
                
            // Insert
            } else {
                if (!$vars[$varname]->id = $DB->insert_record('extendedquiz_var', $var)) {
                    print_error('errordb', 'qtype_programmedresp');
                }
            }   
        }
    } 
    // Concat vars
        
    // If there are previous concat vars delete the non used
    $sql = "origin = 'quiz' AND instanceid = '$quizid'";
    $quizoldconcatvars = $DB->get_records_select('qtype_programmedresp_conc', $sql, array(), 'id, name');
    if ($quizoldconcatvars) {
        foreach ($quizoldconcatvars as $quizoldconcatvar) {
        	if (empty($concatvars[$quizoldconcatvar->name])) {
        		$DB->delete_records('qtype_programmedresp_conc', array('id' => $quizoldconcatvar->id));
        	}
        }
    }
        
    // Insert / update
    if (!empty($concatvars)) {
        foreach ($concatvars as $obj) {
        	$obj->origin = 'quiz';
        	$obj->instanceid = $quizid;
                
        	// Update
        	if ($dbobj = $DB->get_record('qtype_programmedresp_conc', array('origin'=>'quiz','instanceid'=>$quizid, 'name'=>$obj->name))) {
                    $dbobj->readablename = $obj->readablename;
                    $dbobj->vars = $obj->vars;
                    $result = $DB->update_record('qtype_programmedresp_conc', $dbobj);
        		
        	// Insert
        	} else {
                    $result = $DB->insert_record('qtype_programmedresp_conc', $obj);
        	}
        	
        	if (!$result) {
                    print_error('errordb', 'qtype_programmedresp');
        	}
        }
    }
}
//extendedquiz mod end

/**
 * This function updates the events associated to the quiz.
 * If $override is non-zero, then it updates only the events
 * associated with the specified override.
 *
 * @uses QUIZ_MAX_EVENT_LENGTH
 * @param object $quiz the quiz object.
 * @param object optional $override limit to a specific override
 */
function extendedquiz_update_events($quiz, $override = null) {
    global $DB;

    // Load the old events relating to this quiz.
    $conds = array('modulename'=>'extendedquiz',
                   'instance'=>$quiz->id);
    if (!empty($override)) {
        // Only load events for this override.
        $conds['groupid'] = isset($override->groupid)?  $override->groupid : 0;
        $conds['userid'] = isset($override->userid)?  $override->userid : 0;
    }
    $oldevents = $DB->get_records('event', $conds);

    // Now make a todo list of all that needs to be updated.
    if (empty($override)) {
        // We are updating the primary settings for the quiz, so we
        // need to add all the overrides.
        $overrides = $DB->get_records('extendedquiz_overrides', array('quiz' => $quiz->id));
        // As well as the original quiz (empty override).
        $overrides[] = new stdClass();
    } else {
        // Just do the one override.
        $overrides = array($override);
    }

    foreach ($overrides as $current) {
        $groupid   = isset($current->groupid)?  $current->groupid : 0;
        $userid    = isset($current->userid)? $current->userid : 0;
        $timeopen  = isset($current->timeopen)?  $current->timeopen : $quiz->timeopen;
        $timeclose = isset($current->timeclose)? $current->timeclose : $quiz->timeclose;

        // Only add open/close events for an override if they differ from the quiz default.
        $addopen  = empty($current->id) || !empty($current->timeopen);
        $addclose = empty($current->id) || !empty($current->timeclose);

        if (!empty($quiz->coursemodule)) {
            $cmid = $quiz->coursemodule;
        } else {
            $cmid = get_coursemodule_from_instance('extendedquiz', $quiz->id, $quiz->course)->id;
        }

        $event = new stdClass();
        $event->description = format_module_intro('extendedquiz', $quiz, $cmid);
        // Events module won't show user events when the courseid is nonzero.
        $event->courseid    = ($userid) ? 0 : $quiz->course;
        $event->groupid     = $groupid;
        $event->userid      = $userid;
        $event->modulename  = 'extendedquiz';
        $event->instance    = $quiz->id;
        $event->timestart   = $timeopen;
        $event->timeduration = max($timeclose - $timeopen, 0);
        $event->visible     = instance_is_visible('quiz', $quiz);
        $event->eventtype   = 'open';

        // Determine the event name.
        if ($groupid) {
            $params = new stdClass();
            $params->quiz = $quiz->name;
            $params->group = groups_get_group_name($groupid);
            if ($params->group === false) {
                // Group doesn't exist, just skip it.
                continue;
            }
            $eventname = get_string('overridegroupeventname', 'quiz', $params);
        } else if ($userid) {
            $params = new stdClass();
            $params->quiz = $quiz->name;
            $eventname = get_string('overrideusereventname', 'quiz', $params);
        } else {
            $eventname = $quiz->name;
        }
        if ($addopen or $addclose) {
            if ($timeclose and $timeopen and $event->timeduration <= EXTENDEDQUIZ_MAX_EVENT_LENGTH) {
                // Single event for the whole quiz.
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->name = $eventname;
                // The method calendar_event::create will reuse a db record if the id field is set.
                calendar_event::create($event);
            } else {
                // Separate start and end events.
                $event->timeduration  = 0;
                if ($timeopen && $addopen) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name = $eventname.' ('.get_string('quizopens', 'quiz').')';
                    // The method calendar_event::create will reuse a db record if the id field is set.
                    calendar_event::create($event);
                }
                if ($timeclose && $addclose) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name      = $eventname.' ('.get_string('quizcloses', 'quiz').')';
                    $event->timestart = $timeclose;
                    $event->eventtype = 'close';
                    calendar_event::create($event);
                }
            }
        }
    }

    // Delete any leftover events.
    foreach ($oldevents as $badevent) {
        $badevent = calendar_event::load($badevent);
        $badevent->delete();
    }
}

/**
 * @return array
 */
function extendedquiz_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * @return array
 */
function extendedquiz_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions',
            'delete attempt', 'manualgrade');
}

/**
 * @param array $questionids of question ids.
 * @return bool whether any of these questions are used by any instance of this module.
 */
function extendedquiz_questions_in_use($questionids) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    list($test, $params) = $DB->get_in_or_equal($questionids);
    return $DB->record_exists_select('extendedquiz_q_instances',
            'question ' . $test, $params) || question_engine::questions_in_use(
            $questionids, new qubaid_join('{extendedquiz_attempts} quiza',
            'quiza.uniqueid', 'quiza.preview = 0'));
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the quiz.
 *
 * @param $mform the course reset form that is being built.
 */
function extendedquiz_reset_course_form_definition($mform) {
    $mform->addElement('header', 'quizheader', get_string('modulenameplural', 'extendedquiz'));
    $mform->addElement('advcheckbox', 'reset_quiz_attempts',                                            //?¿?¿?¿
            get_string('removeallquizattempts', 'quiz'));
}

/**
 * Course reset form defaults.
 * @return array the defaults.
 */
function extendedquiz_reset_course_form_defaults($course) {
    return array('reset_quiz_attempts' => 1);
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string optional type
 */
function extendedquiz_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $quizzes = $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {extendedquiz} q ON cm.instance = q.id
            WHERE m.name = 'quiz' AND cm.course = ?", array($courseid));

    foreach ($quizzes as $quiz) {
        extendedquiz_grade_item_update($quiz, 'reset');
    }
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * quiz attempts for course $data->courseid, if $data->reset_quiz_attempts is
 * set and true.
 *
 * Also, move the quiz open and close dates, if the course start date is changing.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function extendedquiz_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');

    $componentstr = get_string('modulenameplural', 'quiz');
    $status = array();

    // Delete attempts.
    if (!empty($data->reset_quiz_attempts)) {
        question_engine::delete_questions_usage_by_activities(new qubaid_join(
                '{extendedquiz_attempts} quiza JOIN {extendedquiz} quiz ON quiza.quiz = quiz.id',
                'quiza.uniqueid', 'quiz.course = :quizcourseid',
                array('quizcourseid' => $data->courseid)));

        $DB->delete_records_select('extendedquiz_attempts',
                'quiz IN (SELECT id FROM {extendedquiz} WHERE course = ?)', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('attemptsdeleted', 'quiz'),
            'error' => false);

        // Remove all grades from gradebook.
        $DB->delete_records_select('extendedquiz_grades',
                'quiz IN (SELECT id FROM {extendedquiz} WHERE course = ?)', array($data->courseid));
        if (empty($data->reset_gradebook_grades)) {
            extendedquiz_reset_gradebook($data->courseid);
        }
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'quiz'),
            'error' => false);
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        $DB->execute("UPDATE {extendedquiz_overrides}
                         SET timeopen = timeopen + ?
                       WHERE quiz IN (SELECT id FROM {extendedquiz} WHERE course = ?)
                         AND timeopen <> 0", array($data->timeshift, $data->courseid));
        $DB->execute("UPDATE {extendedquiz_overrides}
                         SET timeclose = timeclose + ?
                       WHERE quiz IN (SELECT id FROM {extendedquiz} WHERE course = ?)
                         AND timeclose <> 0", array($data->timeshift, $data->courseid));

        shift_course_mod_dates('extendedquiz', array('timeopen', 'timeclose'),
                $data->timeshift, $data->courseid);

        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('openclosedatesupdated', 'quiz'),
            'error' => false);
    }

    return $status;
}

/**
 * Prints quiz summaries on MyMoodle Page
 * @param arry $courses
 * @param array $htmlarray
 */
function extendedquiz_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;
    // These next 6 Lines are constant in all modules (just change module name).
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$quizzes = get_all_instances_in_courses('extendedquiz', $courses)) {
        return;
    }

    // Fetch some language strings outside the main loop.
    $strquiz = get_string('modulename', 'extendedquiz');
    $strnoattempts = get_string('noattempts', 'quiz');

    // We want to list quizzes that are currently available, and which have a close date.
    // This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($quizzes as $quiz) {
        if ($quiz->timeclose >= $now && $quiz->timeopen < $now) {
            // Give a link to the quiz, and the deadline.
            $str = '<div class="extendedquiz overview">' .
                    '<div class="name">' . $strquiz . ': <a ' .
                    ($quiz->visible ? '' : ' class="dimmed"') .
                    ' href="' . $CFG->wwwroot . '/mod/extendedquiz/view.php?id=' .
                    $quiz->coursemodule . '">' .
                    $quiz->name . '</a></div>';
            $str .= '<div class="info">' . get_string('quizcloseson', 'quiz',
                    userdate($quiz->timeclose)) . '</div>';

            // Now provide more information depending on the uers's role.
            $context = context_module::instance($quiz->coursemodule);
            if (has_capability('mod/extendedquiz:viewreports', $context)) {
                // For teacher-like people, show a summary of the number of student attempts.
                // The $quiz objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                $str .= '<div class="info">' .
                        extendedquiz_num_attempt_summary($quiz, $quiz, true) . '</div>';
            } else if (has_any_capability(array('mod/extendedquiz:reviewmyattempts', 'mod/extendedquiz:attempt'),
                    $context)) { // Student
                // For student-like people, tell them how many attempts they have made.
                if (isset($USER->id) &&
                        ($attempts = extendedquiz_get_user_attempts($quiz->id, $USER->id))) {
                    $numattempts = count($attempts);
                    $str .= '<div class="info">' .
                            get_string('numattemptsmade', 'quiz', $numattempts) . '</div>';
                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }
            } else {
                // For ayone else, there is no point listing this quiz, so stop processing.
                continue;
            }

            // Add the output for this quiz to the rest.
            $str .= '</div>';
            if (empty($htmlarray[$quiz->course]['extendedquiz'])) {                                                       //¿?¿?¿?¿?¿?¿?
                $htmlarray[$quiz->course]['quiz'] = $str;
            } else {
                $htmlarray[$quiz->course]['quiz'] .= $str;
            }
        }
    }
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular quiz,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $quiz the quiz object. Only $quiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function extendedquiz_num_attempt_summary($quiz, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('extendedquiz_attempts', array('quiz'=> $quiz->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{extendedquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quiz = ? AND preview = 0 AND groupid = ?',
                        array($quiz->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'quiz', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{extendedquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quiz = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($quiz->id), $params));
                return get_string('attemptsnumyourgroups', 'quiz', $a);
            }
        }
        return get_string('attemptsnum', 'quiz', $numattempts);
    }
    return '';
}

/**
 * Returns the same as {@link quiz_num_attempt_summary()} but wrapped in a link
 * to the quiz reports.
 *
 * @param object $quiz the quiz object. Only $quiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the quiz context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function extendedquiz_attempt_summary_link_to_reports($quiz, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $CFG;
    $summary = extendedquiz_num_attempt_summary($quiz, $cm, $returnzero, $currentgroup);
    if (!$summary) {
        return '';
    }

    require_once($CFG->dirroot . '/mod/extendedquiz/report/reportlib.php');
    $url = new moodle_url('/mod/extendedquiz/report.php', array(
            'id' => $cm->id, 'mode' => extendedquiz_report_default_report($context)));
    return html_writer::link($url, $summary);
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if quiz supports feature
 */
function extendedquiz_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                    return true;
        case FEATURE_GROUPINGS:                 return true;
        case FEATURE_GROUPMEMBERSONLY:          return true;
        case FEATURE_MOD_INTRO:                 return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        case FEATURE_GRADE_HAS_GRADE:           return true;
        case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        case FEATURE_SHOW_DESCRIPTION:          return true;
        case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;
        case FEATURE_USES_QUESTIONS:            return true;

        default: return null;
    }
}

/**
 * @return array all other caps used in module
 */
function extendedquiz_get_extra_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    $caps = question_get_all_capabilities();
    $caps[] = 'moodle/site:accessallgroups';
    return $caps;
}

/**
 * This fucntion extends the global navigation for the site.
 * It is important to note that you should not rely on PAGE objects within this
 * body of code as there is no guarantee that during an AJAX request they are
 * available
 *
 * @param navigation_node $quiznode The quiz node within the global navigation
 * @param object $course The course object returned from the DB
 * @param object $module The module object returned from the DB
 * @param object $cm The course module instance returned from the DB
 */
function extendedquiz_extend_navigation($quiznode, $course, $module, $cm) {
    global $CFG;

    $context = context_module::instance($cm->id);

    if (has_capability('mod/extendedquiz:view', $context)) {
        $url = new moodle_url('/mod/extendedquiz/view.php', array('id'=>$cm->id));
        $quiznode->add(get_string('info', 'quiz'), $url, navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/info', ''));
    }

    if (has_any_capability(array('mod/extendedquiz:viewreports', 'mod/extendedquiz:grade'), $context)) {
        require_once($CFG->dirroot . '/mod/extendedquiz/report/reportlib.php');
        $reportlist = extendedquiz_report_list($context);

        $url = new moodle_url('/mod/extendedquiz/report.php',
                array('id' => $cm->id, 'mode' => reset($reportlist)));
        $reportnode = $quiznode->add(get_string('results', 'quiz'), $url,
                navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/report', ''));

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/extendedquiz/report.php',
                    array('id' => $cm->id, 'mode' => $report));
            $reportnode->add(get_string($report, 'quiz_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'quiz_report_' . $report, new pix_icon('i/item', ''));
        }
    }
}

/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $quiznode
 */
function extendedquiz_extend_settings_navigation($settings, $quiznode) {
    global $PAGE, $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $quiznode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    if (has_capability('mod/extendedquiz:manageoverrides', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/extendedquiz/overrides.php', array('cmid'=>$PAGE->cm->id));
        $node = navigation_node::create(get_string('groupoverrides', 'quiz'),
                new moodle_url($url, array('mode'=>'group')),
                navigation_node::TYPE_SETTING, null, 'mod_extendedquiz_groupoverrides');                                  //?¿¿?¿?¿?¿?¿
        $quiznode->add_node($node, $beforekey);

        $node = navigation_node::create(get_string('useroverrides', 'quiz'),
                new moodle_url($url, array('mode'=>'user')),
                navigation_node::TYPE_SETTING, null, 'mod_extendedquiz_useroverrides');
        $quiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/extendedquiz:manage', $PAGE->cm->context)) {
        $node = navigation_node::create(get_string('editquiz', 'quiz'),
                new moodle_url('/mod/extendedquiz/edit.php', array('cmid'=>$PAGE->cm->id)),
                navigation_node::TYPE_SETTING, null, 'mod_extendedquiz_edit',
                new pix_icon('t/edit', ''));
        $quiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/extendedquiz:preview', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/extendedquiz/startattempt.php',
                array('cmid'=>$PAGE->cm->id, 'sesskey'=>sesskey()));
        $node = navigation_node::create(get_string('preview', 'quiz'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_extendedquiz_preview',
                new pix_icon('i/preview', ''));
        $quiznode->add_node($node, $beforekey);
    }

    question_extend_settings_navigation($quiznode, $PAGE->cm->context)->trim_if_empty();
}

/**
 * Serves the quiz files.
 *
 * @package  mod_quiz
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function extendedquiz_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$quiz = $DB->get_record('extendedquiz', array('id'=>$cm->instance))) {
        return false;
    }

    // The 'intro' area is served by pluginfile.php.
    $fileareas = array('feedback');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $feedbackid = (int)array_shift($args);
    if (!$feedback = $DB->get_record('extendedquiz_feedback', array('id'=>$feedbackid))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_extendedquiz/$filearea/$feedbackid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Called via pluginfile.php -> question_pluginfile to serve files belonging to
 * a question in a question_attempt when that attempt is a quiz attempt.
 *
 * @package  mod_quiz
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param int $qubaid the attempt usage id.
 * @param int $slot the id of a question in this quiz attempt.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function extendedquiz_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options=array()) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/extendedquiz/locallib.php');

    $attemptobj = extendedquiz_attempt::create_from_usage_id($qubaid);
    require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

    if ($attemptobj->is_own_attempt() && !$attemptobj->is_finished()) {
        // In the middle of an attempt.
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/extendedquiz:attempt');
        }
        $isreviewing = false;

    } else {
        // Reviewing an attempt.
        $attemptobj->check_review_capability();
        $isreviewing = true;
    }

    if (!$attemptobj->check_file_access($slot, $isreviewing, $context->id,
            $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function extendedquiz_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-extendedquiz-*'=>get_string('page-mod-quiz-x', 'quiz'),
        'mod-extendedquiz-edit'=>get_string('page-mod-quiz-edit', 'quiz'));
    return $module_pagetype;
}

/**
 * @return the options for quiz navigation.
 */
function extendedquiz_get_navigation_options() {
    return array(
        EXTENDEDQUIZ_NAVMETHOD_FREE => get_string('navmethod_free', 'quiz'),
        EXTENDEDQUIZ_NAVMETHOD_SEQ  => get_string('navmethod_seq', 'quiz')
    );
}
