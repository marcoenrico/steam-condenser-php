<?php
/**
 * This code is free software; you can redistribute it and/or modify it under
 * the terms of the new BSD License.
 *
 * Copyright (c) 2008-2009, Sebastian Staudt
 *
 * @author Sebastian Staudt
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @package Steam Condenser (PHP)
 * @subpackage Steam Community
 */

require_once 'exceptions/SteamCondenserException.php';
require_once 'steam/community/GameStats.php';
require_once 'steam/community/SteamGroup.php';

/**
 * @package Steam Condenser (PHP)
 * @subpackage Steam Community
 */
class SteamId {

    /**
     * @var array
     */
    public static $steamIds = array();

    /**
     * @var String
     */
    private $customUrl;

    /**
     * @var int
     */
    private $fetchTime;

    /**
     * @var array
     */
    private $games;

    /**
     * @var String
     */
    private $steamId64;

    /**
     * Returns whether the requested SteamID is already cached
     * @param String id
     */
    public static function isCached($id) {
        return array_key_exists(strtolower($id), self::$steamIds);
    }

    /**
     * Clears the group cache
     */
    public static function clearCache() {
        self::$steamIds = array();
    }

    /**
     * Converts the SteamID as reported by game servers to a 64bit SteamID
     * @return String
     */
    public static function convertSteamIdToCommunityId($steamId) {
        if($steamId == 'STEAM_ID_LAN' || $steamId == 'BOT') {
            throw new SteamCondenserException("Cannot convert SteamID \"$steamId\" to a community ID.");
        }
        if(preg_match('/^STEAM_[0-1]:[0-1]:[0-9]+$/', $steamId) == 0) {
            throw new SteamCondenserException("SteamID \"$steamId\" doesn't have the correct format.");
        }

        $steamId = explode(':', substr($steamId, 6));
        $steamId = $steamId[1] + $steamId[2] * 2 + 76561197960265728;

        return number_format($steamId, 0, '', '');
    }

    /**
     * This checks the cache for an existing SteamID. If it exists it is returned.
     * Otherwise a new SteamID is created.
     * @param String $id
     * @param boolean $fetch
     * @param boolean $bypassCache
     * @return SteamId
     */
    public static function create($id, $fetch = true, $bypassCache = false) {
        $id = strtolower($id);
        if(self::isCached($id) && !$bypassCache) {
            $steamId = self::$steamIds[$id];
            if($fetch && !$steamId->isFetched()) {
                $steamId->fetchMembers();
            }
            return $steamId;
        } else {
            return new SteamId($id, $fetch);
        }
    }

    /**
     * Creates a new SteamId object using the SteamID64 converted from a server
     * SteamID given by +steam_id+
     */
    public static function getFromSteamId($steamId) {
        return new SteamId(self::convertSteamIdToCommunityId($steamId));
    }

    /**
     * Creates a new SteamId object for the given SteamID, either numeric or the
     * custom URL specified by the user. If fetch is true, fetch_data is used to
     * load data into the object. fetch defaults to true.
     * Due to restrictions in PHP's integer representation you have to use
     * String representation for numeric IDs also, e.g. '76561197961384956'.
     * @param String $id
     * @param boolean $fetch
     */
    public function __construct($id, $fetch = true) {
        if(is_numeric($id)) {
            $this->steamId64 = $id;
        }
        else {
            $this->customUrl = strtolower($id);
        }

        if($fetch) {
            $this->fetchData();
        }

        $this->cache();
    }

    /**
     * Saves this SteamID in the cache
     */
    public function cache() {
        if(!array_key_exists($this->steamId64, self::$steamIds)) {
            self::$steamIds[$this->steamId64] = $this;
            if(!empty($this->customUrl) &&
               !array_key_exists($this->customUrl, self::$steamIds)) {
               self::$steamIds[$this->customUrl] = $this;
            }
        }
    }

