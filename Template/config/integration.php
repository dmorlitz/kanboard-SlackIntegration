<h3><img src="<?= $this->url->dir() ?>plugins/Mailgun/mailgun-icon.png"/>&nbsp;Slack Integration</h3>
<div class="panel">

    <?= $this->form->label(t('Slack token'), 'slackintegration_token') ?>
    <?= $this->form->text('slackintegration_token', $values) ?>

    <?= $this->form->label(t('Slack Signing Secret'), 'slackintegration_slack_signing_secret') ?>
    <?= $this->form->text('slackintegration_slack_signing_secret', $values) ?>

    <?= $this->form->label(t('Protocol://Hostname:port to use for incoming task webhook (NO trailing slash)'), 'slackintegration_host_port') ?>
    <?= $this->form->text('slackintegration_host_port', $values) ?>

    <?= $this->form->label(t('Field to use for task subject (must exist in the incoming JSON request)'), 'slackintegration_subject') ?>
    <?= $this->form->text('slackintegration_subject', $values) ?>

    <p class="alert"><?= t("Warning: You need to click save every time you change a drop-down for the other ones to populate with valid date - sorry I am still working on this.") ?></p>

    <?= $this->form->label(t('Project ID for task creation'), 'slackintegration_project_id') ?>
    <?= $this->form->select('slackintegration_project_id', $this->app->projectModel->getList(),array('slackintegration_project_id'=>$values['slackintegration_project_id'])) ?>
    <!--<?= $this->form->text('slackintegration_project_id', $values) ?>-->
    <?php if (!in_array($values['slackintegration_project_id'], $this->app->projectModel->getAllIds())) { ?>
           <p class="alert-error"><?= t("ERROR: Project " . $values['slackintegration_project_id'] . " does not appear to exist - task insertion will FAIL") ?></p>
    <?php } ?>

    <?= $this->form->label(t('Column ID for task creation'), 'slackintegration_column_id') ?>
    <?= $this->form->select('slackintegration_column_id', $this->app->columnModel->getList($values['slackintegration_project_id'])) ?>
    <!--<?= $this->form->text('slackintegration_column_id', $values) ?>-->
    <?php if ($values['slackintegration_project_id'] != $this->app->columnModel->getProjectId($values['slackintegration_column_id'])) { ?>
           <p class="alert-error"><?= t("ERROR: Column " . $values['slackintegration_column_id'] . " is not in project " . $values['slackintegration_project_id'] . " - task insertion will FAIL") ?></p>
    <?php } ?>

    <?= $this->form->label(t('Swimlane ID for task creation'), 'slackintegration_swimlane_id') ?>
    <?= $this->form->select('slackintegration_swimlane_id', $this->app->swimlaneModel->getList($values['slackintegration_project_id'])) ?>
    <!--<?= $this->form->text('slackintegration_swimlane_id', $values) ?>-->
    <?php if (!array_key_exists($values['slackintegration_swimlane_id'], $this->app->swimlaneModel->getList($values['slackintegration_project_id']))) { ?>
           <p class="alert-error"><?= t("ERROR: Swimlane " . $values['slackintegration_swimlane_id'] . " does not appear to exist in project " . $values['slackintegration_project_id'] . " - task insertion will FAIL") ?></p>
    <?php } ?>

    <?= $this->form->label(t('URL to provide to remote service:'), 'slackintegration_column_id') ?>
    <div class="panel">
        <?php
            //if (strpos( $this->url->href('SlackIntegrationController', 'receiver', array('plugin' => 'SlackIntegration')) , "?") !== false)
            if (strpos( $this->url->href('SlackIntegrationController', 'receiver', array('plugin' => 'SlackIntegration')) , "?") !== false)
            {
               //There is already a ? in the URL - so append token with a &;
            ?>
               <?= $this->text->e($values['slackintegration_host_port'] . $this->url->href('SlackIntegrationController', 'receiver', array('plugin' => 'SlackIntegration')) . '&token=' . $values['webhook_token'] ) ?>
            <?php
            } else {
            ?>
               <?= $this->text->e($values['slackintegration_host_port'] . $this->url->href('SlackIntegrationController', 'receiver', array('plugin' => 'SlackIntegration')) . '?token=' . $values['webhook_token'] ) ?>
            <?php
            }
        ?>
    </div>

    <?= $this->form->label(t('Debug info:'), 'slackintegration_column_id') ?>
    <div class="panel">
        (Project ID=<?= $values['slackintegration_project_id']?>) (Column ID=<?= $values['slackintegration_column_id']?>) (Swimlane ID=<?= $values['slackintegration_swimlane_id']?>)
    </div>

    <div class="form-actions">
        <input type="submit" value="<?= t('Save') ?>" class="btn btn-blue">
    </div>

    <p class="alert"><?= t("NOTE: This plugin uses the Webhook token defined for Kanboard - if you reset that token this one will also change.") ?></p>
</div>

