<h3><img src="<?= $this->url->dir() ?>plugins/Mailgun/mailgun-icon.png"/>&nbsp;Slack Integration</h3>
<div class="panel">

    <?= $this->form->label(t('Slack user ID authorized to your Kanboard account'), 'slackintegration_user_id') ?>
    <?= $this->form->text('slackintegration_user_id', $values) ?>
    <p><?= t("NOTE: The format for this is <team id>.<user id> and is comma separated - to allow authentication from multiple Slack workspaces") ?>

    <div class="form-actions">
        <input type="submit" value="<?= t('Save') ?>" class="btn btn-blue">
    </div>

</div>

