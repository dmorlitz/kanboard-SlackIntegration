<?php

namespace Kanboard\Plugin\SlackIntegration\Controller;

use Kanboard\Controller\BaseController;

/**
 * SlackIntegration Controller
 *
 * @package  SlackIntegration
 * @author   David Morlitz
 */
class SlackIntegrationController extends BaseController
{
    /**
     * Handle SlackIntegration - initial slash commands start with receiver()
     *
     * @access public
     */
    public function receiver()
    {
        //Requests with an invalid webhook token will be rejected with an "Access denied" message
        $this->checkWebhookToken();

//Debug code
//$req_dump = print_r($_REQUEST, true);
//$fp = file_put_contents('/tmp/SlackIntegration.log', $req_dump);
//$req_dump = print_r($_POST, true);
//$fp = file_put_contents('/tmp/SlackIntegration.log', $req_dump, FILE_APPEND);

        // Determine if we need to send HTTP status codes or descriptive text error messages
        $send_http_error_codes = true;

        // Get the list of valid subject fields
        $incomingtask_subject = $this->configModel->get('incomingtask_subject');

        //Try to find the first text field provided that has a value
        $subject_fields = explode(",", preg_replace('/\s+/','', $incomingtask_subject));
        $found = false;
        $subject = "";
        foreach ($subject_fields as $subject_sent) {
            if (isset($_REQUEST[$subject_sent])) {
                if ( ($_REQUEST[$subject_sent] != "") && ($found == false) ) {
                    $subject = $_REQUEST[$subject_sent];
                    $found = true;
                }
            }
        }

        // Make sure we found a valid subject field
        if ($found == false) {
           if ($send_http_error_codes) { http_response_code(500); }
           echo "ERROR: You asked to look in the fields named " . implode(",", $subject_fields) . " but none of these were found in the data sent";
           exit(1);
        }

        // Get the command to execute
        $arr = explode(' ',trim($subject));
        $cmd = $arr[0];

        switch ($cmd) {
            case 'add':
                if ($send_http_error_codes) { http_response_code(200); }
                $this->addTask(substr($subject,4));
                break;
            case 'help':
                header('Content-type: application/json');
                if ($send_http_error_codes) { http_response_code(200); }
                $this->help($subject);
                break;
            case 'overdue':
                header('Content-type: application/json');
                if ($send_http_error_codes) { http_response_code(200); }
                $this->showOverdue($subject);
                break;
            default:
                if ($send_http_error_codes) { http_response_code(200); }
                echo 'Unknown command sent ' . $subject;
                break;
        } //End command switch
    }

    private function buildSlackBlockForCard($cardNumber, $replace = false) {
        $slackMsg = array(
                          "blocks" => array()
                      );

        if ($replace == true) {
            array_push($slackMsg["blocks"], array("delete_original"=>"true"));
        }

        $task = $this->taskFinderModel->getById($cardNumber);
        $msg = "*" . $task["title"] . "* (" . $task["id"] . ") _Due: " . date("m/d/Y", intval($task["date_due"])) . '_';

        $addon = array( //BEGIN first section
                     "type" => "section",
                     "text" => array(
                         "type" => "mrkdwn",
                         "text" => $msg,
                     ),
                 ); //END first section
        array_push($slackMsg["blocks"], $addon);

        $addon = array( //BEGIN new button array
            "type" => "actions", //Define as an action
            "elements" => array( //BEGIN elements definition
                array( //BEGIN button 1
                    "type" => "button",
                    "text" => array(
                        "type" => "plain_text",
                        "text" => "Close card", //Text to appear on Button 1
                    ),
                    "value" => strval($cardNumber), //Value to be sent with Button 1
                ), //END button 1 definition
                array( //BEGIN button 2
                    "type" => "button",
                    "text" => array(
                        "type" => "plain_text",
                        "text" => "Push to Monday", //Text to appear on Button 2
                    ),
                    "value" => strval($cardNumber), //Value to be sent with Button 2
                ), //END button 2 definition
                array( //BEGIN button 3
                    "type" => "button",
                    "text" => array(
                        "type" => "plain_text",
                        "text" => "Push 7 days", //Text to appear on Button 3
                    ),
                    "value" => strval($cardNumber), //Value to be sent with Button 3
                ), //END button 3 definition
                array( //BEGIN button 4
                    "type" => "button",
                    "text" => array(
                        "type" => "plain_text",
                        "text" => "Push 30 days", //Text to appear on Button 4
                    ),
                    "value" => strval($cardNumber), //Value to be sent with Button 4
                ), //END button 4 definition
                array( //BEGIN button 5
                    "type" => "button",
                    "text" => array(
                        "type" => "plain_text",
                        "text" => "Open card", //Text to appear on Button 5
                    ),
                    "value" => strval($cardNumber), //Value to be sent with Button 5
                ), //END button 5 definition
            ) //END elements definition
        ); //END second section
        array_push($slackMsg["blocks"], $addon);
        return($slackMsg);
    } // END function buildSlackBlockForCard

