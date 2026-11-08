<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Agents\Data\AgentDefinition;
use Chatom\Operations\Pipeline\CoreStages;
use Chatom\Operations\Pipeline\Effects;
use Chatom\Operations\Pipeline\Phase;
use Chatom\Operations\Pipeline\StageDefinition;
use Chatom\Plugins\Contracts\Plugin;
use Chatom\Plugins\PluginManifest;
use Chatom\Plugins\PluginRegistrar;

final class HostPlugin implements Plugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('knowkit.qualification');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->agent(new AgentDefinition(
            actorType: 'bot',
            handler: InstalledHandler::class,
            trigger: Trigger::class,
            durable: true,
            handlerKey: 'knowkit.lesson',
        ));
        $registrar->stage(StageDefinition::make('knowkit.qualification.current-write')
            ->for('message.send')
            ->in(Phase::Reauthorize)
            ->before(CoreStages::REAUTHORIZE_SENDER)
            ->effects(Effects::None)
            ->handledBy(RevokeAtWrite::class));
    }
}
