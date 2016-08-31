<?php
namespace Inviqa\Seeder;

use Composer\Composer;
use Composer\Script\Event;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Inviqa\Seeder\ScriptHandler;

class SeederPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {

    }

    public static function getSubscribedEvents()
    {
        return [
            'post-create-project-cmd' => [
                'postCreateProject',
            ]
        ];
    }

    public static function postCreateProject(Event $event)
    {
        (new ScriptHandler($event->getIO()))->run();
    }
}
