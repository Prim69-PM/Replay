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
use pocketmine\block\Block;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\event\block\BlockEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LegacySkinAdapter;
use function array_key_first;
use function count;
use function property_exists;
use function is_null;

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

	/** @var DataPacket[] */
	public $list = [];
	/** @var Block[] */
	public $blocks = [];
	/** @var Block[] */
	public $setBlocks = [];

	/** @var Level|null  */
	public $level = null;

	public function __construct(Player $player, Player $target, Main $main)
	{
		$this->player = $player;
		$this->main = $main;
		$this->list = $main->saved[$target->getName()]["packets"];
		$this->blocks = $main->saved[$target->getName()]["blocks"];

		foreach ($main->saved[$target->getName()]["preBlocks"] as $block) {
			$blockPK = new UpdateBlockPacket();
			$blockPK->x = $block->x;
			$blockPK->y = $block->y;
			$blockPK->z = $block->z;
			$blockPK->blockRuntimeId = $block->getRuntimeId();
			$blockPK->flags = UpdateBlockPacket::FLAG_NETWORK;
			$player->sendDataPacket($blockPK);
		}

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
		$skinpk = new PlayerSkinPacket();
		$skinpk->uuid = $pk->uuid;
		$skinpk->skin = (new LegacySkinAdapter())->toSkinData($target->getSkin());
		$player->dataPacket($skinpk);
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
				foreach ($this->setBlocks as $block) {
					if (!$block instanceof Block) continue;
					if (is_null($block->getRuntimeId()) || is_null($block->getLevel()) || is_null($block->getPosition())) continue;
					$pk = new UpdateBlockPacket();
					$pk->x = $block->x;
					$pk->y = $block->y;
					$pk->z = $block->z;
					$pk->blockRuntimeId = $block->getLevel()->getBlockAt($block->x, $block->y, $block->z)->getRuntimeId();
					$pk->flags = UpdateBlockPacket::FLAG_NETWORK;
					$this->player->sendDataPacket($pk);
				}
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

		if (isset($this->blocks[$key])) {
			/** @var Block */
			$relayed = $this->blocks[$key];
			if ($relayed instanceof Block && $relayed instanceof Vector3) {
				if ($relayed->x !== null && $relayed->y !== null && $relayed->z !== null && $relayed->getRuntimeId() !== null) {
					$pk = new UpdateBlockPacket();
					$pk->x = $relayed->x;
					$pk->y = $relayed->y;
					$pk->z = $relayed->z;
					$pk->blockRuntimeId = $relayed->getRuntimeId();
					$pk->flags = UpdateBlockPacket::FLAG_NETWORK;
					$this->player->sendDataPacket($pk);
					$this->setBlocks[] = $relayed;
				}
			}
		}

		unset($this->blocks[$key]);
		unset($this->list[$key]);
	}
}