    /**
     * Fetchs data from the Steam Community by querying the XML version of the
     * profile specified by the ID of this SteamID
     */
    private function fetchData() {
        $url = $this->getBaseUrl() . "?xml=1";
        $profile = new SimpleXMLElement(file_get_contents($url));

        $this->imageUrl = (string) $profile->avatarIcon;
        $this->onlineState = (string) $profile->onlineState;
        $this->privacyState = (string) $profile->privacyState;
        $this->stateMessage = (string) $profile->stateMessage;
        $this->steamId = (string) $profile->steamID;
        $this->steamId64 = (string) $profile->steamID64;
        $this->vacBanned = (bool) $profile->vacBanned;
        $this->visibilityState = (int) $profile->visibilityState;

        if($this->privacyState == "public") {
            $this->customUrl = strtolower((string) $profile->customURL);
            $this->favoriteGame = (string) $profile->favoriteGame->name;
            $this->favoriteGameHoursPlayed = (string) $profile->favoriteGame->hoursPlayed2wk;
            $this->headLine = (string) $profile->headline;
            $this->hoursPlayed = (float) $profile->hoursPlayed2Wk;
            $this->location = (string) $profile->location;
            $this->memberSince = (string) $profile->memberSince;
            $this->realName = (string) $profile->realname;
            $this->steamRating = (float) $profile->steamRating;
            $this->summary = (string) $profile->summary;
        }

        foreach($profile->mostPlayedGames->mostPlayedGame as $mostPlayedGame) {
            $this->mostPlayedGames[(string) $mostPlayedGame->gameName] = (float) $mostPlayedGame->hoursPlayed;
        }

        foreach($profile->friends->friend as $friend) {
            $this->friends[] = new SteamId((string) $friend->steamID64, false);
        }

        foreach($profile->groups->group as $group) {
            $this->groups[] = new SteamGroup((string) $group->groupID64, false);
        }

        foreach($profile->weblinks->weblink as $link) {
            $this->links[(string) $link->title] = (string) $link->link;
        }

        $this->fetchTime = time();
    }

    /**
     * Fetches the games this user owns
     */
    private function fetchGames() {
        $this->games = array();

        $dom = new DOMDocument();
        $dom->recover = true;
        $dom->strictErrorChecking = false;
        @$dom->loadHTML(file_get_contents($this->getBaseUrl() . "/games"));

        foreach($dom->getElementsByTagName('h4') as $game) {
            $gameName = $game->nodeValue;
            $stats = $game->nextSibling->nextSibling;

            if($stats->nodeName == 'br') {
                $this->games[$gameName] = false;
            }
            else {
                if($stats->nodeName == 'h5') {
                    $stats = $stats->nextSibling->nextSibling;
                }

                if($stats->nodeName == 'br') {
                    $this->games[$gameName] = false;
                }
                else {
                    $stats = $stats->nextSibling->nextSibling;
                    preg_match('#http://steamcommunity.com/stats/([0-9a-zA-Z:]+)/achievements/#', $stats->getAttribute('href'), $friendlyName);
                    $this->games[$gameName] = strtolower($friendlyName[1]);
                }
            }
        }
    }

    /**
     * @return String
     */
    private function getBaseUrl() {
        if(empty($this->customUrl)) {
            return "http://steamcommunity.com/profiles/{$this->steamId64}";
        }
        else {
            return "http://steamcommunity.com/id/{$this->customUrl}";
        }
    }

    /**
     * Return the time the data of this SteamId has been fetched
     * @return int
     */
    public function getFetchTime() {
        return $this->fetchTime;
    }

    /**
     *
     * @return String
     */
    public function getFullAvatarUrl() {
        return $this->imageUrl . "_full.jpg";
    }

    /**
     * Returns a associative array with the games this user owns. The keys are
     * the games' names and the values are the "friendly names" used for stats
     * or false if the games has no stats.
     * @return array
     */
    public function getGames() {
        if(empty($this->games)) {
            $this->fetchGames();
        }

        return $this->games;
    }

    /**
     *
     * @param $gameName
     * @return GameStats
     */
    public function getGameStats($gameName) {
        $gameName = strtolower($gameName);

        if(in_array($gameName, array_values($this->getGames()))) {
            $friendlyName = $gameName;
        }
        else if(array_key_exists($gameName, $this->getGames())) {
            $friendlyName = $this->games[$gameName];
        }
        else {
            throw new SteamCondenserException("Stats for game {$gameName} do not exist.");
        }

        if(empty($this->customUrl)) {
            return GameStats::createGameStats($this->steamId64, $friendlyName);
        }
        else {
            return GameStats::createGameStats($this->customUrl, $friendlyName);
        }
    }

    /**
     *
     * @return String
     */
    public function getIconAvatarUrl() {
        return $this->imageUrl . "_.jpg";
    }

    /**
     * Returns the URL of the medium version of this user's avatar
     * @return String
     */
    public function getMediumAvatarUrl() {
        return $this->imageUrl . "_medium.jpg";
    }

    /**
     * Returns whether the owner of this SteamID is VAC banned
     * @return boolean
     */
    public function isBanned() {
        return $this->vacBanned;
    }

    /**
     * Returns whether the data for this SteamID has already been fetched
     * @return boolean
     */
    public function isFetched() {
        return !empty($this->fetchTime);
    }

    /**
     * Returns whether the owner of this SteamId is playing a game
     * @return boolean
     */
    public function isInGame() {
        return $this->onlineState == "in-game";
    }

    /**
     * Returns whether the owner of this SteamID is currently logged into Steam
     * @return boolean
     */
    public function isOnline() {
        return ($this->onlineState == "online") || ($this->onlineState == "in-game");
    }
}
?>