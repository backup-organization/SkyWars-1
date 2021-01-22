<?php

declare(strict_types=1);

namespace vixikhd\skywars;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\Player;
use vixikhd\mysqlapi\DataStorage;
use vixikhd\skywars\arena\Arena;

/**
 * Class API
 * @package vixikhd\skywars
 *
 * API For server owners, here you can handle all
 */
class API {

    /**
     * @param Player $player
     * @param Arena $arena
     * @param EntityDamageEvent $event
     */
    public static function handleKill(Player $player, Arena $arena, EntityDamageEvent $event) {
        // your code
        Stats::addKill($player);

        if(class_exists(DataStorage::class))
            DataStorage::getPlayerInformation($player)->getSkyWarsStats()->addKill();
    }

    /**
     * @param Player $player
     * @param Arena $arena
     * @param EntityDamageEvent $event
     */
    public static function handleDeath(Player $player, Arena $arena, EntityDamageEvent $event) {
        // your code
    }

    /**
     * @param Player $player
     * @param Arena $arena
     */
    public static function handleWin(Player $player, Arena $arena) {
        // your code
        Stats::addKill($player);

        if(class_exists(DataStorage::class))
            DataStorage::getPlayerInformation($player)->getSkyWarsStats()->addWin();
    }

    /**
     * @param Player $player
     * @param Arena $arena
     * @param bool $force
     *
     * @return bool $allowJoin
     */
    public static function handleJoin(Player $player, Arena $arena, bool $force): bool {
        return true;
    }

    /**
     * @param Player $player
     * @param Arena $arena
     */
    public static function handleQuit(Player $player, Arena $arena) {
        // your code
    }

    /**
     * Will be called 5 secs after game end
     *
     * @param Player[] $players
     * @param Arena $arena
     */
    public static function handleRestart(array $players, Arena $arena) {
        // your code
    }
}