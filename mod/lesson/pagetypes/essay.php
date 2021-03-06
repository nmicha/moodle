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
 * Essay
 *
 * @package    mod
 * @subpackage lesson
 * @copyright  2009 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

defined('MOODLE_INTERNAL') || die();

/** Essay question type */
define("LESSON_PAGE_ESSAY", "10");

class lesson_page_type_essay extends lesson_page {

    protected $type = lesson_page::TYPE_QUESTION;
    protected $typeidstring = 'essay';
    protected $typeid = LESSON_PAGE_ESSAY;
    protected $string = null;

    public function get_typeid() {
        return $this->typeid;
    }
    public function get_typestring() {
        if ($this->string===null) {
            $this->string = get_string($this->typeidstring, 'lesson');
        }
        return $this->string;
    }
    public function get_idstring() {
        return $this->typeidstring;
    }
    public function display($renderer, $attempt) {
        global $PAGE, $CFG, $USER;

        $mform = new lesson_display_answer_form_essay($CFG->wwwroot.'/mod/lesson/continue.php', array('contents'=>$this->get_contents()));

        $data = new stdClass;
        $data->id = $PAGE->cm->id;
        $data->pageid = $this->properties->id;
        if (isset($USER->modattempts[$this->lesson->id])) {
            $essayinfo = unserialize($attempt->useranswer);
            $data->answer = array('text'=>$essayinfo->answer, 'format'=>FORMAT_HTML);
        }
        $mform->set_data($data);
        return $mform->display();
    }
    public function create_answers($properties) {
        global $DB;
        // now add the answers
        $newanswer = new stdClass;
        $newanswer->lessonid = $this->lesson->id;
        $newanswer->pageid = $this->properties->id;
        $newanswer->timecreated = $this->properties->timecreated;

        if (isset($properties->jumpto[0])) {
            $newanswer->jumpto = $properties->jumpto[0];
        }
        if (isset($properties->score[0])) {
            $newanswer->score = $properties->score[0];
        }
        $newanswer->id = $DB->insert_record("lesson_answers", $newanswer);
        $answers = array($newanswer->id => new lesson_page_answer($newanswer));
        $this->answers = $answers;
        return $answers;
    }
    public function check_answer() {
        global $PAGE, $CFG;
        $result = parent::check_answer();
        $result->isessayquestion = true;

        $mform = new lesson_display_answer_form_essay($CFG->wwwroot.'/mod/lesson/continue.php', array('contents'=>$this->get_contents()));
        $data = $mform->get_data();
        require_sesskey();

        if (!$data) {
            redirect(new moodle_url('/mod/lesson/view.php', array('id'=>$PAGE->cm->id, 'pageid'=>$this->properties->id)));
        }

        $studentanswer = $data->answer['text'];
        if (trim($studentanswer) === '') {
            $result->noanswer = true;
            return $result;
        }

        $answers = $this->get_answers();
        foreach ($answers as $answer) {
            $result->answerid = $answer->id;
            $result->newpageid = $answer->jumpto;
        }

        $userresponse = new stdClass;
        $userresponse->sent=0;
        $userresponse->graded = 0;
        $userresponse->score = 0;
        $userresponse->answer = $studentanswer;
        $userresponse->response = "";
        $result->userresponse = serialize($userresponse);

        $result->studentanswer = s($studentanswer);
        return $result;
    }
    public function update($properties) {
        global $DB, $PAGE;
        $answers  = $this->get_answers();
        $properties->id = $this->properties->id;
        $properties->lessonid = $this->lesson->id;
        $properties = file_postupdate_standard_editor($properties, 'contents', array('noclean'=>true, 'maxfiles'=>EDITOR_UNLIMITED_FILES, 'maxbytes'=>$PAGE->course->maxbytes), get_context_instance(CONTEXT_MODULE, $PAGE->cm->id), 'mod_lesson', 'page_contents', $properties->id);
        $DB->update_record("lesson_pages", $properties);

        if (!array_key_exists(0, $this->answers)) {
            $this->answers[0] = new stdClass;
            $this->answers[0]->lessonid = $this->lesson->id;
            $this->answers[0]->pageid = $this->id;
            $this->answers[0]->timecreated = $this->timecreated;
        }
        if (isset($properties->jumpto[0])) {
            $this->answers[0]->jumpto = $properties->jumpto[0];
        }
        if (isset($properties->score[0])) {
            $this->answers[0]->score = $properties->score[0];
        }
        if (!isset($this->answers[0]->id)) {
            $this->answers[0]->id =  $DB->insert_record("lesson_answers", $this->answers[0]);
        } else {
            $DB->update_record("lesson_answers", $this->answers[0]->properties());
        }

        return true;
    }
    public function stats(array &$pagestats, $tries) {
        if(count($tries) > $this->lesson->maxattempts) { // if there are more tries than the max that is allowed, grab the last "legal" attempt
            $temp = $tries[$this->lesson->maxattempts - 1];
        } else {
            // else, user attempted the question less than the max, so grab the last one
            $temp = end($tries);
        }
        $essayinfo = unserialize($temp->useranswer);
        if ($essayinfo->graded) {
            if (isset($pagestats[$temp->pageid])) {
                $essaystats = $pagestats[$temp->pageid];
                $essaystats->totalscore += $essayinfo->score;
                $essaystats->total++;
                $pagestats[$temp->pageid] = $essaystats;
            } else {
                $essaystats->totalscore = $essayinfo->score;
                $essaystats->total = 1;
                $pagestats[$temp->pageid] = $essaystats;
            }
        }
        return true;
    }
    public function report_answers($answerpage, $answerdata, $useranswer, $pagestats, &$i, &$n) {
        $answers = $this->get_answers();
        $formattextdefoptions = new stdClass;
        $formattextdefoptions->para = false;  //I'll use it widely in this page
        foreach ($answers as $answer) {
            if ($useranswer != NULL) {
                $essayinfo = unserialize($useranswer->useranswer);
                if ($essayinfo->response == NULL) {
                    $answerdata->response = get_string("nocommentyet", "lesson");
                } else {
                    $answerdata->response = s($essayinfo->response);
                }
                if (isset($pagestats[$this->properties->id])) {
                    $percent = $pagestats[$this->properties->id]->totalscore / $pagestats[$this->properties->id]->total * 100;
                    $percent = round($percent, 2);
                    $percent = get_string("averagescore", "lesson").": ". $percent ."%";
                } else {
                    // dont think this should ever be reached....
                    $percent = get_string("nooneansweredthisquestion", "lesson");
                }
                if ($essayinfo->graded) {
                    if ($this->lesson->custom) {
                        $answerdata->score = get_string("pointsearned", "lesson").": ".$essayinfo->score;
                    } elseif ($essayinfo->score) {
                        $answerdata->score = get_string("receivedcredit", "lesson");
                    } else {
                        $answerdata->score = get_string("didnotreceivecredit", "lesson");
                    }
                } else {
                    $answerdata->score = get_string("havenotgradedyet", "lesson");
                }
            } else {
                $essayinfo->answer = get_string("didnotanswerquestion", "lesson");
            }

            if (isset($pagestats[$this->properties->id])) {
                $avescore = $pagestats[$this->properties->id]->totalscore / $pagestats[$this->properties->id]->total;
                $avescore = round($avescore, 2);
                $avescore = get_string("averagescore", "lesson").": ". $avescore ;
            } else {
                // dont think this should ever be reached....
                $avescore = get_string("nooneansweredthisquestion", "lesson");
            }
            $answerdata->answers[] = array(s($essayinfo->answer), $avescore);
            $answerpage->answerdata = $answerdata;
        }
        return $answerpage;
    }
    public function is_unanswered($nretakes) {
        global $DB, $USER;
        if (!$DB->count_records("lesson_attempts", array('pageid'=>$this->properties->id, 'userid'=>$USER->id, 'retry'=>$nretakes))) {
            return true;
        }
        return false;
    }
    public function requires_manual_grading() {
        return true;
    }
    public function get_earnedscore($answers, $attempt) {
        $essayinfo = unserialize($attempt->useranswer);
        return $essayinfo->score;
    }
}

class lesson_add_page_form_essay extends lesson_add_page_form_base {

    public $qtype = 'essay';
    public $qtypestring = 'essay';

    public function custom_definition() {

        $this->add_jumpto(0);
        $this->add_score(0, null, 1);

    }
}

class lesson_display_answer_form_essay extends moodleform {

    public function definition() {
        global $USER, $OUTPUT;
        $mform = $this->_form;
        $contents = $this->_customdata['contents'];

        $mform->addElement('header', 'pageheader');

        $mform->addElement('html', $OUTPUT->container($contents, 'contents'));

        $options = new stdClass;
        $options->para = false;
        $options->noclean = true;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'pageid');
        $mform->setType('pageid', PARAM_INT);

        $mform->addElement('editor', 'answer', get_string('youranswer', 'lesson'), null, null);
        $mform->setType('answer', PARAM_RAW);

        $this->add_action_buttons(null, get_string("pleaseenteryouranswerinthebox", "lesson"));
    }

}
