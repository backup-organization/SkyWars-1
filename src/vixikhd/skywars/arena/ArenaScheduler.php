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

namespace vixikhd\skywars\arena;

use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\tile\Sign;
use vixikhd\skywars\API;
use vixikhd\skywars\form\CustomForm;
use vixikhd\skywars\form\Form;
use vixikhd\skywars\form\SimpleForm;
use vixikhd\skywars\math\Time;
use vixikhd\skywars\math\Vector3;
use vixikhd\skywars\provider\DataProvider;
use vixikhd\skywars\provider\economy\EconomyProvider;
use vixikhd\skywars\provider\lang\Lang;
use vixikhd\skywars\SkyWars;
use vixikhd\skywars\utils\ScoreboardBuilder;
use vixikhd\skywars\utils\Sounds;

/**
 * Class ArenaScheduler
 * @package skywars\arena
 */
class ArenaScheduler extends Task {

    /** @var Arena $plugin */
    protected $plugin;

    /** @var array $signSettings */
    protected $signSettings;

    /** @var int $startTime */
    public $startTime = 40;

    /** @var float|int $gameTime */
    public $gameTime = 20 * 60;

    /** @var int $restartTime */
    public $restartTime = 20;

    /** @var bool $forceStart */
    public $forceStart = false;

    /** @var bool $teleportPlayers */
    public $teleportPlayers = false;

    /**
     * ArenaScheduler constructor.
     * @param Arena $plugin
     */
    public function __construct(Arena $plugin) {
        $this->plugin = $plugin;
        $this->signSettings = $this->plugin->plugin->getConfig()->getAll()["joinsign"];
    }

    /**
     * @param int $currentTick
     */
    public function onRun(int $currentTick) {
        $this->reloadSign();

        if($this->plugin->setup) return;

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= $this->plugin->data["pts"] || $this->forceStart) {
                    $this->startTime--;
                    if(Lang::canSend("arena.starting")) {
                        $this->plugin->broadcastMessage(Lang::getMsg("arena.starting", [(string)count($this->plugin->players), (string)$this->plugin->data["slots"], Time::calculateTime($this->startTime), (string)$this->plugin->data["pts"]]), Arena::MSG_TIP);
                    }

                    foreach ($this->plugin->players as $player) {
                        $this->sendScoreboard($player);
                    }

                    if($this->startTime == 5 && $this->teleportPlayers) {
                        $players = [];
                        foreach ($this->plugin->players as $player) {
                            $players[] = $player;
                        }

                        $this->plugin->players = [];

                        foreach ($players as $index => $player) {
                            $player->teleport(Position::fromObject(Vector3::fromString($this->plugin->data["spawns"]["spawn-" . (string)($index + 1)]), $this->plugin->level));
                            $player->getInventory()->removeItem(Item::get(Item::PAPER));
                            $player->getCursorInventory()->removeItem(Item::get(Item::PAPER));


                            $this->plugin->players["spawn-" . (string)($index + 1)] = $player;
                        }
                    }

                    if($this->startTime == 0) {
                        $this->plugin->startGame();
                    }

                    else {
                        if($this->plugin->plugin->dataProvider->config["sounds"]["enabled"]) {
                            foreach ($this->plugin->players as $player) {
                                $class = Sounds::getSound($this->plugin->plugin->dataProvider->config["sounds"]["start-tick"]);
                                $player->getLevel()->addSound(new $class($player->asVector3()));
                            }
                        }
                    }
                }
                else {
                    foreach ($this->plugin->players as $player) {
                        $this->sendScoreboard($player);
                    }

                    if(Lang::canSend("arena.waiting")) {
                        $this->plugin->broadcastMessage(Lang::getMsg("arena.waiting", [(string)count($this->plugin->players), (string)$this->plugin->data["slots"], Time::calculateTime($this->startTime), (string)$this->plugin->data["pts"]]), Arena::MSG_TIP);
                    }

                    if($this->teleportPlayers && $this->startTime < $this->plugin->data["startTime"]) {
                        foreach ($this->plugin->players as $player) {
                            $player->teleport(Position::fromObject(Vector3::fromString($this->plugin->data["lobby"][0]), $this->plugin->plugin->getServer()->getLevelByName($this->plugin->data["lobby"][1])));
                        }
                    }

                    $this->startTime = $this->plugin->data["startTime"];
                }
                break;
            case Arena::PHASE_GAME:
                foreach ($this->plugin->players as $player) {
                    $this->sendScoreboard($player);
                }
                if(Lang::canSend("arena.game")) {
                    $this->plugin->broadcastMessage(Lang::getMsg("arena.game", [(string)count($this->plugin->players), Time::calculateTime($this->gameTime)]), Arena::MSG_TIP);
                }
                switch ($this->gameTime) {
                    case ($this->plugin->data["gameTime"]- 3 * 60):
                        $this->plugin->broadcastMessage("§7§lSkyWars>§r§a All chests will be refilled in 5 min.");
                        break;
                    case ($this->plugin->data["gameTime"]- 7 * 60):
                        $this->plugin->broadcastMessage("§7§lSkyWars>§r§a All chest will be refilled in 1 min.");
                        break;
                    case ($this->plugin->data["gameTime"]- 8 * 60):
                        $this->plugin->broadcastMessage("§7§lSkyWars>§r§a All chests have been refilled.");
                        break;
                }
                if($this->plugin->checkEnd()) $this->plugin->startRestart();
                $this->gameTime--;
                break;
            case Arena::PHASE_RESTART:
                foreach (array_merge($this->plugin->players, $this->plugin->spectators) as $player) {
                    $this->sendScoreboard($player);
                }
                if($this->restartTime >= 0) {
                    $this->plugin->broadcastMessage("§a> Restarting in {$this->restartTime} sec.", Arena::MSG_TIP);
                }

