<?php

/**
 * Copyright 2018 GamakCZ
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace vixikhd\skywars\commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginIdentifiableCommand;
use pocketmine\item\WrittenBook;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use vixikhd\skywars\arena\Arena;
use vixikhd\skywars\arena\object\EmptyArenaChooser;
use vixikhd\skywars\arena\object\LuckyBlockPrize;
use vixikhd\skywars\kit\KitManager;
use vixikhd\skywars\SkyWars;

/**
 * Class SkyWarsCommand
 * @package skywars\commands
 */
class SkyWarsCommand extends Command implements PluginIdentifiableCommand {

    /** @var SkyWars $plugin */
    protected $plugin;

    /**
     * SkyWarsCommand constructor.
     * @param SkyWars $plugin
     */
    public function __construct(SkyWars $plugin) {
        $this->plugin = $plugin;
        parent::__construct("skywars", "SkyWars commands", \null, ["sw"]);
    }

    /**
     * @param CommandSender $sender
     * @param string $commandLabel
     * @param array $args
     * @return mixed|void
     */
    public function execute(CommandSender $sender, string $commandLabel, array $args) {
        if(!isset($args[0])) {
            $sender->sendMessage("§cUsage: §7/sw help");
            return;
        }

        switch ($args[0]) {
            case "debug":
                foreach ($this->plugin->arenas as $arena) {
                    $sender->sendMessage("{$arena->data["level"]} - " . implode(", ", array_keys($arena->players)));
                }
                break;
            case "help":
                if(!$sender->hasPermission("sw.cmd.help")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(isset($args[1]) && $args[1] == "2") {
                    $sender->sendMessage("§a> SkyWars commands (2/3):\n" .
                        "§7/sw shop : Open SkyWars kit shop\n".
                        "§7/sw start : Force start game\n".
                        "§7/sw join : Join to arena\n".
                        "§7/sw leave : Leave the arena\n".
                        "§7/sw restart : Restart skywars arena");
                    break;
                }
                if(isset($args[1]) && $args[1] == "3") {
                    $sender->sendMessage("§a> SkyWars commands (3/3):\n" .
                        "§7/sw version : Displays information about plugin\n".
                        "§7/sw kit : Choose kit for game\n".
                        "§7/sw reload : Reloads SkyWars config\n".
                        "§7/sw kick : Kicks player from the game\n".
                        "§7/sw random : Joins player to random arena");
                    break;
                }
                $sender->sendMessage("§a> SkyWars commands (1/3):\n" .
                    "§7/sw help : Displays list of SkyWars commands\n".
                    "§7/sw create : Create SkyWars arena\n".
                    "§7/sw remove : Remove SkyWars arena\n".
                    "§7/sw set : Set SkyWars arena\n".
                    "§7/sw arenas : Displays list of arenas");
                break;
            case "create":
                if(!$sender->hasPermission("sw.cmd.create")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sw create <arenaName>");
                    break;
                }
                if(isset($this->plugin->arenas[$args[1]])) {
                    $sender->sendMessage("§c> Arena $args[1] already exists!");
                    break;
                }
                $this->plugin->arenas[$args[1]] = new Arena($this->plugin, []);
                $sender->sendMessage("§a> Arena $args[1] created!");
                break;
            case "remove":
                if(!$sender->hasPermission("sw.cmd.remove")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sw remove <arenaName>");
                    break;
                }
                if(!isset($this->plugin->arenas[$args[1]])) {
                    $sender->sendMessage("§c> Arena $args[1] was not found!");
                    break;
                }

                /** @var Arena $arena */
                $arena = $this->plugin->arenas[$args[1]];

                foreach ($arena->players as $player) {
                    $player->teleport($this->plugin->getServer()->getDefaultLevel()->getSpawnLocation());
                }

                if(is_file($file = $this->plugin->getDataFolder() . "arenas" . DIRECTORY_SEPARATOR . $args[1] . ".yml")) unlink($file);
                unset($this->plugin->arenas[$args[1]]);

                $sender->sendMessage("§a> Arena removed!");
                break;
            case "set":
                if(!$sender->hasPermission("sw.cmd.set")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(!$sender instanceof Player) {
                    $sender->sendMessage("§c> This command can be used only in-game!");
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sw set <arenaName|all> OR §7/sw set <arenaName1,arenaName2,...>");
                    break;
                }
                if(isset($this->plugin->setters[$sender->getName()])) {
                    $sender->sendMessage("§c> You are already in setup mode!");
                    break;
                }

                /** @var Arena[] $arenas */
                $arenas = [];

                if(isset($this->plugin->arenas[$args[1]])) {
                    $arenas[] = $this->plugin->arenas[$args[1]];
                }

                if(count($targetArenas = explode(",", $args[1])) > 1) {
                    foreach ($targetArenas as $arena) {
                        if(isset($this->plugin->arenas[$arena])) {
                            $arenas[] = $this->plugin->arenas[$arena];
                        }
                    }
                }

                if($args[1] == "all") {
                    $arenas = array_values($this->plugin->arenas);
                }

                if(count($arenas) === 0) {
                    $sender->sendMessage("§c> Arena wasn't found.");
                    break;
                }

                $target = count($arenas) > 1 ? $arenas : $arenas[0];

                $sender->sendMessage("§a> You've joined setup mode.\n".
                    "§7- use §lhelp §r§7to display available commands\n"  .
                    "§7- or §ldone §r§7to leave setup mode");

                $this->plugin->setters[$sender->getName()] = $target;
                break;
            case "start":
                if(!$sender->hasPermission("sw.cmd.start")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                /** @var Arena $arena */
                $arena = null;

                if(isset($args[1])) {
                    if(!isset($this->plugin->arenas[$args[1]])) {
                        $sender->sendMessage("§c> Arena $args[1] was not found!");
                        break;
                    }
                    $arena = $this->plugin->arenas[$args[1]];
                }

                if($arena == null && $sender instanceof Player) {
                    foreach ($this->plugin->arenas as $arenas) {
                        if($arenas->inGame($sender)) {
                            $arena = $arenas;
                        }
                    }
                }
                else {
                    $sender->sendMessage("§cUsage: §7/sw start <arena>");
                    break;
                }

                $arena->scheduler->forceStart = true;
                $arena->scheduler->startTime = 10;

                $sender->sendMessage("§a> Arena starts in 10 sec!");
                break;
            case "shop":
                if(!$sender->hasPermission("sw.cmd.shop")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(!$sender instanceof Player) {
                    $sender->sendMessage("§cThis command can be used only in-game!");
                    break;
                }
                if(!$this->plugin->kitManager instanceof KitManager) {
                    $sender->sendMessage("§c> Enable KitManager first!");
                    break;
                }
                $this->plugin->kitManager->kitShop->sendShopKitWindow($sender);
                break;
            case "arenas":
                if(!$sender->hasPermission("sw.cmd.arenas")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(count($this->plugin->arenas) === 0) {
                    $sender->sendMessage("§6> There are 0 arenas.");
                    break;
                }
                $list = "§7> Arenas:\n";
                foreach ($this->plugin->arenas as $name => $arena) {
                    if($arena->setup) {
                        $list .= "§7- $name : §cdisabled\n";
                    }
                    else {
                        $list .= "§7- $name : §aenabled\n";
                    }
                }
                $sender->sendMessage($list);
                break;
            case "leave":
                if(!$sender->hasPermission("sw.cmd.leave")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }

                if(!$sender instanceof Player) {
                    $sender->sendMessage("§c> This command can be used only in-game!");
                    break;
                }

                $arena = null;
                foreach ($this->plugin->arenas as $arenas) {
                    if($arenas->inGame($sender)) {
                        $arena = $arenas;
                    }
                }

                if(is_null($arena)) {
                    $sender->sendMessage("§cArena not found.");
                    break;
                }

                $arena->disconnectPlayer($sender, "§a> You have successfully left the arena.", false, false, true);
                break;
            case "kick":
                if(!$sender->hasPermission("sw.cmd.kick")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }

                if(!isset($args[1]) || !isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sw kick <player> <arena>");
                    break;
                }

                if(!(($player = $this->plugin->getServer()->getPlayer($args[1])) instanceof Player)) {
                    $sender->sendMessage("§c> Player not found.");
                    break;
                }

                if(!isset($this->plugin->arenas[$args[2]])) {
                    $sender->sendMessage("§c> Arena not found.");
                }
                break;
            case "join":
                if(!$sender->hasPermission("sw.cmd.join")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }

                if(!$sender instanceof Player) {
                    $sender->sendMessage("§c> This command can be used only in-game!");
                    break;
                }

                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sw join <arenaName>");
                    break;
                }

                if(!isset($this->plugin->arenas[$args[1]])) {
                    $sender->sendMessage("§cArena {$args[1]} not found.");
                    break;
                }

                $this->plugin->arenas[$args[1]]->joinToArena($sender);
                break;
            case "random":
                if(!$sender->hasPermission("sw.cmd.random")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }

                if(!$sender instanceof Player) {
                    $sender->sendMessage("§c> This command can be used only in-game!");
                    break;
                }

                $arena = $this->plugin->emptyArenaChooser->getRandomArena();

                if($arena === null) {
                    $sender->sendMessage("§a> All the arenas are full!");
                    break;
                }
                $arena->joinToArena($sender);
                break;
            case "kit":
                if(!$sender->hasPermission("sw.cmd.kit")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }

                if(!$sender instanceof Player) {
                    $sender->sendMessage("§c> This command can be used only in-game!");
                    break;
                }

                $arena = null;
                foreach ($this->plugin->arenas as $arenas) {
                    if($arenas->inGame($sender)) {
                        $arena = $arenas;
                        break;
                    }
                }

                if(is_null($arena) || $arena->phase > 0) {
                    $sender->sendMessage("§c> You cannot select the kit right now!");
                    break;
                }

                $this->plugin->kitManager->kitShop->sendKitWindow($sender);
                break;
            default:
                if($sender->hasPermission("sw.cmd")) {
                    $sender->sendMessage("§cUsage: §7/sw help");
                    break;
                }
                $sender->sendMessage("§cYou have not permissions to use this command!");
        }

    }

    /**
     * @return SkyWars|Plugin $skywars
     */
    public function getPlugin(): Plugin {
        return $this->plugin;
    }

}