    private function sendSlackBlockInteractive($block, $response_url) {
        $response = "";

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $response_url,
            CURLOPT_POST => 1,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS => json_encode($block)
        ));
        $resp = curl_exec($curl);
        curl_close($curl);
$fp = file_put_contents('/tmp/SlackIntegration.log', $resp, FILE_APPEND);
$fp = file_put_contents('/tmp/SlackIntegration.log', json_encode($block), FILE_APPEND);
    } //END function sendSlackBlockInteractive

    private function sendSlackBlockSeparate($block) {
        $curl = curl_init();

        $curl_url = 'https://slack.com/api/chat.postEphemeral' .
            '?token=' . $this->configModel->get('slackintegration_token') .
            '&channel=' . $_POST['channel_id'] .
            '&user=' . $_POST['user_id'] .
            '&text=Something%20went%20wrong%20sending%20the%20block%20to%20Slack' .
            '&blocks=' . curl_escape($curl, json_encode($block['blocks'])) .
            '&pretty=1';

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $curl_url);

        $resp = curl_exec($curl);
        curl_close($curl);
    } // END function sendSlackBlockSeparate

    public function interactive()
    { //BEGIN function interactive
        //Requests with an invalid webhook token will be rejected with an "Access denied" message
        $this->checkWebhookToken();

//Debug code
$req_dump = print_r($_REQUEST, true);
$fp = file_put_contents('/tmp/SlackIntegration.log', $req_dump);
$req_dump = print_r($_POST, true);
$fp = file_put_contents('/tmp/SlackIntegration.log', $req_dump, FILE_APPEND);

        $slackUpdate = json_decode($_POST['payload'],true);
        $cardNumber = $slackUpdate['actions'][0]['value'];
        $cardAction = $slackUpdate['actions'][0]['text']['text'];

        //$fp = file_put_contents('/tmp/SlackIntegration.log', "DEBUG", FILE_APPEND);
        //$fp = file_put_contents('/tmp/SlackIntegration.log', print_r($slackUpdate, true), FILE_APPEND);
        //$fp = file_put_contents('/tmp/SlackIntegration.log', "URL = " . $slackUpdate['response_url'], FILE_APPEND);
        $fp = file_put_contents('/tmp/SlackIntegration.log', "Action = " . $slackUpdate['actions'][0]['text']['text'], FILE_APPEND);
        $fp = file_put_contents('/tmp/SlackIntegration.log', "Card = " . $slackUpdate['actions'][0]['value'], FILE_APPEND);

        // Find the task to be updated
        $task = $this->taskFinderModel->getById($cardNumber);

        // Compute the new date
        switch ($cardAction) {
            case 'Push 7 days':
                $adjust = "+7 days";
                break;
            case 'Push 30 days':
                $adjust = "+30 days";
                break;
            case 'Push to Monday':
                $adjust = "Monday";
                break;
        } //END switch statement

        // Store the date
        $values = array(
            'id' => $cardNumber,
            'date_due' => date('Y-m-d H:i', strtotime($adjust)),
        );

        // Commit the date to the card
        $this->taskModificationModel->update($values, false);

        header('Content-type: application/json');
        http_response_code(200);
        $block = $this->buildSlackBlockForCard(1052);
//        echo json_encode($block);
        $this->sendSlackBlockInteractive($block, $slackUpdate['response_url']); //This replies to an interactive message
    } //END function interactive

    public function showOverdue($subject)
    { // BEGIN function showOverdue

        $block = $this->buildSlackBlockForCard(1052);
        $this->sendSlackBlockSeparate($block); // Send a card as a reponse to a given request

        http_response_code(200);
    } // END function showOverdue

    public function help($subject)
    { // BEGIN function help
        $helpMsg = array(
                       "blocks" => array( //BEGIN blocks array
                           array( //BEGIN first section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "*/kanboard help* = Display this message",
                               ),
                           ), //END first section
                           array( //BEGIN second section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "*/kanboard add <task name>* = Add a new task with the name <task name>",
                               ),
                           ), //END second section
                           array( //BEGIN third section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "*/kanboard overdue* = Display all overdue tasks",
                               ),
                           ), //END third section
                       ), //END of blocks
                   ); //END of array
        echo json_encode($helpMsg);
    } // END function help

    public function addTask($subject)
    {
        $incomingtask_subject = $this->configModel->get('slackintegration_subject');
        $incomingtask_project_id  = $this->configModel->get('slackintegration_project_id');
        $incomingtask_column_id   = $this->configModel->get('slackintegration_column_id');
        $incomingtask_swimlane_id = $this->configModel->get('slackintegration_swimlane_id');

        //if ( (isset($_REQUEST['response_url'])) && (strpos($_REQUEST['response_url'], "slack.com") !== false) ) { $send_http_error_codes = false; }

        if ($this->configModel->get('incomingtask_subject') == "") {
           if ($send_http_error_codes) { http_response_code(500); }
           echo "ERROR: Subject field is not defined - please check your Kanboard configuration";
           exit(1);
        }

        if (intval($incomingtask_project_id) == 0) {
           if ($send_http_error_codes) { http_response_code(500); }
           echo "ERROR: Project to insert task into is not defined - please check your Kanboard configuration";
           exit(1);
        }

        if (!in_array($incomingtask_project_id, $this->projectModel->getAllIds())) {
           if ($send_http_error_codes) { http_response_code(500); }
           echo("ERROR: Project " . $incomingtask_project_id . " does not appear to exist - task insertion will FAIL");
           exit(1);
        }

        if (intval($incomingtask_column_id) == 0) {
           if ($send_http_error_codes) { http_response_code(500); }
           echo "ERROR: Column to insert task into is not defined - please check your Kanboard configuration";
           exit(1);
        }

        if ($incomingtask_project_id != $this->columnModel->getProjectId($incomingtask_column_id)) {
           if ($send_http_error_codes) { http_response_code(500); }
           echo("ERROR: Column " . $incomingtask_column_id . " is not in project " . $incomingtask_project_id . " - task insertion will FAIL");
           exit(1);
        }

        if (intval($incomingtask_swimlane_id) == 0) {
           if ($send_http_error_codes) { http_response_code(500); }
           echo "ERROR: Swimlane to insert task into is not defined - please check your Kanboard configuration";
           exit(1);
        }

        if (!array_key_exists($incomingtask_swimlane_id, $this->swimlaneModel->getList($incomingtask_project_id))) {
           if ($send_http_error_codes) { http_response_code(500); }
           echo("ERROR: Swimlane " . $incomingtask_swimlane_id . " does not appear to exist in project " . $incomingtask_project_id . " - task insertion will FAIL");
           exit(1);
        }

        if ($subject == "") {
           if ($send_http_error_codes) { http_response_code(500); }
           echo("ERROR: No text was sent for the task name - ABORT");
           exit(1);
        }

	$fullrequest = print_r($_REQUEST, true);

        if (isset($_REQUEST['body-plain'])) {
		$description = $_REQUEST['body-plain'] . "\n\n--------------\n\n" . $fullrequest;
	} else {
		$description = $fullrequest;
	}

        $result = $this->taskCreationModel->create(array(
                                                         'title' => $subject,
                                                         'project_id' => $incomingtask_project_id,
                                                         'column_id' => $incomingtask_column_id,
                                                         'swimlane_id' => $incomingtask_swimlane_id,
							 'description' => $description,
                                                        )
                                                  );

        if ($result > 0) {
           if ($send_http_error_codes) { http_response_code(200); }
           echo("Kanboard accepted a task titled '" . $subject . "' as task number " . $result);
        } else {
           if ($send_http_error_codes) { http_response_code(500); }
           echo("Something went wrong and Kanboard did not accept your task");
        }
    } //END public function add task
}
