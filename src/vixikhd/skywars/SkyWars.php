<?php

declare(strict_types=1);

namespace vixikhd\skywars;

use pocketmine\command\Command;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Armor;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use vixikhd\skywars\arena\Arena;
use vixikhd\skywars\arena\object\EmptyArenaChooser;
use vixikhd\skywars\chestrefill\ChestRefill;
use vixikhd\skywars\chestrefill\EnchantmentManager;
use vixikhd\skywars\commands\SkyWarsCommand;
use vixikhd\skywars\event\listener\EventListener;
use vixikhd\skywars\kit\KitManager;
use vixikhd\skywars\math\Vector3;
use vixikhd\skywars\provider\DataProvider;
use vixikhd\skywars\provider\economy\EconomyManager;
use vixikhd\skywars\provider\JsonDataProvider;
use vixikhd\skywars\provider\MySQLDataProvider;
use vixikhd\skywars\provider\SQLiteDataProvider;
use vixikhd\skywars\provider\YamlDataProvider;
use vixikhd\skywars\utils\ServerManager;

/**
 * Class SkyWars
 *
 * @package skywars
 *
 * @version 1.0.0
 * @author VixikCZ gamak.mcpe@gmail.com
 * @copyright 2017-2020 (c)
 */
class SkyWars extends PluginBase implements Listener {

    /** @var SkyWars $instance */
    private static $instance;

    /** @var DataProvider $dataProvider */
    public $dataProvider = null;

    /** @var KitManager $kitManager */
    public $kitManager = null;

    /** @var EconomyManager */
    public $economyManager = null;

    /** @var EmptyArenaChooser $emptyArenaChooser */
    public $emptyArenaChooser = null;

    /** @var EventListener $eventListener */
    public $eventListener;

    /** @var Command[] $commands */
    public $commands = [];

    /** @var Arena[] $arenas */
    public $arenas = [];

    /** @var Arena[]|Arena[][] $setters */
    public $setters = [];

    /** @var int[] $setupData */
    public $setupData = [];

