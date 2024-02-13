<?php

namespace Network\Enums;

class Protocol
{
    const NULL = 0;

    // the first thing sent by the client
    // contains the version info for the client
    const INFO = 1;

    // sent by server
    const MAP_CHANGE = 2;	 // sent when client should switch map
    const MAP_DATA   = 3;	 // map transfer, contains a chunk of the map file
    const CON_READY  = 4;	 // connection is ready, client should send start info
    // const SNAP             = 5;	 // normal snapshot, multiple parts
    // const SNAPEMPTY        = 6;	 // empty snapshot
    // const SNAPSINGLE       = 7;	 // ?
    // const SNAPSMALL        = 8;	 // ?
    // const INPUTTIMING      = 9;  // reports how off the input was
    // const RCON_AUTH_STATUS = 10; // result of the authentication
    // const RCON_LINE        = 11; // line that should be printed to the remote console
    // const AUTH_CHALLANGE   = 12; //
    // const AUTH_RESULT      = 13; //

    // sent by client
    const READY     = 14; //
    const ENTERGAME = 15; //

    // const INPUT            = 16; // contains the inputdata from the client
    // const RCON_CMD         = 17; //
    // const RCON_AUTH        = 18; //
    const REQUEST_MAP_DATA = 19; //
    // const AUTH_START       = 20; //
    // const AUTH_RESPONSE    = 21; //

    // sent by both
    // const PING       = 22;
    // const PING_REPLY = 23;
    // const ERROR      = 24;

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
