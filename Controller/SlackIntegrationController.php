<?php

namespace Kanboard\Plugin\SlackIntegration\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Model\CommentModel;
use Kanboard\Model\TaskModel;
use Kanbaord\Model\ProjectModel;
use Kanbaord\Model\ColumnModel;
use Kanbaord\Model\SwimlaneModel;

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
//$fp = file_put_contents('/tmp/SlackIntegration.log', $req_dump, FILE_APPEND);
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
                $this->help();
                break;
            case 'overdue':
                $this->respondOK(); //This operation could take a while and responds separately - acknowledge request to Slack
                $this->showOverdue();
                break;
            case 'search':
                $this->showSearchedTasks(substr($subject,7));
                break;
            case 'display':
                switch ($arr[1]) {
                    case 'task':
                        $block = $this->buildSlackBlockForCard($arr[2]);
                        $this->sendSlackBlockSeparate($block, $responseURL);
                        break;
                    case 'board':
                        echo "Board display";
                        break;
                    default:
                        echo "Unknown display command sent - please check /kanboard help";
                        break;
                } //Switch
                break;
            default:
                if ($send_http_error_codes) { http_response_code(200); }
                echo 'Unknown command sent ' . $subject;
                break;
        } //End command switch
    }

    private function openCommentTextDialog($cardNumber, $responseURL, $triggerID) {

        $task = $this->taskFinderModel->getByID($cardNumber);

        header('Content-type: application/json');
        http_response_code(200);

        $view = array(
                   "title" => array("type"=>"plain_text", "text"=>"Adding Kanboard comment"),
                   "submit" => array("type"=>"plain_text", "text"=>"Add comment"),
                   "type"=>"modal",
                   "blocks"=> array(
                                array(
                                  "type"=>"input",
                                  "element"=>array(
                                                  "type"=>"plain_text_input",
                                                  "action_id"=>"comment_text",
                                                  "placeholder"=>array("type"=>"plain_text","text"=>"Add comment here"),
                                                  ),
                                  "label"=>array("type"=>"plain_text","text"=>"Comment to add"),
                                  "hint"=>array("type"=>"plain_text","text"=>$task['id'] . ": Adding comment to the task named '" . $task["title"] . "'"),
                                ),
                                array(
                                  "type"=>"input",
                                  "element"=>array(
                                                  "type"=>"conversations_select",
                                                  "action_id"=>"comment_text",
                                                  "placeholder"=>array("type"=>"plain_text","text"=>"Please do not change"),
                                                  "response_url_enabled"=>true,
                                                  "default_to_current_conversation"=>true,
                                                  ),
                                  "label"=>array("type"=>"plain_text","text"=>"Update goes to"),
                                  "hint"=>array("type"=>"plain_text","text"=>"Please do not change this value - all responses will go only to you"),
                                ),
                              ),
                );

        $slackMsg = array(
            "token" => $this->configModel->get('slackintegration_token'),
            "trigger_id" => $triggerID,
            "view" => json_encode($view),
        ); //END of slackMsg array

        $curl = curl_init();

        $curlHeaders = array();
        $curlHeaders[] = 'Content-Type: application/json; charset=utf-8';
        $curlHeaders[] = 'Authorization: Bearer ' . $this->configModel->get('slackintegration_token');

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://slack.com/api/views.open",
            CURLOPT_POST => 1,
            CURLOPT_BINARYTRANSFER => TRUE,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS => json_encode($slackMsg),
        ));
        $resp = curl_exec($curl);