    public function onEnable() {
        $restart = (bool)(self::$instance instanceof $this);
        if(!$restart) {
            self::$instance = $this;
        } else {
            $this->getLogger()->notice("We'd recommend to restart server insteadof reloading. Reload can cause bugs.");
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        if(is_file($file = $this->getDataFolder() . DIRECTORY_SEPARATOR . "config.yml")) {
            $config = new Config($file, Config::YAML);
            switch (strtolower($config->get("dataProvider"))) {
                case "json":
                    $this->dataProvider = new JsonDataProvider($this);
                    break;
                case "sqlite":
                    $this->dataProvider = new SQLiteDataProvider($this);
                    break;
                case "mysql":
                    $this->dataProvider = new MySQLDataProvider($this);
                    break;
                default:
                    $this->dataProvider = new YamlDataProvider($this);
                    break;
            }
        }
        else {
            $this->dataProvider = new YamlDataProvider($this);
        }

        EnchantmentManager::registerAdditionalEnchantments();
        ChestRefill::init();
        Stats::init();

        if($this->dataProvider->config["economy"]["enabled"]) {
            $this->economyManager = new EconomyManager($this, $this->dataProvider->config["economy"]["plugin"]);
            $this->kitManager = new KitManager($this, [
                "kits" => $this->dataProvider->config["kits"],
                "customKits" => $this->dataProvider->config["customKits"]
            ]);
        }

        $this->emptyArenaChooser = new EmptyArenaChooser($this);
        $this->eventListener = new EventListener($this);

        $this->getServer()->getCommandMap()->register("SkyWars", $this->commands[] = new SkyWarsCommand($this));
    }

    public function onDisable() {
        if($this->kitManager instanceof KitManager) {
            $this->kitManager->saveKits();
        }
        $this->dataProvider->save();
    }

    /**
     * @param PlayerJoinEvent $event
     */
    public function onJoin(PlayerJoinEvent $event) {
        if($this->dataProvider->config["waterdog"]["enabled"]) {
            $event->setJoinMessage("");
            $player = $event->getPlayer();

            $arena = $this->emptyArenaChooser->getRandomArena();
            if($arena === null) {
                kick:
                ServerManager::transferPlayer($player, $this->dataProvider->config["waterdog"]["lobbyServer"]);
                return;
            }

            $joined = $arena->joinToArena($player);
            if($joined === false) {
                goto kick;
            }
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) {
        if($event->getAction() === $event::RIGHT_CLICK_AIR && $event->getItem() instanceof Armor) {
            switch (true) {
                case in_array($event->getItem()->getId(), [Item::LEATHER_HELMET, Item::IRON_HELMET, Item::GOLD_HELMET, Item::DIAMOND_HELMET, Item::CHAIN_HELMET]):
                    $tempItem = $event->getItem();
                    $armorItem = $event->getPlayer()->getArmorInventory()->getHelmet();

                    $event->getPlayer()->getArmorInventory()->setHelmet($tempItem);
                    $event->getPlayer()->getInventory()->setItemInHand($armorItem);
                    break;
                case in_array($event->getItem()->getId(), [Item::LEATHER_CHESTPLATE, Item::IRON_CHESTPLATE, Item::GOLD_CHESTPLATE, Item::DIAMOND_CHESTPLATE, Item::CHAIN_CHESTPLATE]):
                    $tempItem = $event->getItem();
                    $armorItem = $event->getPlayer()->getArmorInventory()->getChestplate();

                    $event->getPlayer()->getArmorInventory()->setChestplate($tempItem);
                    $event->getPlayer()->getInventory()->setItemInHand($armorItem);
                    break;
                case in_array($event->getItem()->getId(), [Item::LEATHER_LEGGINGS, Item::IRON_LEGGINGS, Item::GOLD_LEGGINGS, Item::DIAMOND_LEGGINGS, Item::CHAIN_LEGGINGS]):
                    $tempItem = $event->getItem();
                    $armorItem = $event->getPlayer()->getArmorInventory()->getLeggings();

                    $event->getPlayer()->getArmorInventory()->setLeggings($tempItem);
                    $event->getPlayer()->getInventory()->setItemInHand($armorItem);
                    break;
                case in_array($event->getItem()->getId(), [Item::LEATHER_BOOTS, Item::IRON_BOOTS, Item::GOLD_BOOTS, Item::DIAMOND_BOOTS, Item::CHAIN_BOOTS]):
                    $tempItem = $event->getItem();
                    $armorItem = $event->getPlayer()->getArmorInventory()->getBoots();

                    $event->getPlayer()->getArmorInventory()->setBoots($tempItem);
                    $event->getPlayer()->getInventory()->setItemInHand($armorItem);
                    break;
            }
        }
    }

    /**
     * @param PlayerChatEvent $event
     */
    public function onChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();

        if(!isset($this->setters[$player->getName()])) {
            return;
        }

        $event->setCancelled(true);
        $args = explode(" ", $event->getMessage());

        /** @var Arena $arena */
        $arena = $this->setters[$player->getName()];
        /** @var Arena[] $arenas */
        $arenas = is_array($this->setters[$player->getName()]) ? $this->setters[$player->getName()] : [$this->setters[$player->getName()]];

        switch ($args[0]) {
            case "help":
                if(!isset($args[1]) || $args[1] == "1") {
                    $player->sendMessage("§a> SkyWars setup help (1/3):\n".
                        "§7help : Displays list of available setup commands\n" .
                        "§7slots : Update arena slots\n".
                        "§7level : Set arena level\n".
                        "§7spawn : Set arena spawns\n".
                        "§7joinsign : Set arena joinsign\n".
                        "§7leavepos : Sets position to leave arena");
                }
                elseif($args[1] == "2") {
                    $player->sendMessage("§a> SkyWars setup help (2/3):\n".
                        "§7starttime : Set start time (in sec)\n" .
                        "§7gametime : Set game time (in sec)\n".
                        "§7restarttime : Set restart time (in sec)\n".
                        "§7lucky : Enables the lucky mode\n".
                        "§7spectator : Enables the spectator mode\n".
                        "§7enable : Enable the arena");
                }
                elseif($args[1] == "3") {
                    $player->sendMessage("§a> SkyWars setup help (3/3):\n".
                        "§7prize : Set arena win prize (0 = nothing)\n".
                        "§7addcmdprize : Adds command that is called when player win the game\n".
                        "§7rmcmdprize : Remove command that is called when player win the game\n".
                        "§7savelevel : Saves level to disk\n".
                        "§7startplayers : Sets players count needed to start\n".
                        "§7lobby : Sets arena lobby\n"
                    );
                }

                break;
            case "slots":
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7slots <int: slots>");
                    break;
                }
                foreach ($arenas as $arena)
                    $arena->data["slots"] = (int)$args[1];
                $player->sendMessage("§a> Slots updated to $args[1]!");
                break;
            case "level":
                if(is_array($arena)) {
                    $player->sendMessage("§c> Level must be different for each arena.");
                    break;
                }
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7level <levelName>");
                    break;
                }
                if(!$this->getServer()->isLevelGenerated($args[1])) {
                    $player->sendMessage("§c> Level $args[1] does not found!");
                    break;
                }
                $player->sendMessage("§a> Arena level updated to $args[1]!");

                foreach ($arenas as $arena)
                    $arena->data["level"] = $args[1];
                break;
            case "spawn":
                if(is_array($arena)) {
                    $player->sendMessage("§c> Spawns are different for each arena.");
                    break;
                }

                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7setspawn <int: spawn>");
                    break;
                }

