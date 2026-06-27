<?php

namespace TeeFrame\Game;

class GameConstants
{
    // Weapon constants (Teeworlds 0.6)
    public const WEAPON_HAMMER  = 0;
    public const WEAPON_GUN     = 1;
    public const WEAPON_SHOTGUN = 2;
    public const WEAPON_GRENADE = 3;
    public const WEAPON_RIFLE   = 4;
    public const WEAPON_NINJA   = 5;
    public const NUM_WEAPONS    = 6;

    // Special weapon identifiers for kills (Teeworlds 0.6)
    public const WEAPON_GAME  = -3; // team switching etc
    public const WEAPON_SELF  = -2; // console kill command
    public const WEAPON_WORLD = -1; // death tiles etc

    // Team constants (Teeworlds 0.6)
    public const TEAM_SPECTATORS = -1;
    public const TEAM_RED         = 0;
    public const TEAM_BLUE        = 1;

    // Spectator mode (Teeworlds 0.6)
    public const SPEC_FREEVIEW = -1;

    // Pickup types (Teeworlds 0.6)
    public const POWERUP_HEALTH = 0;
    public const POWERUP_ARMOR  = 1;
    public const POWERUP_WEAPON = 2;
    public const POWERUP_NINJA  = 3;

    // Hook state constants (Teeworlds 0.6)
    public const HOOK_RETRACTED     = -1;
    public const HOOK_IDLE          = 0;
    public const HOOK_RETRACT_START = 1;
    public const HOOK_RETRACT_END   = 3;
    public const HOOK_FLYING        = 4;
    public const HOOK_GRABBED       = 5;

    // Core event bit flags (Teeworlds 0.6)
    public const COREEVENT_GROUND_JUMP        = 0x01;
    public const COREEVENT_AIR_JUMP           = 0x02;
    public const COREEVENT_HOOK_LAUNCH        = 0x04;
    public const COREEVENT_HOOK_ATTACH_PLAYER = 0x08;
    public const COREEVENT_HOOK_ATTACH_GROUND = 0x10;
    public const COREEVENT_HOOK_HIT_NOHOOK    = 0x20;
    public const COREEVENT_HOOK_RETRACT       = 0x40;

    // Sound constants (Teeworlds 0.6)
    public const SOUND_GUN_FIRE = 0;
    public const SOUND_SHOTGUN_FIRE = 1;
    public const SOUND_GRENADE_FIRE = 2;
    public const SOUND_HAMMER_FIRE = 3;
    public const SOUND_HAMMER_HIT = 4;
    public const SOUND_NINJA_FIRE = 5;
    public const SOUND_GRENADE_EXPLODE = 6;
    public const SOUND_NINJA_HIT = 7;
    public const SOUND_RIFLE_FIRE = 8;
    public const SOUND_RIFLE_BOUNCE = 9;
    public const SOUND_WEAPON_SWITCH = 10;
    public const SOUND_PLAYER_PAIN_SHORT = 11;
    public const SOUND_PLAYER_PAIN_LONG = 12;
    public const SOUND_BODY_LAND = 13;
    public const SOUND_PLAYER_AIRJUMP = 14;
    public const SOUND_PLAYER_JUMP = 15;
    public const SOUND_PLAYER_DIE = 16;
    public const SOUND_PLAYER_SPAWN = 17;
    public const SOUND_PLAYER_SKID = 18;
    public const SOUND_TEE_CRY = 19;
    public const SOUND_HOOK_LOOP = 20;
    public const SOUND_HOOK_ATTACH_GROUND = 21;
    public const SOUND_HOOK_ATTACH_PLAYER = 22;
    public const SOUND_HOOK_NOATTACH = 23;
    public const SOUND_PICKUP_HEALTH = 24;
    public const SOUND_PICKUP_ARMOR = 25;
    public const SOUND_PICKUP_GRENADE = 26;
    public const SOUND_PICKUP_SHOTGUN = 27;
    public const SOUND_PICKUP_NINJA = 28;
    public const SOUND_WEAPON_SPAWN = 29;
    public const SOUND_WEAPON_NOAMMO = 30;
    public const SOUND_HIT = 31;

}
