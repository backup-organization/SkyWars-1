<?php

declare(strict_types=1);

namespace vixikhd\skywars\arena;

use pocketmine\block\Block;
use pocketmine\block\BlockIds;
use pocketmine\block\TNT;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\tile\Chest;
use pocketmine\tile\Tile;
use vixikhd\anticheat\SpectatingApi;
use vixikhd\skywars\API;
use vixikhd\skywars\chestrefill\ChestRefill;
use vixikhd\skywars\utils\ServerManager;
use vixikhd\skywars\arena\object\LuckyBlockPrize;
use vixikhd\skywars\event\PlayerArenaWinEvent;
use vixikhd\skywars\form\SimpleForm;
use vixikhd\skywars\kit\Kit;
use vixikhd\skywars\kit\KitManager;
use vixikhd\skywars\provider\lang\Lang;
use vixikhd\skywars\math\Vector3;
use vixikhd\skywars\SkyWars;
use vixikhd\skywars\utils\ScoreboardBuilder;
use vixikhd\skywars\utils\Sounds;

/**
 * Class Arena
 * @package skywars\arena
 */
class Arena implements Listener {

    public const MSG_MESSAGE = 0;
    public const MSG_TIP = 1;
    public const MSG_POPUP = 2;
    public const MSG_TITLE = 3;

    public const PHASE_LOBBY = 0;
    public const PHASE_GAME = 1;
    public const PHASE_RESTART = 2;

    public const FILLING_BLOCK = 0;
    public const FILLING_ITEM = 1;
    public const FILLING_FOOD = 2;
    public const FILLING_POTION = 3;
    public const FILLING_MATERIAL = 4;
    public const FILLING_ARMOUR = 5;

    // from config
    public const FILLING_CUSTOM = -1;

    /** @var SkyWars $plugin */
    public $plugin;

    /** @var ArenaScheduler $scheduler */
    public $scheduler;

    /** @var MapReset $mapReset */
    public $mapReset;

    /** @var int $phase */
    public $phase = 0;

    /** @var array $data */
    public $data = [];

    /** @var bool $setting */
    public $setup = false;

    /** @var Player[] $players */
    public $players = [];

    /** @var Player[] $spectators */
    public $spectators = [];

    /** @var Kit[] $kits */
    public $kits = [];

    /** @var array $kills */
    public $kills = [];

    /** @var Player[] $toRespawn */
    public $toRespawn = [];

    /** @var array $rewards */
    public $rewards = [];

    /** @var Level $level */
    public $level = null;

    /** @var array $defaultChestItems */
    public $defaultChestItems = [];

    /** @var LuckyBlockPrize $lbPrize */
    public $lbPrize;

    /** @var array $wantLeft */
    private $wantLeft = [];

    /**
     * Arena constructor.
     * @param SkyWars $plugin
     * @param array $arenaFileData
     */
    public function __construct(SkyWars $plugin, array $arenaFileData) {
        $this->plugin = $plugin;
        $this->data = $arenaFileData;
        $this->setup = !$this->enable(\false);

        $this->lbPrize = new LuckyBlockPrize($this);
        $this->plugin->getScheduler()->scheduleRepeatingTask($this->scheduler = new ArenaScheduler($this), 20);

        if($this->setup) {
            if(empty($this->data)) {
                $this->createBasicData();
                $this->plugin->getLogger()->error("Could not load arena {$this->data["level"]}");
            }
            else {
                $this->plugin->getLogger()->error("Could not load arena {$this->data["level"]}, complete setup.");
            }
        }
        else {
            $this->loadArena();
        }
    }

