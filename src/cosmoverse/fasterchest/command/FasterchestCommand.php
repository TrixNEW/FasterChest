<?php

namespace cosmoverse\fasterchest\command;

use cosmoverse\fasterchest\Loader;
use Generator;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use SOFe\AwaitGenerator\Await;

final class FasterchestCommand extends Command {

    public function __construct() {
        parent::__construct("fasterchest", "Fasterchest management command", "/fasterchest convert/revert <world>");
        $this->setPermission("manage.fasterchest.command");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): void {
        if (!$this->testPermissionSilent($sender)) {
            $sender->sendMessage("You can't do TS.");
            return;
        }

        if (count($args) < 2 || !in_array($args[0], ["convert", "revert"])) {
            $sender->sendMessage(TextFormat::YELLOW . "/$commandLabel convert <world>" . TextFormat::GRAY . " - Convert containers to fast storage");
            $sender->sendMessage(TextFormat::YELLOW . "/$commandLabel revert <world>" . TextFormat::GRAY . " - Revert containers to vanilla storage");
            return;
        }

        $action = $args[0];
        $worldName = $args[1];
        $wm = Server::getInstance()->getWorldManager();
        $world = $wm->getWorldByName($worldName) ?? ($wm->loadWorld($worldName) ? $wm->getWorldByName($worldName) : null);

        if (is_null($world)) {
            $sender->sendMessage(TextFormat::RED . "World '$worldName' could not be found or loaded.");
            return;
        }

        $actionText = $action === "convert" ? "Converting" : "Reverting";
        $sender->sendMessage(TextFormat::YELLOW . "$actionText containers in {$world->getFolderName()}...");

        Await::f2c(function() use ($world, $sender, $action): Generator {
            $plugin = Loader::getInstance();
            $result = $action === "convert" ? yield from $plugin->convertWorld($world) : yield from $plugin->revertWorld($world);

            if (!($sender instanceof Player) || $sender->isConnected()) {
                $pastTense = $action === "convert" ? "Converted" : "Reverted";
                $sender->sendMessage(TextFormat::GREEN . "$pastTense $result container(s) in {$world->getFolderName()}.");
            }
        });
    }
}