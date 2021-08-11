<?php

namespace Prim69\Replay;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\network\mcpe\protocol\types\SkinImage;
use pocketmine\network\mcpe\protocol\types\SkinData;
use pocketmine\network\mcpe\protocol\types\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\PersonaSkinPiece;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\block\Block;
use pocketmine\utils\UUID;
use function get_class;
use function in_array;
use function microtime;
use function round;

class Main extends PluginBase implements Listener
{

	/** @var array */
	public $recording = [];

	/** @var array */
	public $saved = [];

	/** @var array */
	public $positions = [];

	/** @var array */
	public $skinData = [];

	public const IGNORE_SERVERBOUND = [
		LevelChunkPacket::class,
		RequestChunkRadiusPacket::class,
		ResourcePackChunkRequestPacket::class,
		ResourcePackChunkDataPacket::class,
		TextPacket::class
	];

	public function onEnable()
	{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getServer()->getCommandMap()->register($this->getName(), new ReplayCommand($this));
	}

	public function showRecording(Player $player, Player $target)
	{
		$this->getScheduler()->scheduleRepeatingTask(new ReplayTask($player, $target, $this), 1);
	}

	public function isRecording(string $name): bool
	{
		return isset($this->recording[$name]);
	}

	public function onBlockPlace(BlockPlaceEvent $event) {
		$player = $event->getPlayer();
		if (!$this->isRecording($player->getName())) return;
		$this->recording[$player->getName()]["blocks"][(string) round(microtime(true), 2)] = $event->getBlock();
		$this->recording[$player->getName()]["preBlocks"][] = Block::get(0, 0, $event->getBlock());
	}

	public function onBlockBreak(BlockBreakEvent $event) {
		$player = $event->getPlayer();
		if (!$this->isRecording($player->getName())) return;
		$this->recording[$player->getName()]["blocks"][(string) round(microtime(true), 2)] = Block::get(0, 0, $event->getBlock());
		$this->recording[$player->getName()]["preBlocks"][] = $event->getBlock();
	}

	public function onReceive(DataPacketReceiveEvent $event)
	{
		$pk = $event->getPacket();
		$player = $event->getPlayer();
		if ($this->isRecording($player->getName())) {
			if (!in_array(get_class($pk), self::IGNORE_SERVERBOUND)) {
				$this->recording[$player->getName()]["packets"][(string) round(microtime(true), 2)] = $pk;
			}
		}
		if ($pk instanceof LoginPacket) {
			$packet = $pk;
			$animations = [];
			foreach ($packet->clientData["AnimatedImageData"] as $animation) {
				$animations[] = new SkinAnimation(
					new SkinImage(
						$animation["ImageHeight"],
						$animation["ImageWidth"],
						base64_decode($animation["Image"], true)
					),
					$animation["Type"],
					$animation["Frames"],
					$animation["AnimationExpression"]
				);
			}

			$personaPieces = [];
			foreach ($packet->clientData["PersonaPieces"] as $piece) {
				$personaPieces[] = new PersonaSkinPiece(
					$piece["PieceId"],
					$piece["PieceType"],
					$piece["PackId"],
					$piece["IsDefault"],
					$piece["ProductId"]
				);
			}

			$pieceTintColors = [];
			foreach ($packet->clientData["PieceTintColors"] as $tintColor) {
				$pieceTintColors[] = new PersonaPieceTintColor($tintColor["PieceType"], $tintColor["Colors"]);
			}
			$skinData = new SkinData(
				$packet->clientData["SkinId"],
				$packet->clientData["PlayFabId"],
				base64_decode($packet->clientData["SkinResourcePatch"] ?? "", true),
				new SkinImage(
					$packet->clientData["SkinImageHeight"],
					$packet->clientData["SkinImageWidth"],
					base64_decode($packet->clientData["SkinData"], true)
				),
				$animations,
				new SkinImage(
					$packet->clientData["CapeImageHeight"],
					$packet->clientData["CapeImageWidth"],
					base64_decode($packet->clientData["CapeData"] ?? "", true)
				),
				base64_decode($packet->clientData["SkinGeometryData"] ?? "", true),
				base64_decode($packet->clientData["SkinAnimationData"] ?? "", true),
				$packet->clientData["PremiumSkin"] ?? false,
				$packet->clientData["PersonaSkin"] ?? false,
				$packet->clientData["CapeOnClassicSkin"] ?? false,
				$packet->clientData["CapeId"] ?? "",
				null,
				$packet->clientData["ArmSize"] ?? SkinData::ARM_SIZE_WIDE,
				$packet->clientData["SkinColor"] ?? "",
				$personaPieces,
				$pieceTintColors,
				true
			);
			if(!!$packet->xuid) $this->skinData[$packet->xuid] = $skinData;
		}
	}

	public function onQuit(PlayerQuitEvent $event)
	{
		$name = $event->getPlayer()->getName();
		if ($this->isRecording($name)) {
			$this->saved[$name] = $this->recording[$name];
			unset($this->recording[$name]);
		}
		if (isset($this->skinData[$event->getPlayer()->getXuid()])) {
			unset($this->skinData[$event->getPlayer()->getXuid()]);
		}
	}

	/*public function throwFakeProjectile(Player $player, ?ProjectileItem $item, Location $l){
		$pk = new AddActorPacket();
		$pk->position = $l;
		$pk->pitch = $l->pitch;
		//$pk->type = "minecraft:splash_potion";
		$pk->yaw = $l->yaw;
		$pk->entityRuntimeId = $pk->entityUniqueId = Entity::$entityCount++;

		$flags = 0;
		$flags |= 1 << Entity::DATA_FLAG_LINGER;
		$pk->metadata = [
			Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, $flags],
			Entity::DATA_OWNER_EID => [Entity::DATA_TYPE_LONG, -1],
			Entity::DATA_NAMETAG => [Entity::DATA_TYPE_STRING, ""],
			Entity::DATA_POTION_COLOR => [Entity::DATA_TYPE_INT, 23]
		];

		$player->dataPacket($pk);
	}*/
}
