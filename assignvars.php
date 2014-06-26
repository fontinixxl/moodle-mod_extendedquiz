<?php 

require_once('../../config.php');
require_once($CFG->dirroot. '/mod/extendedquiz/extendedquiz_assignvars_form.php');
require_once($CFG->dirroot. '/mod/extendedquiz/locallib.php');

$quizid = required_param('quizid', PARAM_INT);
$questionid = required_param('questionid', PARAM_INT);

if (!$quiz = $DB->get_record('extendedquiz', array('id' => $quizid))) {
    print_error('invalidquizid', 'extendedquiz');
}
if (!$course = $DB->get_record('course', array('id' => $quiz->course))) {
    print_error('invalidcourseid');
}
if (!$cm = get_coursemodule_from_instance("extendedquiz", $quiz->id, $course->id)) {
    print_error('invalidcoursemodule');
}
// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/extendedquiz:manage', $context);

// Initialize $PAGE
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url($CFG->wwwroot.'/mod/extendedquiz/assignvars.php', array('quizid' => $quizid, 'questionid' => $questionid)));
$title = get_string('assignvars', 'extendedquiz');
$PAGE->set_title($title);
//print_header_simple($title, $title);
$PAGE->set_heading($title);
echo $OUTPUT->header();

// Arguments
$args = extendedquiz_get_question_extendedquiz_args($questionid, true);
if (!$args) {
	print_error('errornoargs', 'extendedquiz');
}

// Getting stored data
foreach ($args as $key => $arg) {
	if ($vararg = $DB->get_record('extendedquiz_var_arg', array('quizid' => $quizid , 'programmedrespargid' => $arg->id))) {
	    $toform->{'arg_'.$arg->id} = $vararg->type.'_'.$vararg->instanceid;
	}
}

// Guidedquiz vars
$extendedquizvars = $DB->get_records('extendedquiz_var', array('quizid' => $quizid));
if (!$extendedquizvars) {
	print_error('errornoquizvars', 'extendedquiz');
}

$concatvars = $DB->get_records_select('qtype_programmedresp_conc', "origin = 'quiz' AND instanceid = '$quizid'");

// Preprocess quiz vars -> options
foreach ($extendedquizvars as $extendedquizvar) {
    $options['var_'.$extendedquizvar->id] = $extendedquizvar->varname.' ('.get_string('vartypevar', 'extendedquiz').')';
}

// Now the concat vars
if ($concatvars) {
	foreach ($concatvars as $var) {
	    $options['concatvar_'.$var->id] = $var->name.' ('.get_string('vartypeconcatvar', 'extendedquiz').')';
	}
}

$toform->quizid = $quizid;
$toform->questionid = $questionid;

$url = $CFG->wwwroot.'/mod/extendedquiz/assignvars.php';

//$url = new moodle_url($CFG->wwwroot.'/mod/extendedquiz/assignvars.php', array('quizid' => $quizid, 'questionid' => $questionid));

$customdata['extendedquizvars'] = $options;
$customdata['args'] = $args;
$form = new extendedquiz_assignvars_form($url, $customdata);

// Cancelled?
if ($form->is_cancelled()) {
	echo '<script type="text/javascript">window.close();</script>';
}

// Submitted
if ($values = $form->get_data()) {

    $obj->quizid = $quizid;

    // Inserting new ones
    foreach ($values as $key => $value) {
        if (substr($key, 0, 4) == 'arg_') {

            $argdata = explode('_', $key);

            // Deleting old values
            $DB->delete_records('extendedquiz_var_arg', array('quizid' => $quizid, 'programmedrespargid' => $argdata[1]));

            $selectedvalue = explode('_', $value);
            $obj->type = clean_param($selectedvalue[0], PARAM_ALPHA);
            $obj->instanceid = clean_param($selectedvalue[1], PARAM_INT);
            $obj->programmedrespargid = $argdata[1];

            if (!$obj->id = $DB->insert_record('extendedquiz_var_arg', $obj)) {
                print_error('errordb', 'qtype_programmedresp');
            }
        }
    }
    echo '<script type="text/javascript">window.close();</script>';
}

$form->set_data($toform);
$form->display();

echo $OUTPUT->footer();
