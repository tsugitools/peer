<?php
require_once "../config.php";
use \Tsugi\Blob\BlobUtil;

require_once "peer_util.php";

use \Tsugi\Util\U;
use \Tsugi\Core\Cache;
use \Tsugi\Core\Settings;
use \Tsugi\Core\LTIX;
use \Tsugi\Util\LTI;
use \Tsugi\UI\SettingsForm;

// Sanity checks
$LAUNCH = LTIX::requireData();
$p = $CFG->dbprefix;

if ( SettingsForm::handleSettingsPost() ) {
    header( 'Location: '.addSession('index') ) ;
    return;
}

// Grab the due date information
$dueDate = SettingsForm::getDueDate();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && count($_POST) < 1 ) {
    $_SESSION['error'] = 'File upload size exceeded, please re-upload a smaller file';
    error_log("Upload size exceeded");
    header('Location: '.addSession('index'));
    return;
}

// Model
$row = loadAssignment();
$assn_json = null;
$assn_id = false;
if ( $row !== false && strlen($row['json']) > 0 ) {
    $assn_json = json_decode(upgradeSubmission($row['json']));
    $assn_id = $row['assn_id'];
}

$upload_max_size_bytes = BlobUtil::maxUploadBytes();
$image_max = isset($assn_json->image_size) ? (int) $assn_json->image_size : 0;
$image_max = $image_max * 1024 * 1024;
if ( $image_max == 0 ) $image_max = $upload_max_size_bytes;
$image_max_text = U::displaySize($image_max);

$pdf_max = isset($assn_json->pdf_size) ? (int) $assn_json->pdf_size : 0;
$pdf_max = $pdf_max * 1024 * 1024;
if ( $pdf_max == 0 ) $pdf_max = $upload_max_size_bytes;
$pdf_max_text = U::displaySize($pdf_max);

// Load up the submission and parts if they exist
$submit_id = false;
$submit_row = loadSubmission($assn_id, $USER->id);
if ( $submit_row !== false ) $submit_id = $submit_row['submit_id'];