//$fp = file_put_contents('/tmp/SlackIntegration.log', json_encode($slackMsg), FILE_APPEND);
//$fp = file_put_contents('/tmp/SlackIntegration.log', print_r($resp,true), FILE_APPEND);
//var_dump($curlHeaders);
//var_dump($slackMsg);
//var_dump($resp);
        curl_close($curl);

    } // END function getCommentTextModal


    private function buildSlackBlockForCard($cardNumber) {
        $task = $this->taskFinderModel->getByID($cardNumber);
        return $this->buildSlackBlockForCardArray($task, $cardNumber);
    } // END function buildSlackBlockForCardArray

    private function buildSlackBlockForCardArray($task, $cardNumber) {
        $slackMsg = array(
                          "blocks" => array()
                      );

        $cardURL = $this->configModel->get("application_url") . "?controller=TaskViewController&action=show&" .
                   "task_id=" . $cardNumber . "&project_id=" . $task["project_id"];

        $display_comment = "";
        $comments = $this->commentModel->getAll($task['id'], 'ASC');
        foreach ($comments as $comment) {
            $display_comment = date("m/d/Y", $comment['date_creation']) . ': ' . $comment['comment'];
        }

        // Original combined single markdown
        $msg = "*" . $task["title"] . "* (" . $task["id"] . ") \n" .
                     "_Due: " . date("m/d/Y", intval($task["date_due"])) . "_\n" .
                     "Last comment: " . $display_comment . "\n<" . $cardURL . "|Open in browser>";


        $msg = "*" . $task["title"] . "* (" . $task["id"] . ")";

        $addon = array( //BEGIN first section
                     "type" => "section",
                     "text" => array(
                         "type" => "mrkdwn",
                         "text" => $msg,
                     ),
                 ); //END first section
        array_push($slackMsg["blocks"], $addon);

        // Text fields are displayed left to right, not up to down, so you have to alternate columns
        $project = $this->projectModel->getById($task["project_id"]);
        $column = $this->columnModel->getById($task["column_id"]);
        $swimlane = $this->swimlaneModel->getById($task["swimlane_id"]);
        $displayFields = array(
                             array("type"=>"mrkdwn","text"=>"_Due: `" . date("m/d/Y", intval($task["date_due"])) . "`_"),
                             array("type"=>"mrkdwn","text"=>"Project: `" . $project["name"] ."`"),
                             array("type"=>"mrkdwn","text"=>"<" . $cardURL . "|Open in browser>"),
                             array("type"=>"mrkdwn","text"=>"Column: `" . $column["title"] ."`"),
                             array("type"=>"mrkdwn","text"=>"Last comment: " . $display_comment),
                             array("type"=>"mrkdwn","text"=>"Swimlane: `" . $swimlane["name"] . "`"),
                         );
        $cardDetails = array(
                             "type"=>"section",
                             "fields"=>$displayFields,
                            );
        array_push($slackMsg["blocks"], $cardDetails);

        $options = array(
                        array(
                              "text"=>array("type"=>"plain_text", "text"=>"Close card", "emoji"=>true),
                              "value"=>"Close card:" . strval($cardNumber),
                             ),
                        array(
                              "text"=>array("type"=>"plain_text", "text"=>"Push 7 days", "emoji"=>true),
                              "value"=>"Push 7 days:" . strval($cardNumber),
                             ),
                        array(
                              "text"=>array("type"=>"plain_text", "text"=>"Push 30 days", "emoji"=>true),
                              "value"=>"Push 30 days:" . strval($cardNumber),
                             ),
                        array(
                              "text"=>array("type"=>"plain_text", "text"=>"Push to Monday", "emoji"=>true),
                              "value"=>"Push to Monday:" . strval($cardNumber),
                             ),
                        array(
                              "text"=>array("type"=>"plain_text", "text"=>"Add comment", "emoji"=>true),
                              "value"=>"Add comment:" . strval($cardNumber),
                             ),
                        array(
                              "text"=>array("type"=>"plain_text", "text"=>"Refresh card", "emoji"=>true),
                              "value"=>"Refresh card:" . strval($cardNumber),
                             ),
                   );

        $addon = array( //BEGIN dropdown
                      "type" => "actions",
                      "elements" => array(array(
                          "type" => "static_select",
                          "placeholder" => array("type"=>"plain_text", "text"=>"Kanboard Actions", "emoji"=>true),
                          "options" => $options,
                      )),
                 ); //END first section
        array_push($slackMsg["blocks"], $addon);
//$fp = file_put_contents('/tmp/SlackIntegration.log', "=============ADDON=============\n", FILE_APPEND);
//$fp = file_put_contents('/tmp/SlackIntegration.log', json_encode($addon,JSON_PRETTY_PRINT), FILE_APPEND);
//$fp = file_put_contents('/tmp/SlackIntegration.log', "=============ADDON=============\n", FILE_APPEND);

/*
        //This code created 6 unique buttons in Slack
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
                        "text" => "Add comment", //Text to appear on Button 5
                    ),
                    "value" => strval($cardNumber), //Value to be sent with Button 5
                ), //END button 5 definition
                array( //BEGIN button 5
                    "type" => "button",
                    "text" => array(
                        "type" => "plain_text",
                        "text" => "Refresh card", //Text to appear on Button 5
                    ),
                    "value" => strval($cardNumber), //Value to be sent with Button 5
                ), //END button 5 definition
            ) //END elements definition
        ); //END second section
        array_push($slackMsg["blocks"], $addon);
        //END Creating 6 unique buttons in Slack
*/

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
//$fp = file_put_contents('/tmp/SlackIntegration.log', json_encode($block), FILE_APPEND);
//$fp = file_put_contents('/tmp/SlackIntegration.log', $resp, FILE_APPEND);
    } //END function sendSlackBlockInteractive

    private function sendSlackBlockSeparate($block) {
        $slackMsg = array(
            "token" => $this->configModel->get('slackintegration_token'),
            "channel" => $_POST['channel_id'],
            "user" => $_POST['user_id'],
            "text" => "Something%20went%20wrong%20sending%20the%20block%20to%20Slack",
            "pretty" => 1,
            "blocks" => $block["blocks"],
        ); //END of slackMsg array

        $curl = curl_init();

        $curlHeaders = array();
        $curlHeaders[] = 'Content-Type: application/json; charset=utf-8';
        $curlHeaders[] = 'Authorization: Bearer ' . $this->configModel->get('slackintegration_token');

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://slack.com/api/chat.postEphemeral",
            CURLOPT_POST => 1,
            CURLOPT_BINARYTRANSFER => TRUE,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS => json_encode($slackMsg),
        ));
        $resp = curl_exec($curl);
