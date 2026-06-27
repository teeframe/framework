<?php

namespace TeeFrame\Network;

class NetworkMessages
{
    /*
     * Control Messages
     */
    const CONTROL_KEEP_ALIVE     = 0;
    const CONTROL_CONNECT        = 1;
    const CONTROL_CONNECT_ACCEPT = 2;
    const CONTROL_CLOSE          = 4;

    /*
     * System Messages
     */
    const INFO = 1;

    // sent by server
    const MAP_CHANGE       = 2;
    const MAP_DATA         = 3;
    const CON_READY        = 4;
    const SNAP             = 5;
    const SNAPEMPTY        = 6;
    const SNAPSINGLE       = 7;
    const INPUTTIMING      = 9;
    const RCON_AUTH_STATUS = 10;
    const RCON_LINE        = 11;

    // sent by client
    const READY            = 14;
    const ENTERGAME        = 15;
    const INPUT            = 16;
    const RCON_CMD         = 17;
    const RCON_AUTH        = 18;
    const REQUEST_MAP_DATA = 19;

    // sent by both
    const PING       = 22;
    const PING_REPLY = 23;

    // sent by server (todo: move it up)
    // const RCON_CMD_ADD = 25;
    // const RCON_CMD_REM = 26;

    /*
     * Game Messages
     */
    const SV_MOTD = 1;

    // const SV_BROADCAST  = 2;
    const SV_CHAT = 3;

    // const SV_KILLMSG    = 4;
    // const SV_SOUNDGLOBAL= 5;
    const SV_TUNEPARAMS   = 6;
    const SV_READYTOENTER = 8;

    // const SV_WEAPONPICKUP = 9;
    const SV_EMOTICON         = 10;
    const SV_VOTECLEAROPTIONS = 11;
    const CL_SAY              = 17;
    const CL_START_INFO       = 20;
    const CL_KILL             = 22;
    const CL_EMOTICON         = 23;

    /*
     * Snap Items
     */
    const NETOBJTYPE_PROJECTILE = 2;
    const NETOBJTYPE_LASER      = 3;
    const NETOBJTYPE_PICKUP     = 4;
    const NETOBJTYPE_FLAG       = 5;
    const NETOBJTYPE_GAMEINFO   = 6;
    const NETOBJTYPE_GAMEDATA   = 7;
    const NETOBJTYPE_CHARACTER  = 9;
    const NETOBJTYPE_PLAYERINFO = 10;
    const NETOBJTYPE_CLIENTINFO = 11;
    const NETOBJTYPE_SPECTATOR  = 12;

    /*
     * Event Types
     */
    const NETEVENTTYPE_EXPLOSION  = 14;
    const NETEVENTTYPE_SPAWN      = 15;
    const NETEVENTTYPE_HAMMERHIT  = 16;
    const NETEVENTTYPE_DEATH      = 17;
    const NETEVENTTYPE_SOUNDWORLD = 19;
    const NETEVENTTYPE_DAMAGEIND  = 20;

    /*
     * Pickup Types
     */
    const POWERUP_HEALTH = 0;
    const POWERUP_ARMOR  = 1;
    const POWERUP_WEAPON = 2;
    const POWERUP_NINJA  = 3;
}