// Handle the submission post
if ( $assn_id != false && $assn_json != null &&
    isset($_POST['notes']) && isset($_POST['doSubmit']) ) {
    if ( $submit_row !== false ) {
        $_SESSION['error'] = 'Cannot submit an assignment twice';
        header( 'Location: '.addSession('index') ) ;
        return;
    }

    /* TODO: Remove this 
    // Check all files to be within our size limit
    foreach($_FILES as $fdes) {
        if ( $fdes['size'] > 1024*1024 ) {
            $_SESSION['error'] = 'Error - '.$fdes['name'].' has a size of '.$fdes['size'].' (1M max size per file)';
            header( 'Location: '.addSession('index') ) ;
            return;
        }
    }
     */

    $blob_ids = array();
    $urls = array();
    $code_ids = array();
    $html_ids = array();
    $content_items = array();
    $partno = 0;
    foreach ( $assn_json->parts as $part ) {
        if ( $part->type == 'image' || $part->type == 'pdf') {
            $fname = 'uploaded_file_'.$partno;
            if( ! isset($_FILES[$fname]) ) {
                $_SESSION['error'] = 'Problem with uploaded files - perhaps your files were too large';
                header( 'Location: '.addSession('index') ) ;
                return;
            }

            $fdes = $_FILES[$fname];
            $filename = isset($fdes['name']) ? basename($fdes['name']) : false;

            // Check to see if they left off a file
            if( $fdes['error'] == 4) {
                $_SESSION['error'] = 'Missing file, make sure to select all files before pressing submit';
                header( 'Location: '.addSession('index') ) ;
                return;
            }

            $filesize = $part->type == 'image' ? $assn_json->image_size : $assn_json->pdf_size;
            $filesize = $filesize * 1024 * 1024;
            if ( $filesize == 0 ) $filesize = $upload_max_size_bytes;
            $filesize_text = U::displaySize($filesize);
            if ( $fdes['size'] > $filesize ) {
                $_SESSION['error'] = 'Error - '.$fdes['name'].' has a size of '.$fdes['size'].' which is > '.$filesize_text;
                header( 'Location: '.addSession('index') ) ;
                return;
            }

            // Sanity-check the file
            $mime_types =  $part->type == 'image' ? array("image/png", "image/jpeg") : array("application/pdf");
            $safety = BlobUtil::checkFileSafety($fdes, $mime_types);
            if ( $safety !== true ) {
                $_SESSION['error'] = "Error: ".$safety;
                error_log("Upload Error: ".$safety);
                header( 'Location: '.addSession('index') ) ;
                return;
            }

            // Check the kind of file
            if ( $part->type == 'image' && ! BlobUtil::isPngOrJpeg($fdes) ) {
                $_SESSION['error'] = 'Files must either contain JPG, or PNG images: '.$filename;
                error_log("Upload Error - Not an Image: ".$filename);
                header( 'Location: '.addSession('index') ) ;
                return;
            }

            // Indicate this is not really used yet in case the submission is not saved
            // We update this by calling setBackref after the submission is complete
            $backref = "{$p}peer_submit::submit_id::-1";
            $SAFETY_CHECK=true;
            $blob_id = BlobUtil::uploadToBlob($fdes, $SAFETY_CHECK, $backref);
            if ( $blob_id === false ) {
                $_SESSION['error'] = 'Problem storing file in server: '.$filename;
                header( 'Location: '.addSession('index') ) ;
                return;
            }
            $blob_ids[] = $blob_id;
        } else if ( $part->type == 'url' ) {
            $url = $_POST['input_url_'.$partno];
            if ( strpos($url,'http://') === false && strpos($url,'https://') === false ) {
                $_SESSION['error'] = 'URLs must start with http:// or https:// ';
                header( 'Location: '.addSession('index') ) ;
                return;
            }
            $urls[] = $_POST['input_url_'.$partno];
        } else if ( $part->type == 'content_item' ) {
            $content_item = $_POST['input_content_item_'.$partno];
            $content_data = json_decode($content_item);
            if ( $content_data === null || ! isset($content_data->url)) {
                $_SESSION['error'] = 'ContentItems must be valid JSON';
                header( 'Location: '.addSession('index') ) ;
                return;
            }
            $content_items[] = $content_data;
        } else if ( $part->type == 'code' ) {
            $code = $_POST['input_code_'.$partno];
            if( strlen($code) < 1 ) {
                $_SESSION['error'] = 'Missing: '.$part->title;
                header( 'Location: '.addSession('index') ) ;
                return;
            }
            $PDOX->queryDie("
                INSERT INTO {$p}peer_text
                    (assn_id, user_id, data, created_at, updated_at)
                    VALUES ( :AID, :UID, :DATA, NOW(), NOW() )",
                array(
                    ':AID' => $assn_id,
                    ':DATA' => $code,
                    ':UID' => $USER->id)
            );
            $code_ids[] = $PDOX->lastInsertId();
        } else if ( $part->type == 'html' ) {
            $html = $_POST['input_html_'.$partno];
            if( strlen($html) < 1 ) {
                $_SESSION['error'] = 'Missing: '.$part->title;
                header( 'Location: '.addSession('index') ) ;
                return;
            }
            $PDOX->queryDie("
                INSERT INTO {$p}peer_text
                    (assn_id, user_id, data, created_at, updated_at)
                    VALUES ( :AID, :UID, :DATA, NOW(), NOW() )",
                array(
                    ':AID' => $assn_id,
                    ':DATA' => $html,
                    ':UID' => $USER->id)
            );
            $html_ids[] = $PDOX->lastInsertId();
        }
        $partno++;
    }

    $submission = new stdClass();
    $submission->notes = $_POST['notes'];
    $submission->blob_ids = $blob_ids;
    $submission->urls = $urls;
    $submission->codes = $code_ids;
    $submission->htmls = $html_ids;
    $submission->content_items = $content_items;
    $json = json_encode($submission);
    $stmt = $PDOX->queryReturnError(
        "INSERT INTO {$p}peer_submit
            (assn_id, user_id, json, created_at, updated_at)
            VALUES ( :AID, :UID, :JSON, NOW(), NOW())
            ON DUPLICATE KEY UPDATE json = :JSON, updated_at = NOW()",
        array(
            ':AID' => $assn_id,
            ':JSON' => $json,
            ':UID' => $USER->id)
    );


    Cache::clear('peer_submit');
    if ( $stmt->success ) {
        $submit_id = $PDOX->lastInsertId();
        // Mark our blobs as belonging to this submission
        $backref = "{$p}peer_submit::submit_id::".$submit_id;
        foreach($blob_ids as $file_id ) {
            BlobUtil::setBackref($file_id, $backref);
        }
        $_SESSION['success'] = 'Assignment submitted';
        header( 'Location: '.addSession('index') ) ;
    } else {
        $_SESSION['error'] = $stmt->errorImplode;
        header( 'Location: '.addSession('index') ) ;
    }
    return;
}