                if($args[1] == "all") {
                    $this->setupData[$player->getName()] = [1, 1];
                    $player->sendMessage("§a> Break blocks to update spawns.");
                    break;
                }

                if(!is_numeric($args[1])) {
                    $player->sendMessage("§cType number!");
                    break;
                }

                if((int)$args[1] > $arena->data["slots"]) {
                    $player->sendMessage("§cThere are only {$arena->data["slots"]} slots!");
                    break;
                }

                $arena->data["spawns"]["spawn-{$args[1]}"] = (new Vector3((int)$player->getX(), (int)$player->getY(), (int)$player->getZ()))->__toString();
                $player->sendMessage("§a> Spawn $args[1] set to X: " . (string)round($player->getX()) . " Y: " . (string)round($player->getY()) . " Z: " . (string)round($player->getZ()));

                break;
            case "joinsign":
                if(is_array($arena)) {
                    $player->sendMessage("§c> Join signs should be different for each arena.");
                    break;
                }

                $player->sendMessage("§a> Break block to set join sign!");
                $this->setupData[$player->getName()] = [
                    0 => 0
                ];

                break;
            case "leavepos":
                foreach ($arenas as $arena) {
                    $arena->data["leavePos"] = [(new Vector3((int)$player->getX(), (int)$player->getY(), (int)$player->getZ()))->__toString(), $player->getLevel()->getFolderName()];
                }

                $player->sendMessage("§a> Leave position updated.");
                break;
            case "enable":
                if(is_array($arena)) {
                    $player->sendMessage("§c> You cannot enable arena in mode multi-setup mode.");
                    break;
                }

                if(!$arena->setup) {
                    $player->sendMessage("§6> Arena is already enabled!");
                    break;
                }

                if(!$arena->enable()) {
                    $player->sendMessage("§c> Could not load arena, there are missing information!");
                    break;
                }

                foreach ($arenas as $arena)
                    $arena->mapReset->saveMap($arena->level);

                $player->sendMessage("§a> Arena enabled!");
                break;
            case "done":
                $player->sendMessage("§a> You have successfully left setup mode!");
                unset($this->setters[$player->getName()]);
                if(isset($this->setupData[$player->getName()])) {
                    unset($this->setupData[$player->getName()]);
                }
                break;
            case "starttime":
                if(!isset($args[1])) {
                    $player->sendMessage("§c> Usage: §7starttime <int: start time (in sec)>");
                    break;
                }
                if(!is_numeric($args[1])) {
                    $player->sendMessage("§c> Type start time in seconds (eg. 1200)");
                    break;
                }

                foreach ($arenas as $arena) {
                    $arena->data["startTime"] = (int)$args[1];
                    if($arena->setup) $arena->scheduler->startTime = (int)$args[1];
                }

                $player->sendMessage("§a> Start time updated to {$args[1]}!");
                break;
            case "gametime":
                if(!isset($args[1])) {
                    $player->sendMessage("§c> Usage: §7gametime <int: game time (in sec)>");
                    break;
                }
                if(!is_numeric($args[1])) {
                    $player->sendMessage("§c> Type game time in seconds (eg. 1200)");
                    break;
                }

                foreach ($arenas as $arena) {
                    $arena->data["gameTime"] = (int)$args[1];
                    if($arena->setup) $arena->scheduler->gameTime = (int)$args[1];
                }

                $player->sendMessage("§a> Game time updated to {$args[1]}!");
                break;
            case "restarttime":
                if(!isset($args[1])) {
                    $player->sendMessage("§c> Usage: §7restarttime <int: restart time (in sec)>");
                    break;
                }
                if(!is_numeric($args[1])) {
                    $player->sendMessage("§c> Type restart time in seconds (eg. 1200)");
                    break;
                }

                foreach ($arenas as $arena) {
                    $arena->data["restartTime"] = (int)$args[1];
                    if($arena->setup) $arena->scheduler->restartTime = (int)$args[1];
                }

                $player->sendMessage("§a> Restart time updated to {$args[1]}!");
                break;
            case "lucky":
                if(!isset($args[1]) || !in_array($args[1], ["false", "true"])) {
                    $player->sendMessage("§c> Usage: §7lucky <bool: false|true>");
                    break;
                }

                foreach ($arenas as $arena) {
                    $arena->data["luckyBlocks"] = (bool)($args[1] == "true");
                }

                $player->sendMessage("§a> Lucky mode updated to $args[1]");
                break;
            case "spectator":
                if(!isset($args[1]) || !in_array($args[1], ["false", "true"])) {
                    $player->sendMessage("§c> Usage: §7spectator <bool: false|true>");
                    break;
                }

