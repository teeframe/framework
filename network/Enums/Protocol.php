<?php

namespace Enums;

class Protocol
{
    const NULL = 0;

    // the first thing sent by the client
    // contains the version info for the client
    const INFO = 1;

    // sent by server
    const MAP_CHANGE = 2;	 // sent when client should switch map
    // const MAP_DATA         = 3;	 // map transfer, contains a chunk of the map file
    // const CON_READY        = 4;	 // connection is ready, client should send start info
    // const SNAP             = 5;	 // normal snapshot, multiple parts
    // const SNAPEMPTY        = 6;	 // empty snapshot
    // const SNAPSINGLE       = 7;	 // ?
    // const SNAPSMALL        = 8;	 //
    // const INPUTTIMING      = 9;  // reports how off the input was
    // const RCON_AUTH_STATUS = 10; // result of the authentication
    // const RCON_LINE        = 11; // line that should be printed to the remote console
    // const AUTH_CHALLANGE   = 12; //
    // const AUTH_RESULT      = 13; //

    // sent by client
    // const READY            = 14; //
    // const ENTERGAME        = 15; //
    // const INPUT            = 16; // contains the inputdata from the client
    // const RCON_CMD         = 17; //
    // const RCON_AUTH        = 18; //
    // const REQUEST_MAP_DATA = 19; //
    // const AUTH_START       = 20; //
    // const AUTH_RESPONSE    = 21; //

    // sent by both
    // const PING       = 22;
    // const PING_REPLY = 23;
    // const ERROR      = 24;

    // sent by server (todo: move it up)
    // const RCON_CMD_ADD = 25;
    // const RCON_CMD_REM = 26;
}