    /**
     * @param Player $player
     * @param bool $force
     */
    public function joinToArena(Player $player, bool $force = false) {
        if(!$this->data["enabled"]) {
            $player->sendMessage("§7§lSkyWars>§r§c Arena is under setup!");
            return;
        }

        if($this->phase !== 0) {
            $player->sendMessage("§7§lSkyWars>§r§c Arena is already in game!");
            return;
        }

        if(count($this->players) >= $this->data["slots"]) {
            $player->sendMessage(Lang::getMsg("arena.join.full"));
            return;
        }

        if($this->inGame($player)) {
            $player->sendMessage(Lang::getMsg("arena.join.player.ingame"));
            return;
        }

        if($this->scheduler->startTime <= 5) {
            $player->sendMessage("§c> Arena is starting...");
            return;
        }

        if(!API::handleJoin($player, $this, $force)) {
            return;
        }

        $this->scheduler->teleportPlayers = isset($this->data["lobby"]) || $this->data["lobby"] !== null;

        if(!$this->scheduler->teleportPlayers) {
            $selected = false;
            for($lS = 1; $lS <= $this->data["slots"]; $lS++) {
                if(!$selected) {
                    if(!isset($this->players[$index = "spawn-{$lS}"])) {
                        $player->teleport(Position::fromObject(Vector3::fromString($this->data["spawns"][$index])->add(0.5, 0, 0.5), $this->level));
                        $this->players[$index] = $player;
                        $selected = true;
                    }
                }
            }
        } else {
            if(!$this->plugin->getServer()->isLevelLoaded($this->data["lobby"][1])) {
                $this->plugin->getServer()->loadLevel($this->data["lobby"][1]);
            }
            $this->players[] = $player;
            $player->teleport(Position::fromObject(Vector3::fromString($this->data["lobby"][0]), $this->plugin->getServer()->getLevelByName($this->data["lobby"][1])));
        }

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $player->setGamemode($player::ADVENTURE);
        $player->setHealth(20);
        $player->setFood(20);


        $player->removeAllEffects();

        //$player->setImmobile(true);
        ScoreboardBuilder::removeBoard($player);

        $this->kills[$player->getName()] = 0;

        $inv = $player->getInventory();
        $inv->setItem(6, Item::get(Item::PAPER)->setCustomName("§r§eChange map\n§7[Use]"));
        if($this->plugin->kitManager instanceof KitManager) {
            $inv->setItem(7, Item::get(Item::FEATHER)->setCustomName("§r§eSelect kit\n§7[Use]"));
        }
        $inv->setItem(8, Item::get(Item::BED)->setCustomName("§r§eLeave game\n§7[Use]"));

        $this->broadcastMessage(Lang::getMsg("arena.join", [$player->getName(), count($this->players), $this->data["slots"]]));
    }

    /**
     * @param Player $player
     * @param string $quitMsg
     * @param bool $death
     * @param bool $spectator
     * @param bool $transfer
     */
    public function disconnectPlayer(Player $player, string $quitMsg = "", bool $death = false, bool $spectator = false, bool $transfer = false) {
        if(!$this->inGame($player, true)) {
            return;
        }

        if($spectator || isset($this->spectators[$player->getName()])) {
            unset($this->spectators[$player->getName()]);
        }

        switch ($this->phase) {
            case Arena::PHASE_LOBBY:
                $index = "";
                foreach ($this->players as $i => $p) {
                    if($p->getId() == $player->getId()) {
                        $index = $i;
                    }
                }
                if($index !== "" && isset($this->players[$index])) {
                    unset($this->players[$index]);
                }
                break;
            default:
                unset($this->players[$player->getName()]);
                break;
        }

        if($player->isOnline()) {
            $player->removeAllEffects();

            $player->setGamemode($this->plugin->getServer()->getDefaultGamemode());

            $player->setHealth(20);
            $player->setFood(20);

            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();

            $player->setImmobile(false);

            ScoreboardBuilder::removeBoard($player);
        }

        API::handleQuit($player, $this);

        if($death && $this->data["spectatorMode"]) $this->spectators[$player->getName()] = $player;

        if(!$this->data["spectatorMode"] || $transfer) {
            if($this->plugin->dataProvider->config["waterdog"]["enabled"]) {
                ServerManager::transferPlayer($player, $this->plugin->dataProvider->config["waterdog"]["lobbyServer"]);
            }
            $player->teleport(Position::fromObject(Vector3::fromString($this->data["leavePos"][0]), $this->plugin->getServer()->getLevelByName($this->data["leavePos"][1])));
        }

        /*if(!$death && $this->phase !== 2) {
            $player->sendMessage("§7§lSkyWars>§r§a You have successfully left the arena.");
        }*/

        if($quitMsg != "") {
            $player->sendMessage($quitMsg);
        }
    }