// See if we are going to delete the submission
if ( isset($assn_json) && isset($assn_json->resubmit) &&
    $assn_json->resubmit == "always" && $dueDate->dayspastdue <= 0 &&
    $assn_id && $submit_id && isset($_POST['deleteSubmit']) ) {

    deleteSubmission($row, $submit_row);

    $msg = "Deleted submission for user ".$USER->id." ".$USER->email;
    error_log($msg);
    $_SESSION['success'] = "Submission deleted.";
    header( 'Location: '.addSession('index') ) ;
    return;
}

// Check to see how much grading we have done
$grade_count = 0;
$to_grade = 0;
if ( $assn_json && $assn_json->maxassess > 0 ) {
    // See how much grading is left to do
    $to_grade = loadUngraded($assn_id);

    // See how many grades I have done
    $grade_count = loadMyGradeCount($assn_id);
}

// Retrieve our grades...
$our_grades = retrieveSubmissionGrades($submit_id);

// Handle the flag...
if ( $assn_id != false && $assn_json != null && is_array($our_grades) &&
    isset($_POST['submit_id']) && isset($_POST['grade_id']) && isset($_POST['note']) &&
    isset($_POST['doFlag']) && $submit_id == $_POST['submit_id'] ) {

    // Make sure we have a valid grade_id
    $found = false;
    foreach ( $our_grades as $grade ) {
        if ( $grade['grade_id'] == $_POST['grade_id'] ) {
            $found = true;
        }
    }
    if ( ! $found ) {
        $_SESSION['error'] = 'Cannot a grade that is not yours';
        header( 'Location: '.addSession('index') ) ;
        return;
    }

    $grade_id = $_POST['grade_id']+0;
    $stmt = $PDOX->queryDie(
        "INSERT INTO {$p}peer_flag
            (submit_id, grade_id, user_id, note, created_at, updated_at)
            VALUES ( :SID, :GID, :UID, :NOTE, NOW(), NOW())
            ON DUPLICATE KEY UPDATE note = :NOTE, updated_at = NOW()",
        array(
            ':SID' => $submit_id,
            ':GID' => $grade_id,
            ':UID' => $USER->id,
            ':NOTE' => $_POST['note'])
    );
    $_SESSION['success'] = "Flagged for the instructor to examine";
    header( 'Location: '.addSession('index') ) ;
    return;
}

$menu = false;
if ( $USER->instructor ) {
    $menu = new \Tsugi\UI\MenuSet();
    if ( $assn_json !== null ) {
        $menu->addLeft('Student Data', 'admin');
    }
    $submenu = new \Tsugi\UI\Menu();
    $submenu->addLink('Settings', '#', /* push */ false, SettingsForm::attr());
    $submenu->addLink('Configure', 'configure');
    if ( $CFG->launchactivity ) {
        $submenu->addLink('Analytics', 'analytics');
    }
    if ( $assn_json != null && $assn_json->totalpoints > 0 ) {
        $submenu->addLink('Maintenance', 'maint', 'target="_blank"');
    }
    $submenu->addLink('Debug Data', 'debug');
    $menu->addRight('Instructor', $submenu);
}

// View
$OUTPUT->header();
?>
<link href="<?= U::get_rest_parent() ?>/static/prism.css" rel="stylesheet"/>
<!-- https://webaim.org/techniques/css/invisiblecontent/ -->
<script>
let html_loads = [];
</script>
<style>
.skipNav
{
position: absolute;
top: -30em;
left: -30em;
padding: 0 0 0 0;
}
</style>
<?php

$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

?>
<div class="skipNav"><a href="access.php" 
  title="accessibility options for this assignment" 
  accesskey="a">Click here for accessibility options for this assignment</a></div>
<?php

if ( $USER->instructor ) {
    SettingsForm::start();
    SettingsForm::dueDate();
    SettingsForm::done();
    SettingsForm::end();
}