                foreach ($arenas as $arena) {
                    $arena->data["spectatorMode"] = (bool)($args[1] == "true");
                }

                $player->sendMessage("§a> Spectator mode updated to $args[1]!");
                break;
            case "savelevel":
                foreach ($arenas as $arena) {
                    if($arena->data["level"] === null) {
                        $player->sendMessage("§c> Level not found!");
                        break;
                    }

                    if(!$arena->level instanceof Level) {
                        $player->sendMessage("§c> Invalid level type: enable arena first.");
                        break;
                    }

                    $player->sendMessage("§a> Level saved.");
                    $arena->mapReset->saveMap($arena->level);
                }

                break;
            case "prize":
                if(!isset($args[1])) {
                    $player->sendMessage("§c> Usage: §7prize <int: prize>");
                    break;
                }
                if(!is_numeric($args[1])) {
                    $player->sendMessage("§c> Invalid prize.");
                    break;
                }
                foreach ($arenas as $arena) {
                    $arena->data["prize"] = (int)$args[1];
                    $player->sendMessage("§a> Prize set to {$arena->data["prize"]}!");
                }
                break;
            case "addcmdprize":
                if(!isset($args[1])) {
                    $player->sendMessage("§c> Usage: §7addcmdprize <string: command>");
                    break;
                }

                foreach ($arenas as $arena) {
                    $arena->data["prizecmds"][] = $args[1];
                }

                $player->sendMessage("§a> Command {$args[1]} added!");
                break;
            case "rmcmdprize":
                if(!isset($args[1])) {
                    $player->sendMessage("§c> Usage: rmcmdprize <string: command>");
                    break;
                }
                foreach ($arenas as $arena) {
                    if(!isset($arena->data["prizecmds"])) {
                        $player->sendMessage("§c> Command {$args[1]} not found!");
                        break;
                    }
                    $indexes = [];
                    foreach ($arena->data["prizecmds"] as $index => $cmd) {
                        if($cmd == $args[1]) $indexes[] = $index;
                    }
                    if(empty($indexes)) {
                        $player->sendMessage("§c> Command {$args[1]} not found!");
                        break;
                    }
                    foreach ($indexes as $index) {
                        unset($arena->data["prizecmds"][$index]);
                    }
                    $player->sendMessage("§a> Removed " . (string)count($indexes) . " command(s)!");
                }
                break;
            case "startplayers":
                if(!isset($args[1]) || !is_numeric($args[1])) {
                    $player->sendMessage("§c> Usage: startplayers <int: playersToStart>");
                    break;
                }

                foreach ($arenas as $arena) {
                    $arena->data["pts"] = (int)$args[1];
                }
                $player->sendMessage("§a> Count of players needed to start is updated to {$args[1]}");
                break;
            case "lobby":
                foreach ($arenas as $arena)
                    $arena->data["lobby"] = [(new Vector3((int)$player->getX(), (int)$player->getY(), (int)$player->getZ()))->__toString(), $player->getLevel()->getFolderName()];
                $player->sendMessage("§a> Game lobby updated!");
                break;
            default:
                $player->sendMessage("§6> You are in setup mode.\n".
                    "§7- use §lhelp §r§7to display available commands\n"  .
                    "§7- or §ldone §r§7to leave setup mode");
                break;
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        if(isset($this->setupData[$player->getName()]) && isset($this->setupData[$player->getName()][0])) {
            switch ($this->setupData[$player->getName()][0]) {
                case 0:
                    $this->setters[$player->getName()]->data["joinsign"] = [(new Vector3($block->getX(), $block->getY(), $block->getZ()))->__toString(), $block->getLevel()->getFolderName()];
                    $player->sendMessage("§a> Join sign updated!");
                    unset($this->setupData[$player->getName()]);
                    $event->setCancelled(true);
                    break;
                case 1:
                    $spawn = $this->setupData[$player->getName()][1];
                    $this->setters[$player->getName()]->data["spawns"]["spawn-$spawn"] = (new Vector3((int)$block->getX(), (int)($block->getY()+1), (int)$block->getZ()))->__toString();
                    $player->sendMessage("§a> Spawn $spawn set to X: " . (string)round($block->getX()) . " Y: " . (string)round($block->getY()) . " Z: " . (string)round($block->getZ()));

                    $event->setCancelled(true);


                    $slots = $this->setters[$player->getName()]->data["slots"];
                    if($spawn + 1 > $slots) {
                        $player->sendMessage("§a> Spawns updated.");
                        unset($this->setupData[$player->getName()]);
                        break;
                    }

                    $player->sendMessage("§a> Break block to set " . (string)(++$spawn) . " spawn.");
                    $this->setupData[$player->getName()][1]++;
            }
        }
    }

    /**
     * @return SkyWars
     */
    public static function getInstance(): SkyWars {
        return self::$instance;
    }
}