//var_dump($curlHeaders);
//var_dump($slackMsg);
//var_dump($resp);
        curl_close($curl);
//$fp = file_put_contents('/tmp/SlackIntegration.log', print_r($slackMsg,true), FILE_APPEND);
//$fp = file_put_contents('/tmp/SlackIntegration.log', print_r($resp,true), FILE_APPEND);
    } // END function sendSlackBlockSeparate

    public function pushCardFromSlack($cardNumber, $responseURL, $adjust) {
        // Store the date
        $values = array(
            'id' => $cardNumber,
            'date_due' => date('Y-m-d H:i', strtotime($adjust)),
        );

        // Commit the date to the card
        $this->taskModificationModel->update($values, false);

        header('Content-type: application/json');
        http_response_code(200);
        $block = array("delete_original"=>"true");
        $this->sendSlackBlockInteractive($block, $responseURL); //This replies to an interactive message
    } // END function pushCardFromSlack

    public function closeCardFromSlack($cardNumber, $responseURL) {
        // Close the card
        $this->taskStatusModel->close($cardNumber);

        header('Content-type: application/json');
        http_response_code(200);
        $block = array("delete_original"=>"true");
        $this->sendSlackBlockInteractive($block, $responseURL); //This replies to an interactive message
    } // END function pushCardFromSlack

    public function refreshCardToSlack($cardNumber, $responseURL) {
        // Store the date
        $values = array(
            'id' => $cardNumber,
            'date_due' => date('Y-m-d H:i', strtotime($adjust)),
        );

        // Commit the date to the card
        $this->taskModificationModel->update($values, false);

        header('Content-type: application/json');
        http_response_code(200);
        $block = $this->buildSlackBlockForCard($cardNumber);
        $this->sendSlackBlockInteractive($block, $responseURL); //This replies to an interactive message
    } // END function pushCardFromSlack

    public function interactive()
    { //BEGIN function interactive
        //Requests with an invalid webhook token will be rejected with an "Access denied" message
        $this->checkWebhookToken();

//Debug code
$fp = file_put_contents('/tmp/SlackIntegration.log', "Starting from Slack");

//$req_dump = print_r($_REQUEST, true);
//$fp = file_put_contents('/tmp/SlackIntegration.log', $req_dump, FILE_APPEND);
//$req_dump = print_r($_POST, true);
//$fp = file_put_contents('/tmp/SlackIntegration.log', $req_dump, FILE_APPEND);

        // Decode the incoming JSON request
        $slackUpdate = json_decode($_POST['payload'],true);

        if ($slackUpdate['type'] == "block_actions") {
            $this->slackActionSelected($slackUpdate);
        }

        if ($slackUpdate['type'] == "view_submission") {
            $this->slackCommentSent($slackUpdate);
        }

    } //END function interactive

    private function slackCommentSent($slackUpdate) {
        //$fp = file_put_contents('/tmp/SlackIntegration.log', print_r($slackUpdate, true), FILE_APPEND);

        $cardHint = explode(":", $slackUpdate["view"]["blocks"][0]["hint"]["text"]);
        $cardNum = $cardHint[0];

        $responseURL = $slackUpdate["response_urls"][0]["response_url"];

        $commandArray = $slackUpdate["view"]["state"]["values"]; //Get the values portion of the JSON
        $firstKey = array_key_first($commandArray); //This key always seems to change in the JSON
        $newComment = $commandArray[$firstKey]["comment_text"]["value"];

        //$fp = file_put_contents('/tmp/SlackIntegration.log', print_r($commandArray,true) . "\n", FILE_APPEND);
        //$fp = file_put_contents('/tmp/SlackIntegration.log', $cardNum . "\n", FILE_APPEND);
        //$fp = file_put_contents('/tmp/SlackIntegration.log', $firstKey . "\n", FILE_APPEND);
        //$fp = file_put_contents('/tmp/SlackIntegration.log', $newComment . "\n", FILE_APPEND);

        $task = $this->taskFinderModel->getById($cardNum);
        $this->commentModel->create(array(
            'comment' => $newComment,
            'task_id' => intval($cardNum),
        ));

        $block = $this->buildSlackBlockForCard($cardNum);
        $this->sendSlackBlockInteractive($block, $responseURL); // Send a card as a reponse to a given request
    } //END function slackCommentSent

    private function slackActionSelected($slackUpdate) {

        // Get the necessary variables
        //$cardNumber = $slackUpdate['actions'][0]['value']; //This works when a button is pressed
        //$cardAction = $slackUpdate['actions'][0]['text']['text']; //This works when a button is pressed

        $slackRequest = explode(':',$slackUpdate['actions'][0]['selected_option']['value']);

        $cardAction = $slackRequest[0];
        $cardNumber = $slackRequest[1];

        $triggerID = $slackUpdate['trigger_id'];
        $responseURL = $slackUpdate['response_url'];

        // Debug statemetns
        $fp = file_put_contents('/tmp/SlackIntegration.log', "DEBUG", FILE_APPEND);
        $fp = file_put_contents('/tmp/SlackIntegration.log', print_r($slackUpdate, true), FILE_APPEND);
        $fp = file_put_contents('/tmp/SlackIntegration.log', "URL = " . $slackUpdate['response_url'], FILE_APPEND);
        $fp = file_put_contents('/tmp/SlackIntegration.log', "Action = " . $cardAction, FILE_APPEND);
        $fp = file_put_contents('/tmp/SlackIntegration.log', "Card = " . $cardNumber, FILE_APPEND);

        // Find the task to be updated
        $task = $this->taskFinderModel->getById($cardNumber);

        // Compute the new date
        switch ($cardAction) {
            case 'Push 7 days':
                $adjust = "+7 days";
                $this->pushCardFromSlack($cardNumber, $responseURL, $adjust);
                break;
            case 'Push 30 days':
                $adjust = "+30 days";
                $this->pushCardFromSlack($cardNumber, $responseURL, $adjust);
                break;
            case 'Push to Monday':
                $adjust = "Monday";
                $this->pushCardFromSlack($cardNumber, $responseURL, $adjust);
                break;
            case 'Add comment':
                $this->openCommentTextDialog($cardNumber, $responseURL, $triggerID);
                break;
            case 'Refresh card':
                $this->refreshCardToSlack($cardNumber, $responseURL);
                break;
            case 'Close card':
                $this->closeCardFromSlack($cardNumber, $responseURL);
                break;
        } //END switch statement
    } //END function slackActionSelected

    public function showOverdue()
    { // BEGIN function showOverdue
        // Start generating blocks for overdue Kanboard cards
        $overdue = $this->taskFinderModel->getOverdueTasks();
        $overdue = $this->taskFinderModel->getOverdueTasksByProject(17);

        // Call the renderer
        $this->buildSlackBlocksForCollection($overdue);
    } // END function showOverdue

    public function showSearchedTasks($searchString)
    { // BEGIN function showOverdue
        // Start generating blocks for overdue Kanboard cards
        $searchResults = $this->db
                ->table(TaskModel::TABLE)
                ->ilike(TaskModel::TABLE . '.title', '%' . $searchString . '%')
                ->eq(TaskModel::TABLE.'.is_active', TaskModel::STATUS_OPEN)
                ->findAllByColumn(TaskModel::TABLE . '.id');

        if ($searchResults == array() ) {
           echo "No tasks found for search string '" . $searchString . "'";
        } else {
            $this->respondOK(); //This operation could take a while and responds separately - acknowledge request to Slack
            foreach ($searchResults as $id => $taskNum) {
               $block = $this->buildSlackBlockForCard($taskNum);
               $this->sendSlackBlockSeparate($block); // Send a card as a reponse to a given request
            }
        }
    } // END function showOverdue

    protected function respondOK()
    {
        // check if fastcgi_finish_request is callable
        if (is_callable('fastcgi_finish_request')) {
            /*
             * This works in Nginx but the next approach not
             */
            session_write_close();
            fastcgi_finish_request();
            return;
        } //END function respondOK

        ignore_user_abort(true);

        ob_start();
        $serverProtocol = filter_input(INPUT_SERVER, 'SERVER_PROTOCOL', FILTER_SANITIZE_STRING);
        header($serverProtocol.' 200 OK');
        header('Content-Encoding: none');
        header('Content-Length: '.ob_get_length());
        header('Connection: close');

        ob_end_flush();
        ob_flush();
        flush();
    }

    public function buildSlackBlocksForCollection($taskCollection) {

        foreach ($taskCollection as $id => $task) {
            $block = $this->buildSlackBlockForCardArray($task, $task['id']);
            $this->sendSlackBlockSeparate($block); // Send a card as a reponse to a given request
        }

        // Diagnostic code to print a single block
        //$block = $this->buildSlackBlockForCard(1052);
        //$this->sendSlackBlockSeparate($block); // Send a card as a reponse to a given request
    } // END function buildSlackBlocksForCollection

    public function help()
    { // BEGIN function help
        header('Content-type: application/json');
        //if ($send_http_error_codes) { http_response_code(200); }
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
                                   "text" => "*/kanboard add _<task name>_* = Add a new task with the name _<task name>_",
                               ),
                           ), //END second section
                           array( //BEGIN second section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "~*/kanboard comment _<task number>_ _<task name>_* = Add a comment _task name_ to card _<task number>_~",
                               ),
                           ), //END second section
                           array( //BEGIN third section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "*/kanboard overdue* = Display all overdue tasks",
                               ),
                           ), //END third section
                           array( //BEGIN third section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "*/kanboard search _<string>_* = Search for tasks with name _<string>_",
                               ),
                           ), //END third section
                           array( //BEGIN third section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "*/kanboard display task _<task number>_* = Display task _<task number>_",
                               ),
                           ), //END third section
                           array( //BEGIN third section
                               "type" => "section",
                               "text" => array(
                                   "type" => "mrkdwn",
                                   "text" => "*/kanboard display board _<board name>_* = Display board named _<board name>_",
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