$OUTPUT->welcomeUserCourse();

if ( $assn_json != null ) {
    echo('<div style="border: 1px solid black">');
    echo("<p><h4>".$assn_json->title."</h4></p>\n");
    echo('<p>'.htmlent_utf8($assn_json->description)."</p><p>\n");
    echo('<p>'.htmlent_utf8($assn_json->grading)."\n");
    if ( $assn_json->gallery != 'off' ) {
        echo("<p>This assignment includes a public gallery where you can view all\n");
        echo("student submissions.</p>\n");
    }
    if( isset($assn_json->assignment) ) {
        echo('<br/>Assignment specification: <a href="'.$assn_json->assignment.'" target="_blank">');
        echo($assn_json->assignment."</a>\n");
    }
    if( isset($assn_json->solution) ) {
        echo('<br/>Sample solution: <a href="'.$assn_json->solution.'" target="_blank">');
        echo($assn_json->solution."</a>\n");
    }
    echo('</p></div>');
}

if ( $assn_json == null ) {
    echo('<p>This assignment is not yet configured</p>');
    $OUTPUT->footer();
    return;
}

$image_count = 0;
if ( $submit_row == false ) {
    if ( $assn_json->gallery == 'always' ) {
        echo('<p><a href="gallery.php" class="btn btn-default">View Student Submissions</a></p> '."\n");
    }
    echo("<p><b>Please Upload Your Submission:</b></p>\n");
    echo('<form name="myform" enctype="multipart/form-data" method="post" action="'.
         addSession('index').'">');

    $partno = 0;
    $content_items = array();
    $html_items = array();
    foreach ( $assn_json->parts as $part ) {
        echo("\n<p>");
        echo(htmlent_utf8($part->title)."\n");
        if ( $part->type == "image" ) {
            $image_count++;
            echo('<input name="uploaded_file_'.$partno.'" data-max-size="'.$image_max.'"
                accept="image/png, image/jpg"
                type="file" class="tsugi_image"> (Please use PNG or JPG files '.$image_max_text.' max)</p>');
        } else if ( $part->type == "pdf" ) {
            $image_count++;
            echo('<input name="uploaded_file_'.$partno.'" data-max-size="'.$pdf_max.'"
                accept="application/pdf"
                type="file" class="tsugi_pdf"> (Please upload a PDF '.$pdf_max_text.' max)</p>');
        } else if ( $part->type == "content_item" ) {
            $endpoint = $part->launch;
            $info = LTIX::getKeySecretForLaunch($endpoint);
            $content_items[] = $partno;
            if ( $info === false ) {
                echo('<p style="color:red">Unable to load key/secret for '.htmlentities($endpoint)."</p>\n");
            } else {
                $icon = $CFG->staticroot.'/font-awesome-4.4.0/png/check-square.png';
                echo('<br/><button type="button" onclick="showModalIframe(\''.$part->title.'\',
                    \'content_item_dialog_'.$partno.'\',\'content_item_frame_'.$partno.'\', false); return false;">
                    Select/Create Item</button>'."\n");
                echo('<img src="'.$icon.'" id="input_content_icon_'.$partno.'" style="display: none">'."\n");
                echo('<br/><textarea name="input_content_item_'.$partno.'" id="input_content_item_'.$partno.'" rows="2" style="display: none; width: 90%"></textarea></p>');
            }
        } else if ( $part->type == "url" ) {
            echo('<input name="input_url_'.$partno.'" type="url" size="80"></p>');
        } else if ( $part->type == "code" ) {
            echo('<br/><textarea name="input_code_'.$partno.'" rows="10" style="width: 90%"></textarea></p>');
        } else if ( $part->type == "html" ) {
            $html_items[] = $partno;
            echo('<br/><textarea name="input_html_'.$partno.'" id="input_html_'.$partno.'" rows="10" style="width: 90%"></textarea></p>');
        }
        $partno++;
    }
    echo("<p>Enter optional comments below</p>\n");
    echo('<textarea rows="5" style="width: 90%" name="notes"></textarea><br/>');
    echo('<input type="submit" name="doSubmit" value="Submit" class="btn btn-default"> ');
    $OUTPUT->exitButton('Cancel');
    echo('</form>');

    // Make all the dialogs here
    $partno = 0;
    foreach ( $assn_json->parts as $part ) {

        if ( $part->type != "content_item" ) {
            $partno++;
            continue;
        }

        $return = $CFG->getCurrentUrlFolder()."/contentitem_return.php?partno=".$partno;
        $return = addSession($return);

        $parms = LTIX::getContentItem($return,array());

        $endpoint = $part->launch;
        $info = LTIX::getKeySecretForLaunch($endpoint);
        $key = $info['key'];
        $secret = $info['secret'];

        $parms = LTI::signParameters($parms, $endpoint, "POST", $key, $secret,
                "Begin Selection");

        $content = LTI::postLaunchHTML($parms, $endpoint, true,
            "width=\"100%\" height=\"500\" scrolling=\"auto\" frameborder=\"1\" transparency");

?>
<div id="content_item_dialog_<?= $partno ?>" title="Media dialog" style="display:none;">
<?= $content ?>
</div>
<?php
        $partno++;
    }

    if ( $image_count > 0 ) {
        $upload_max_size = ini_get('upload_max_filesize');
        echo("\n<p>Make sure each uploaded image file is smaller than 1M.  Total upload size limited to ");
        echo(htmlent_utf8($upload_max_size)."</p>\n");
    }
    if ( isset($assn_json->totalpoints) && $assn_json->totalpoints > 0 ) {
        echo("<p>");
        echo(pointsDetail($assn_json));
        echo("</p>");
    }
    $OUTPUT->footerStart();
?>
<script>
// Set up the checking of data-max-size in type="file" inputs
tsugiCheckFileMaxSize();

$('.basicltiDebugToggle').hide();
</script>
<script src="https://cdn.ckeditor.com/ckeditor5/16.0.0/classic/ckeditor.js"></script>
<script>
    html_items = [];
<?php
    foreach($html_items as $html_item) {
        echo("html_items.push($html_item);\n");
    }
?>
    for(i=0; i< html_items.length; i++ ) {
        var the_item = html_items[i];

ClassicEditor.defaultConfig = {
    toolbar: {
        items: [
            'heading',
            '|',
            'bold',
            'italic',
            'link',
            'bulletedList',
            'numberedList',
            // 'imageUpload',
            'blockQuote',
            'insertTable',
            'mediaEmbed',
            'undo',
            'redo'
        ]
    },

}

        ClassicEditor
            .create( document.querySelector( '#input_html_'+the_item )
            ).then(editor => {
                // editor.isReadOnly = true;
            } ).catch( error => {
                console.error( error );
            } );
        console.log("Item", html_items[i]);
    }
</script>
<?php
    $OUTPUT->footerEnd();
    return;
}

// We have a submission already
$submit_json = json_decode($submit_row['json']);

if ( $assn_json->maxassess > 0 ) {
    if ( $submit_json && isset($submit_json->peer_exempt) ) {
        echo("<p>You have no more peers to grade.</p>\n");
    } else if ( count($to_grade) > 0 &&
        ($USER->instructor || $grade_count < $assn_json->maxassess ) ) {
        if ( $assn_json->rating > 0 ) {
            echo('<p><a href="grade" class="btn btn-default">Rate other students</a></p>'."\n");
        } else {
            echo('<p><a href="grade" class="btn btn-default">Review other students</a></p>'."\n");
        }

        // Add a done button if needed
        echo("<p> You have reviewed ".$grade_count." other student submissions.
            You must review at least ".$assn_json->minassess." submissions for
            full credit on this assignment.\n");
        if ( $assn_json->maxassess < 100 ) {
            echo("You <i>can</i> review up to ".$assn_json->maxassess." submissions if you like.\n");
        }
        echo("</p>\n");
    } else if ( count($to_grade) > 0 ) {
        echo('<p>You have reviewed the maximum number of submissions. Congratulations!<p>');
    } else {
        echo('<p>There are no submisions ready to be reviewed. Please check back later.</p>');
    }
}

if ( $assn_json->gallery != 'off') {
    echo('<p><a href="gallery.php" class="btn btn-default">View All Submissions</a></p> '."\n");
}

echo("<p><b>Your Submission:</b></p>\n");
showSubmission($assn_json, $submit_json, $assn_id, $USER->id);

if ( $submit_row['inst_points'] > 0 ) {
    echo("<p>Instructor grade on assignment: ". $submit_row['inst_points']."</p>\n");
}

if ( strlen($submit_row['inst_note']) > 0 ) {
    echo("<p>Instructor Note:<br/>");
    echo(htmlent_utf8($submit_row['inst_note']));
    echo("</p>\n");
}

if ( $assn_json->maxassess < 1 ) {
    // Do nothing
} else if ( count($our_grades) < 1 ) {
    echo("<p>No peers have graded your submission yet.</p>");
} else {
    echo("<div style=\"padding:3px\"><p>You have the following grades from other students:</p>");
    echo('<table border="1" class="table table-hover table-condensed table-responsive"><tr>');
    if ( $assn_json->peerpoints > 0 ) echo("<th>Points</th>");
    echo("<th>Comments</th>");
    if ( $assn_json->flag ) echo("<th>Action</th>");
    echo("</tr>\n");

    $max_points = false;
    foreach ( $our_grades as $grade ) {
        if ( $assn_json->peerpoints > 0 ) {
            if ( $max_points === false ) $max_points = $grade['points'];
            $show = $grade['points'];
            if ( $show < $max_points ) $show = '';
            echo("<tr><td>".$show."</td>");
        }
        echo("<td>".htmlent_utf8($grade['note'])."</td>\n");

        if ( $assn_json->flag ) echo(
            '<td><form><input type="submit" name="showFlag" value="Flag"'.
            'onclick="$(\'#flag_grade_id\').val(\''.$grade['grade_id'].
             '\'); $(\'#flagform\').toggle(); return false;" class="btn btn-danger">'.
            '</form></td>');
        echo("</tr>\n");
    }
    echo("</table>\n");
    if ( $max_points !== false ) {
        echo("<p>Your overall score from your peers: $max_points </p>\n");
    }
}

if ( isset($assn_json->resubmit) && $assn_json->resubmit == 'always' && $dueDate->dayspastdue <= 0 ) {
    echo('<p><form method = "post">
        <input type="submit" name="deleteSubmit" value="Delete Your Submission" class="btn btn-danger"
            onclick="return confirm(\'Are you sure you want to delete your submission?\');">
        </form></p>
    ');
}

$OUTPUT->exitButton();
?>
<form method="post" id="flagform" style="display:none">
<p>&nbsp;</p>
<p>Please be considerate when flagging an item.  It does not mean
that something is inappropriate - it simply brings the item to the
attention of the instructor.</p>
<input type="hidden" value="<?php echo($submit_id); ?>" name="submit_id">
<input type="hidden" value="<?php echo($USER->id); ?>" name="user_id">
<input type="hidden" value="" id="flag_grade_id" name="grade_id">
<textarea rows="5" cols="60" name="note"></textarea><br/>
<input type="submit" name="doFlag"
    onclick="return confirm('Are you sure you want to bring this peer-grade entry to the attention of the instructor?');"
    value="Submit To Instructor"  class="btn btn-primary">
<input type="submit" name="doCancel" onclick="$('#flagform').toggle(); return false;" value="Cancel Flag" class="btn btn-default">
</form>
<p>
<?php if ( $assn_json->totalpoints > 0 ) { ?>
<div id="gradeinfo">Calculating grade....</div>
</p>
<script type="text/javascript">
function gradeLoad() {
    window.console && console.log('Loading and updating your grade...');
    $.getJSON('<?php echo(addSession('update_grade.php')); ?>', function(data) {
        window.console && console.log(data);
        if ( data.grade ) {
            $("#gradeinfo").html('Your current grade is '+data.grade*100.0+'%');
        } else {
            $("#gradeinfo").html('You do not have a grade.');
            window.console && console.log('Take a screen shot of the console output and send to support...');
        }
    });
}
</script>
<?php
}
$OUTPUT->footerStart();
$json_url = 'http://localhost:8888/py4e/mod/peer-grade/load_html.php?html_id=1&assn_id=1&user_id=6';
?>
<?php if ( $assn_json->totalpoints > 0 ) { ?>
<script type="text/javascript">
$(document).ready(function() {
    gradeLoad();
} );
</script>

<?php } ?>

<script src="<?= U::get_rest_parent() ?>/static/prism.js" type="text/javascript"></script>

<?php
load_htmls();
$OUTPUT->footerEnd();

