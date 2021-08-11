<?php

namespace Prim69\Replay;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\types\SkinData;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\UUID;
use function array_key_first;
use function count;
use function property_exists;

class ReplayTask extends Task
{

	/** @var Main */
	public $main;

	/** @var Player */
	public $player;

	/** @var bool */
	public $started = false;

	/** @var int */
	public $eid;

	/** @var array */
	public $list = [];

	/** @var Level|null  */
	public $level = null;

	public function __construct(Player $player, Player $target, Main $main)
	{
		$this->player = $player;
		$this->main = $main;
		$this->list = $main->saved[$target->getName()];

		$this->eid = Entity::$entityCount++;

		$p = $main->positions[$target->getName()];
		$level = $main->getServer()->getLevelByName($p[5]);
		$this->level = $level ?? $player->getLevel();

		// Thanks, BoomYourBang :)
		$pk = new AddPlayerPacket();
		$pk->username = $target->getName();
		$pk->uuid = UUID::fromRandom();
		$pk->entityRuntimeId = $pk->entityUniqueId = $this->eid;
		$pk->position = new Position($p[2], $p[3], $p[4], $level);
		$pk->yaw = $p[0];
		$pk->pitch = $p[1];
		$pk->item = ItemStackWrapper::legacy(Item::get(0));
		$player->dataPacket($pk);
		if (isset($this->main->skinData[$target->getXuid()])) {
			$skinpk = new PlayerSkinPacket();
			$skinpk->uuid = $pk->uuid;
			$skinpk->skin = $this->main->skinData[$target->getXuid()];
			$player->dataPacket($skinpk);
		}
	}

	public function onRun(int $currentTick)
	{
		if (!$this->player->isOnline()) {
			$this->getHandler()->cancel();
			return;
		}
		if (count($this->list) <= 0) {
			if ($this->started) {
				$pk = new RemoveActorPacket();
				$pk->entityUniqueId = $this->eid;
				$this->player->dataPacket($pk);
			}
			$this->getHandler()->cancel();
			return;
		}
		if (!$this->started) $this->started = true;
		$key = array_key_first($this->list);

		/** @var DataPacket $relayed */
		$relayed = clone $this->list[$key];
		if ($a = property_exists($relayed, 'entityUniqueId')) $relayed->entityUniqueId = $this->eid;
		if ($b = property_exists($relayed, 'entityRuntimeId')) $relayed->entityRuntimeId = $this->eid;
		if ($a || $b) $this->player->dataPacket($relayed);

		unset($this->list[$key]);
	}
}
