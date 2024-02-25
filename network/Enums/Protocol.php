<?php

namespace Network\Enums;

class Protocol
{
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

    // Game messages. This is an adaptation of the original game messages
    // TeeFrame will threat every >127 message as a game message
    const SV_MOTD = 128 + 1;

    // const SV_BROADCAST  = 128 + 2;
    // const SV_CHAT       = 128 + 3;
    // const SV_KILLMSG    = 128 + 4;
    // const SV_SOUNDGLOBAL= 128 + 5;
    const SV_TUNEPARAMS = 128 + 6;

    // const SV_EXTRAPROJECTILE = 128 + 7;
    const SV_READYTOENTER = 128 + 8;

    // const SV_WEAPONPICKUP = 128 + 9;
    // const SV_EMOTICON = 128 + 10;
    const SV_VOTECLEAROPTIONS = 128 + 11;
    const CL_START_INFO       = 128 + 20;
}
