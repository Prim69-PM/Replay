<?php

namespace Prim69\Replay;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginIdentifiableCommand;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat as TF;
use function array_keys;
use function count;
use function implode;
use function is_null;

class ReplayCommand extends Command implements PluginIdentifiableCommand {

	/** @var Main */
	public $main;

	public function __construct(Main $main){
		parent::__construct(
			"replay",
			TF::AQUA . "",
			TF::RED . "Usage: " . TF::GRAY . "/replay < | start <name> | save:stop <name> | watch <name> | delete <name> | list >"
		);
		$this->setPermission("replay.command");
		$this->main = $main;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!$sender instanceof Player){
			$sender->sendMessage(TF::DARK_RED . "Use this command in game!");
			return;
		}

		if(!$sender->hasPermission("replay.command")){
			$sender->sendMessage(TF::DARK_RED . "You do not have access to this command!");
			return;
		}

		if(isset($args[0]) && $args[0] === "list"){
			$recording = implode(", ", array_keys($this->main->recording));
			$saved = implode(", ", array_keys($this->main->saved));
			$sender->sendMessage(
				TF::GREEN . TF::BOLD . "==Lists==\n" . TF::RESET . TF::GREEN . "Players being recorded: " .
				TF::WHITE . $recording . "\n" . TF::GREEN . "Players saved: " . TF::WHITE . $saved
			);
			return;
		}

		if(count($args) < 2){
			$sender->sendMessage($this->usageMessage);
			return;
		}

		$player = $this->main->getServer()->getPlayer($args[1]);
		if(is_null($player)){
			$sender->sendMessage(TF::RED . "That player is not online!");
			return;
		}
		$name = $player->getName();
		switch($args[0]){
			case "start":
				if($this->main->isRecording($name)){
					$sender->sendMessage(TF::RED . "That player is already being recorded! Use /replay save to stop and save the recording, and /replay watch to watch the recording!");
					return;
				}
				if($this->isSaved($name)){
					$sender->sendMessage(TF::RED . "That player already has a saved recording! Use /replay delete before starting a new one!");
					return;
				}
				$this->main->recording[$name] = [];
				$this->main->positions[$name] = [$player->yaw, $player->pitch, $player->x, $player->y, $player->z, $player->getLevel()->getName()];
				$sender->sendMessage(TF::GREEN . "Successfully started recording " . TF::WHITE . "$name! " . TF::GREEN . "Use /replay save followed by /replay watch to view it!");
				break;
			case "save":
			case "stop":
				if(!$this->main->isRecording($name)){
					$sender->sendMessage(TF::RED . "That player is not being recorded! Use /replay start to start recording the players actions!");
					return;
				}
				$this->main->saved[$name] = $this->main->recording[$name];
				unset($this->main->recording[$name]);
				$sender->sendMessage(TF::GREEN . "Successfully saved the recording for " . TF::WHITE . "$name! " . TF::GREEN . "Use /replay watch to watch the recording!");
				break;
			case "watch":
				if($this->main->isRecording($name)){
					$sender->sendMessage(TF::RED . "That player is currently being recorded! Use /replay save to stop and save the recording!");
					return;
				}
				if(!$this->isSaved($name)){
					$sender->sendMessage(TF::RED . "That player does not have a saved recording! Use /replay start to start recording!");
					return;
				}
				$sender->sendMessage(TF::GREEN . "Displaying the recording for " . TF::WHITE . "$name!");
				$this->main->showRecording($sender, $player);
				break;
			case "delete":
				if(!$this->isSaved($name)){
					$sender->sendMessage(TF::RED . "That player does not have a saved recording! Start one with /replay start");
					return;
				}
				unset($this->main->saved[$name]);
				unset($this->main->positions[$name]);
				$sender->sendMessage(TF::GREEN . "Successfully deleted the recording for the player " . TF::WHITE . "$name!");
				break;
			default:
				$sender->sendMessage($this->usageMessage);
		}
	}

	public function isSaved(string $name) : bool {
		return isset($this->main->saved[$name]);
	}

	public function getPlugin() : Plugin {
		return $this->main;
	}

}