                if($this->restartTime === max(5, $this->plugin->data["restartTime"] - 2)) {
                    $this->showReviewForm();
                }

                switch ($this->restartTime) {
                    case 0:
                        foreach ($this->plugin->players as $player) {
                            $player->removeAllEffects();
                            $this->plugin->disconnectPlayer($player, "", false, false, true);
                        }
                        foreach ($this->plugin->spectators as $player) {
                            $player->removeAllEffects();
                            $this->plugin->disconnectPlayer($player, "", false, false, true);
                        }


                        break;
                    case -1:
                        $this->plugin->level = $this->plugin->mapReset->loadMap($this->plugin->level->getFolderName());
                        break;
                    case -6:
                        $this->plugin->loadArena(true);
                        $this->reloadTimer();
                        $this->plugin->phase = Arena::PHASE_LOBBY;
                        break;
                }
                $this->restartTime--;
                break;
        }
    }

    public function reloadSign() {
        if(!is_array($this->plugin->data["joinsign"]) || empty($this->plugin->data["joinsign"])) return;

        $signPos = Position::fromObject(Vector3::fromString($this->plugin->data["joinsign"][0]), $this->plugin->plugin->getServer()->getLevelByName($this->plugin->data["joinsign"][1]));

        if(!$signPos->getLevel() instanceof Level) return;

        if($signPos->getLevel()->getTile($signPos) === null) return;

        if(!$this->signSettings["custom"]) {
            $signText = [
                "§e§lSkyWars",
                "§9[ §b? / ? §9]",
                "§6Setup",
                "§6Wait few sec..."
            ];



            if($this->plugin->setup) {
                /** @var Sign $sign */
                $sign = $signPos->getLevel()->getTile($signPos);
                $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
                return;
            }

            $signText[1] = "§9[ §b" . count($this->plugin->players) . " / " . $this->plugin->data["slots"] . " §9]";

            switch ($this->plugin->phase) {
                case Arena::PHASE_LOBBY:
                    if(count($this->plugin->players) >= $this->plugin->data["slots"]) {
                        $signText[2] = "§6Full";
                        $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                    }
                    else {
                        $signText[2] = "§aJoin";
                        $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                    }
                    break;
                case Arena::PHASE_GAME:
                    $signText[2] = "§5InGame";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                    break;
                case Arena::PHASE_RESTART:
                    $signText[2] = "§cRestarting...";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                    break;
            }

            /** @var Sign $sign */
            $sign = $signPos->getLevel()->getTile($signPos);
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
        }

        else {
            $fix = function(string $text): string {
                $phase = $this->plugin->phase === 0 ? "Lobby" : ($this->plugin->phase === 1 ? "InGame" : "Restarting...");
                $map = ($this->plugin->level instanceof Level) ? $this->plugin->level->getFolderName() : "---";
                $text = str_replace("%phase", $phase, $text);
                $text = str_replace("%ingame", count($this->plugin->players), $text);
                $text = str_replace("%max", $this->plugin->data["slots"], $text);
                $text = str_replace("%map", $map, $text);
                return $text;
            };

            $signText = [
                $fix($this->signSettings["format"]["line-1"]),
                $fix($this->signSettings["format"]["line-2"]),
                $fix($this->signSettings["format"]["line-3"]),
                $fix($this->signSettings["format"]["line-4"])
            ];

            /** @var Sign $sign */
            $sign = $signPos->getLevel()->getTile($signPos);
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
        }
    }

    /**
     * @param Player|null $player
     */
    public function showReviewForm(?Player $player = null) {
        $config = $this->plugin->plugin->dataProvider->config;
        if(!(isset($config["review-form"]["enabled"]) && $config["review-form"]["enabled"])) {
            return;
        }

        $participation = $config["prize"]["participation"] ?? 0;
        $perKill = $config["prize"]["per-kill"] ?? 0;
        $win = $config["prize"]["win"] ?? 0;

        $players = $player !== null ? [$player] : array_merge($this->plugin->players, $this->plugin->spectators);

        /** @var Player $player */
        foreach($players as $player) {
            $killCoins = $perKill * ($this->plugin->kills[$player->getName()] ?? 0);
            $winCoins = isset($this->plugin->players[$player->getName()]) ? $win : 0;

            $finalPrize = $participation + $killCoins + $winCoins;
            if($finalPrize === 0) {
                continue;
            }

            $text = "§f§lYou won §a{$finalPrize} coins.§r\n\n";

            $text .= "§f- Participation = §a{$participation}§f coins\n";
            if($killCoins > 0) {
                $text .= "§f- §a{$this->plugin->kills[$player->getName()]} §fkills = §a$killCoins §fcoins\n";
            }
            if($winCoins > 0) {
                $text .= "§f- §a1 §awin = §a$winCoins §fcoins\n";
            }

            $text .= str_repeat("\n", 5);

            $form = new SimpleForm("Game Review", $text);
            $form->addButton("Play again");
            $form->setCustomData($this->plugin);

            $form->setAdvancedCallable(function (Player $player, $data, $form) {
                /** @var Form $form */
                if($data !== null) {
                    /** @var Arena $arena */
                    $arena = $form->getCustomData();

                    $randomArena = SkyWars::getInstance()->emptyArenaChooser->getRandomArena();
                    if($randomArena === null) {
                        if($arena->inGame($player, true)) {
                            $arena->disconnectPlayer($player, "", false, false, true);
                        }

                        $player->sendMessage("§cAll the arenas are already full.");
                        return;
                    }

                    if($arena->inGame($player, true)) {
                        $arena->disconnectPlayer($player, "", false, false, false);
                    }

                    $randomArena->joinToArena($player);
                }
            });

            $player->sendForm($form);

            if(!isset($this->plugin->rewards[$player->getName()])) {
                $this->plugin->rewards[$player->getName()] = $finalPrize;
                if($this->plugin->plugin->economyManager === null) {
                    SkyWars::getInstance()->getLogger()->error("Could not give prize ($finalPrize coins) to {$player->getName()}, you haven't set economy provider.");
                    return;
                }
                $this->plugin->plugin->economyManager->addMoney($player, $finalPrize);
            }
        }
    }

    public function sendScoreboard(Player $player) {
        $settings = $this->plugin->plugin->dataProvider->config["scoreboard"];
        $fixFormatting = function (Player $player, string $text): string {
            try {
                return str_replace(
                    ["{%line}", "{%players}", "{%maxPlayers}", "{%kit}", "{%neededPlayers}", "{%time}", "{%map}", "{%kills}"],
                    ["\n", (string)count($this->plugin->players), (string)$this->plugin->data["slots"], (isset($this->plugin->kits[$player->getName()]) ? $this->plugin->kits[$player->getName()]->getName() : "---"), (string)$this->plugin->data["pts"], $this->getCalculatedTimeByPhase(), $this->plugin->level->getFolderName(), $this->plugin->kills[$player->getName()]], $text);
            }
            catch (\Exception $exception) {
                return $text;
            }
        };
        if($settings["enabled"]) {
            switch ($this->plugin->phase) {
                case 0:
                    if(count($this->plugin->players) >= $this->plugin->data["pts"]) {
                        ScoreboardBuilder::removeBoard($player);
                        ScoreboardBuilder::sendBoard($player, $fixFormatting($player, $settings["format.starting"]));
                        return;
                    }
                    ScoreboardBuilder::removeBoard($player);
                    ScoreboardBuilder::sendBoard($player, $fixFormatting($player, $settings["format.waiting"]));
                    break;
                case 1:
                    ScoreboardBuilder::removeBoard($player);
                    ScoreboardBuilder::sendBoard($player, $fixFormatting($player, $settings["format.ingame"]));
                    break;
                case 2:
                    ScoreboardBuilder::removeBoard($player);
                    ScoreboardBuilder::sendBoard($player, $fixFormatting($player, $settings["format.restart"]));
                    break;
            }
        }
    }

    /**
     * @return string
     */
    public function getCalculatedTimeByPhase(): string {
        $time = 0;
        switch ($this->plugin->phase) {
            case 0:
                $time = $this->startTime;
                break;
            case 1:
                $time = $this->gameTime;
                break;
            case 2:
                $time = $this->restartTime;
                break;
        }
        return Time::calculateTime($time);
    }

    public function reloadTimer() {
        $this->startTime = $this->plugin->data["startTime"];
        $this->gameTime = $this->plugin->data["gameTime"];
        $this->restartTime = $this->plugin->data["restartTime"];
        $this->forceStart = false;
    }
}
