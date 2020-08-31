<?php

namespace Kanboard\Plugin\SlackIntegration;

use Kanboard\Core\Security\Role;
use Kanboard\Core\Translator;
use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Authorization;
use Kanboard\Core\Security\AccessMap;
use Kanboard\Helper\Url;

/**
 * SlackIntegration Plugin
 *
 * @package  SlackIntegration
 * @author   David Morlitz
 */
class Plugin extends Base
{
    public function initialize()
    {
        $this->template->hook->attach('template:config:integrations', 'SlackIntegration:config/integration');

        $this->route->addRoute('/slackintegration/handler', 'SlackIntegration', 'receiver', 'SlackIntegration');
        $this->applicationAccessMap->add('SlackIntegrationController', 'receiver', Role::APP_PUBLIC);

        $this->route->addRoute('/slackintegration/interactive', 'SlackIntegration', 'interactive', 'SlackIntegration');
        $this->applicationAccessMap->add('SlackIntegrationController', 'interactive', Role::APP_PUBLIC);
    }

    public function getPluginDescription()
    {
        return 'SlackIntegration Web Integration';
    }

    public function getPluginAuthor()
    {
        return 'David Morlitz';
    }

    public function getPluginVersion()
    {
        return '0.0.1';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/dmorlitz/kanboard-SlackIntegration.git';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.5';
    }
}