    public function startGame() {
        $players = [];
        $cages = $this->plugin->dataProvider->config["cage"];
        $sounds = $this->plugin->dataProvider->config["sounds"]["enabled"];
        foreach ($this->players as $player) {
            if($sounds) {
                $class = Sounds::getSound($this->plugin->dataProvider->config["sounds"]["start"]);
                $player->getLevel()->addSound(new $class($player->asVector3()));
            }
            if($cages == "enable") {
                $player->getLevel()->setBlock($player->subtract(0, 1), Block::get(0));
            }
            if($cages == "detect") {
                if(in_array($player->getLevel()->getBlock($player->subtract(0, 1))->getId(), [Block::GLASS, Block::STAINED_GLASS])) {
                    $player->getLevel()->setBlock($player->subtract(0, 1), Block::get(0));
                }
            }
            $players[$player->getName()] = $player;
            $player->setGamemode($player::SURVIVAL);
            $player->getInventory()->clearAll(true);
            $player->setImmobile(false);
            $player->removeAllEffects();
        }



        $this->players = $players;
        $this->phase = 1;

        foreach ($this->kits as $player => $kit) {
            if(isset($this->players[$player])) {
                $kit->equip($this->players[$player]);
            }
        }

        $this->fillChests();
        $this->broadcastMessage(Lang::getMsg("arena.start"), self::MSG_TITLE);
    }

    public function startRestart() {
        $player = null;
        foreach ($this->players as $p) {
            $player = $p;
        }

        $this->phase = self::PHASE_RESTART;
        if($player === null || (!$player instanceof Player) || (!$player->isOnline())) {
            return;
        }

        $player->addTitle(Lang::getMsg("arena.win.title"));
        $player->setAllowFlight(true);

        $this->plugin->getServer()->getPluginManager()->callEvent(new PlayerArenaWinEvent($this->plugin, $player, $this));
        $this->plugin->getServer()->broadcastMessage(Lang::getMsg("arena.win.message", [$player->getName(), $this->level->getFolderName()]));
        API::handleWin($player, $this);
    }

    /**
     * @param Player $player
     * @param bool $addSpectators
     * @return bool
     */
    public function inGame(Player $player, bool $addSpectators = false): bool {
        if($addSpectators && isset($this->spectators[$player->getName()])) return true;
        switch ($this->phase) {
            case self::PHASE_LOBBY:
                $inGame = false;
                foreach ($this->players as $players) {
                    if($players->getId() == $player->getId()) {
                        $inGame = true;
                    }
                }
                return $inGame;
            default:
                return isset($this->players[$player->getName()]);
        }
    }

    /**
     * @param string $message
     * @param int $id
     * @param string $subMessage
     * @param bool $addSpectators
     */
    public function broadcastMessage(string $message, int $id = 0, string $subMessage = "", bool $addSpectators = \true) {
        $players = $this->players;
        if($addSpectators) {
            foreach ($this->spectators as $index => $spectator) {
                $players[$index] = $spectator;
            }
        }
        foreach ($players as $player) {
            switch ($id) {
                case self::MSG_MESSAGE:
                    $player->sendMessage($message);
                    break;
                case self::MSG_TIP:
                    $player->sendTip($message);
                    break;
                case self::MSG_POPUP:
                    $player->sendPopup($message);
                    break;
                case self::MSG_TITLE:
                    $player->addTitle($message, $subMessage);
                    break;
            }
        }
    }

    /**
     * @return bool $end
     */
    public function checkEnd(): bool {
        return count($this->players) <= 1 || $this->scheduler->gameTime <= 0;
    }

