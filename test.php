<?php
    $overdueMsg = array(
                      "blocks" => array()
                  );

    $addon = array( //BEGIN first section
                 "type" => "section",
                 "text" => array(
                     "type" => "mrkdwn",
                     "text" => "*1st card*",
                 ),
             ); //END first section
    array_push($overdueMsg["blocks"], $addon);

    echo var_dump(json_encode($overdueMsg));