    public function fillChests() {
        foreach ($this->level->getTiles() as $tile) {
            if($tile instanceof Chest) {
                ChestRefill::getChestRefill(ChestRefill::getChestRefillType(ChestRefill::getDefault()))->fillInventory($tile->getInventory(), ChestRefill::isSortingEnabled());
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        if(!$this->inGame($player) || !$this->data["luckyBlocks"] || $event->getBlock()->getId() !== BlockIds::SPONGE) {
            return;
        }

        $this->lbPrize->prize = rand(1, 3);
        $this->lbPrize->position = $event->getBlock()->asPosition();
        $this->lbPrize->playerPos = $player->asPosition();
        $bool = $this->lbPrize->givePrize();

        if($bool) {
            $player->addTitle(Lang::getMsg("arena.lbtitle.lucky"));
        } else {
            $player->addTitle(Lang::getMsg("arena.lbtitle.unlucky"));
        }

        $event->setDrops([]);
    }

    /**
     * @param BlockPlaceEvent $event
     */
    public function onPlace(BlockPlaceEvent $event) {
        $player = $event->getPlayer();
        if(!$this->inGame($player)) {
            return;
        }

        $block = $event->getBlock();
        if($block instanceof TNT) {
            $block->ignite(50);
            $event->setCancelled(true);
            $player->getInventory()->removeItem(Item::get($block->getItemId()));
        }
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) {
        if($this->phase != self::PHASE_LOBBY) return;
        $player = $event->getPlayer();
        if($this->inGame($player)) {
            if((!$this->scheduler->teleportPlayers) || $this->scheduler->startTime <= 5) {
                $index = null;
                foreach ($this->players as $i => $p) {
                    if($p->getId() == $player->getId()) {
                        $index = $i;
                    }
                }

                if($event->getPlayer()->asVector3()->distance(Vector3::fromString($this->data["spawns"][$index])->add(0.5, 0, 0.5)) > 1) {
                    // $event->setCancelled() will not work
                    $player->teleport(Vector3::fromString($this->data["spawns"][$index])->add(0.5, 0, 0.5));
                }
            }
        }
    }

    /**
     * @param PlayerExhaustEvent $event
     */
    public function onExhaust(PlayerExhaustEvent $event) {
        $player = $event->getPlayer();

        if(!$player instanceof Player) return;

        if($this->inGame($player) && $this->phase == self::PHASE_LOBBY) {
            $player->setFood(20);
            $event->setCancelled(true);
        }
    }

    /**
     * @param PlayerItemHeldEvent $event
     */
    public function onHeld(PlayerItemHeldEvent $event) {
        $player = $event->getPlayer();
        if(isset($this->spectators[$player->getName()]) && $event->getItem()->getId() == Item::BED) {
            if(isset($this->wantLeft[$player->getName()])) {
                $this->disconnectPlayer($player, "§7§lSkyWars>§r§a You have successfully left the game.", false, true, true);
                unset($this->wantLeft[$player->getName()]);
            }
            else {
                $player->sendMessage("§7§lSkyWars>§r§6 Do you want really left the game?");
                $this->wantLeft[$player->getName()] = true;
                $event->setCancelled(true);
            }
        }
    }

    /**
     * @param PlayerDropItemEvent $eventt
     */
    public function onDrop(PlayerDropItemEvent $event) {
        $player = $event->getPlayer();
        if($this->inGame($player) && $this->phase === 0) {
            $event->setCancelled(true);
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if($this->inGame($player) && $event->getBlock()->getId() == Block::CHEST && $this->phase == self::PHASE_LOBBY) {
            $event->setCancelled(true);
            return;
        }

        if($this->inGame($player, true) && $event->getAction() === $event::RIGHT_CLICK_AIR) {
            switch ($event->getPlayer()->getInventory()->getItemInHand()->getId()) {
                case Item::BED:
                    $this->disconnectPlayer($player, Lang::getMsg("arena.quit.player"), false, false, true);
                    break;
                case Item::FEATHER:
                    $this->plugin->kitManager->kitShop->sendKitWindow($player);
                    break;
                case Item::PAPER:
                    $form = new SimpleForm("§8§lAvailable maps", "§r§fSelect map to join.");
                    foreach ($this->plugin->arenas as $index => $arena) {
                        if($arena->phase == 0 && count($arena->players) < $arena->data["slots"] && $arena !== $this) {
                            $form->addButton("§a{$arena->data["level"]} - " . (string)count($arena->players) . " Players\n§7§oClick to join.");
                            $data = $form->getCustomData();
                            $data[] = $index;
                            $form->setCustomData($data);
                        }
                    }
                    $form->setAdvancedCallable([$this, "handleMapChange"]);
                    if(!is_array($form->getCustomData()) || count($form->getCustomData()) === 0) {
                        $player->sendMessage("§cAll the other arenas are full.");
                        break;
                    }
                    $player->sendForm($form);
                    break;
            }
            return;
        }

        if(!empty($this->data["joinsign"])) {
            if(!$block->getLevel()->getTile($block) instanceof Tile) {
                return;
            }

            $signPos = Position::fromObject(Vector3::fromString($this->data["joinsign"][0]), $this->plugin->getServer()->getLevelByName($this->data["joinsign"][1]));

            if((!$signPos->equals($block)) || $signPos->getLevel()->getId() != $block->getLevel()->getId()) {
                return;
            }

            if($this->phase == self::PHASE_GAME) {
                $player->sendMessage(Lang::getMsg("arena.join.ingame"));
                return;
            }
            if($this->phase == self::PHASE_RESTART) {
                $player->sendMessage(Lang::getMsg("arena.join.restart"));
                return;
            }

            if($this->setup) {
                return;
            }

            $this->joinToArena($player);
        }
    }

    public function onDamage(EntityDamageEvent $event) {
        $entity = $event->getEntity();
        if(!$entity instanceof Player) return;

        if($this->inGame($entity) && $this->phase === 0) {
            $event->setCancelled(true);
            if($event->getCause() === $event::CAUSE_VOID) {
                if(isset($this->data["lobby"]) && $this->data["lobby"] != null) {
                    $entity->teleport(Position::fromObject(Vector3::fromString($this->data["lobby"][0]), $this->plugin->getServer()->getLevelByName($this->data["lobby"][1])));
                }
            }
        }

        if(($this->inGame($entity) && $this->phase === 1 && $event->getCause() == EntityDamageEvent::CAUSE_FALL && ($this->scheduler->gameTime > ($this->data["gameTime"]-3)))) {
            $event->setCancelled(true);
        }

        if($this->inGame($entity) && $this->phase === 2) {
            $event->setCancelled(true);
        }

        // fake kill
        if(!$this->inGame($entity)) {
            return;
        }

        if($this->phase !== 1) {
            return;
        }

        if($event->getCause() === $event::CAUSE_VOID) {
            $event->setBaseDamage(20.0); // hack: easy check for last damage
        }

        if($entity->getHealth()-$event->getFinalDamage() <= 0) {
            $event->setCancelled(true);
            API::handleDeath($entity, $this, $event);

            switch ($event->getCause()) {
                case $event::CAUSE_CONTACT:
                case $event::CAUSE_ENTITY_ATTACK:
                    if($event instanceof EntityDamageByEntityEvent) {
                        $damager = $event->getDamager();
                        if($damager instanceof Player) {
                            API::handleKill($damager, $this, $event);
                            $this->kills[$damager->getName()]++;
                            $this->broadcastMessage(Lang::getMsg("arena.death.killed", [$entity->getName(), $damager->getName(), (string)(count($this->players)-1), (string)$this->data['slots']]));
                            break;
                        }
                    }
                    $this->broadcastMessage(Lang::getMsg("arena.death.killed", [$entity->getName(), "Player", (string)(count($this->players)-1), (string)$this->data['slots']]));
                   break;
                case $event::CAUSE_BLOCK_EXPLOSION:
                    $this->broadcastMessage(Lang::getMsg("arena.death.exploded", [$entity->getName(), (string)(count($this->players)-1), (string)$this->data['slots']]));
                    break;
                case $event::CAUSE_FALL:
                    $this->broadcastMessage(Lang::getMsg("arena.death.fell", [$entity->getName(), (string)(count($this->players)-1), (string)$this->data['slots']]));
                    break;
                case $event::CAUSE_VOID:
                    $lastDmg = $entity->getLastDamageCause();
                    if($lastDmg instanceof EntityDamageByEntityEvent) {
                        $damager = $lastDmg->getDamager();
                        if($damager instanceof Player && $this->inGame($damager)) {
                            $this->broadcastMessage(Lang::getMsg("arena.death.void.player", [$entity->getName(), $damager->getName(), (string)(count($this->players)-1), (string)$this->data['slots']]));
                            break;
                        }
                    }
                    $this->broadcastMessage(Lang::getMsg("arena.death.void", [$entity->getName(), (string)(count($this->players)-1), (string)$this->data['slots']]));
                    break;
                default:
                    $this->broadcastMessage(Lang::getMsg("arena.death", [$entity->getName(), (string)(count($this->players)-1), (string)$this->data['slots']]));
            }

            foreach ($entity->getLevel()->getEntities() as $pearl) {
                if($pearl->getOwningEntityId() === $entity->getId()) {
                    $pearl->kill(); // TODO - cancel teleporting with pearls
                }
            }

            foreach ($entity->getInventory()->getContents() as $item) {
                $entity->getLevel()->dropItem($entity, $item);
            }
            foreach ($entity->getArmorInventory()->getContents() as $item) {
                $entity->getLevel()->dropItem($entity, $item);
            }
            foreach ($entity->getCursorInventory()->getContents() as $item) {
                $entity->getLevel()->dropItem($entity, $item);
            }

            unset($this->players[$entity->getName()]);
            $this->spectators[$entity->getName()] = $entity;
            ScoreboardBuilder::removeBoard($entity);

            $entity->removeAllEffects();
            $entity->getInventory()->clearAll();
            $entity->getArmorInventory()->clearAll();
            $entity->getCursorInventory()->clearAll();

            $entity->setGamemode($entity::SPECTATOR);
            $entity->setFlying(true);

            $entity->teleport(new Position($entity->getX(), Vector3::fromString($this->data["spawns"]["spawn-1"])->getY(), $entity->getZ(), $this->level));
            $entity->getInventory()->setItem(8, Item::get(Item::BED)->setCustomName("§r§eLeave the game\n§7[Use]"));

            $this->scheduler->showReviewForm($entity);
        }
    }

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) {
        if($this->inGame($event->getPlayer(), true)) {
            $this->disconnectPlayer($event->getPlayer(), "", false, $event->getPlayer()->getGamemode() == Player::SPECTATOR || isset($this->spectators[$event->getPlayer()->getName()]));
        }
        $event->setQuitMessage("");
    }

    /**
     * @param EntityLevelChangeEvent $event
     */
    public function onLevelChange(EntityLevelChangeEvent $event) {
        $player = $event->getEntity();
        if(!$player instanceof Player) return;
        if($this->inGame($player, true)) {
            if(class_exists(SpectatingApi::class) && SpectatingApi::isSpectating($player)) {
                return;
            }
            $isLobbyExists = (isset($this->data["lobby"]) && $this->data["lobby"] !== null);
            if ($isLobbyExists) {
                $isFromLobbyLevel = $event->getOrigin()->getId() == $this->plugin->getServer()->getLevelByName($this->data["lobby"][1])->getId();
                if ($isFromLobbyLevel && $this->level instanceof Level && $event->getTarget()->getId() !== $this->level->getId()) {
                    $this->disconnectPlayer($player, "§7§lSkyWars> §r§aYou have successfully left the arena!", false, $player->getGamemode() == $player::SPECTATOR || isset($this->spectators[$player->getName()]));
                }
            } else {
                $this->disconnectPlayer($player, "§7§lSkyWars> §r§aYou have successfully left the arena!", false, $player->getGamemode() == $player::SPECTATOR || isset($this->spectators[$player->getName()]));
            }
        }
    }

    /**
     * @param PlayerChatEvent $event
     */
    public function onChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();
        if(isset($this->plugin->dataProvider->config["chat"]["custom"]) && $this->plugin->dataProvider->config["chat"]["custom"] && $this->inGame($player, true)) {
            $this->broadcastMessage(str_replace(["%player", "%message"], [$player->getName(), $event->getMessage()], $this->plugin->dataProvider->config["chat"]["format"]));
            $event->setCancelled(true);
        }
    }

    /**
     * @param Player $player
     * @param $data
     * @param SimpleForm $form
     */
    public function handleMapChange(Player $player, $data, SimpleForm $form) {
        if($data === null) return;

        $arena = $this->plugin->arenas[$form->getCustomData()[$data]];
        if($arena->phase !== 0) {
            $player->sendMessage("§7§lSkyWars> §r§cArena is in game.");
            return;
        }

        if($arena->data["slots"] <= count($arena->players)) {
            $player->sendMessage("§7§lSkyWars> §r§cArena is full");
            return;
        }

        if($arena === $this) {
            $player->sendMessage("§cYou are already in this arena!");
            return;
        }

        $this->disconnectPlayer($player, "");
        $arena->joinToArena($player);
    }

    /**
     * @param bool $restart
     */
    public function loadArena(bool $restart = false) {
        if(!$this->data["enabled"]) {
            $this->plugin->getLogger()->error("Can not load arena: Arena is not enabled!");
            return;
        }

        if(!$this->mapReset instanceof MapReset) {
            $this->mapReset = new MapReset($this);
        }

        if(!$restart) {
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

            if(!$this->plugin->getServer()->isLevelLoaded($this->data["level"])) {
                $this->plugin->getServer()->loadLevel($this->data["level"]);
            }

            $this->level = $this->plugin->getServer()->getLevelByName($this->data["level"]);
        }

        else {
            if(is_null($this->level)) {
                $this->setup = true;
                $this->plugin->getLogger()->error("Disabling arena {$this->data["level"]}: level not found!");
                $this->data["level"] = null;
                return;
            }

            $this->kills = [];
        }

        if(!$this->plugin->getServer()->isLevelLoaded($this->data["level"])) {
            $this->plugin->getServer()->loadLevel($this->data["level"]);
        }

        if(!$this->level instanceof Level) {
            $this->level = $this->mapReset->loadMap($this->data["level"]);
        }

        if(!$this->level instanceof Level) {
            $this->plugin->getLogger()->error("Disabling arena {$this->data["level"]}: level not found!");
            $this->data["level"] = null;
            return;
        }


        if(is_null($this->level)) {
            $this->setup = true;
        }

        $this->phase = 0;
        $this->players = [];
        $this->spectators = [];
        $this->plugin->dataProvider->loadedArenas[] = $this->data["level"];
    }

    /**
     * @param bool $loadArena
     * @return bool $isEnabled
     */
    public function enable(bool $loadArena = true): bool {
        if(empty($this->data)) {
            return false;
        }
        if($this->data["level"] == null) {
            return false;
        }
        if(!$this->plugin->getServer()->isLevelGenerated($this->data["level"])) {
            return false;
        }
        if(!is_int($this->data["slots"])) {
            return false;
        }
        if(!is_array($this->data["spawns"])) {
            return false;
        }
        if(count($this->data["spawns"]) != $this->data["slots"]) {
            return false;
        }
        if(!isset($this->data["pts"]) || !is_int($this->data["pts"])) {
            return false;
        }
        if(!isset($this->data["leavePos"]) || $this->data["leavePos"] === null) {
            return false;
        }
        $this->data["enabled"] = true;
        $this->setup = false;
        if($loadArena) $this->loadArena();
        return true;
    }

    private function createBasicData() {
        $this->data = [
            "level" => null,
            "slots" => 12,
            "spawns" => [],
            "enabled" => false,
            "joinsign" => [],
            "startTime" => 30,
            "gameTime" => 1200,
            "restartTime" => 10,
            "leaveGameMode" => 2,
            "spectatorMode" => true,
            "leavePos" => null,
            "luckyBlocks" => false,
            "prize" => 0,
            "pts" => 2,
            "lobby" => null
        ];
    }

    public function __destruct() {
        unset($this->scheduler);
    }
}