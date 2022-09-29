<?php
include "vendor/autoload.php";
include "language.php";
include "PlayTracker.class.php";

define("GUILD_ID", 519268261372755968);
define("CHANNEL_MAIN", 960555224056086548); 
define("CHANNEL_ADMIN", 641102112981385226); 
define("CHANNEL_LOG_PLAYING", 1019768367604838460); 
define("CHANNEL_LOG_VOICE", 1020683057835020358); 
define("CHANNEL_VOICE_MAIN", 960557917784920104); 
define("CHANNEL_VOICE_PLAYING", 1019237971217612840); 
define("ROLE_ADMIN", 929172055977508924);
define("ROLE_AFK", 1020313717805699185);
define("ROLE_PLAYING", 1020385919695585311);
define("SERVER_NAME", "VIRUXE's Sandbox");

$env = Dotenv\Dotenv::createImmutable(__DIR__);
$env->load();
$env->required('TOKEN');

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Activity;
use Discord\Parts\WebSockets\PresenceUpdate;
use Discord\Parts\WebSockets\VoiceStateUpdate;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;

print("Starting Padrinho\n\n");

$guild        = (object) NULL;
$mainChannel  = (object) NULL;
$logChannel   = (object) NULL;
$adminChannel = (object) NULL;

$tracker      = new GameTracker();

$discord = new Discord([
    'token'          => $_ENV['TOKEN'],
    'intents'        => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS | Intents::GUILD_PRESENCES,
    'loadAllMembers' => false
]);

$discord->on('ready', function (Discord $discord) {
	global $guild, $mainChannel, $adminChannel, $logChannel;

    echo "Bot is ready!", PHP_EOL;

	$guild        = $discord->guilds->get("id", GUILD_ID);
	$mainChannel  = $guild->channels->get("id", CHANNEL_MAIN);
	$adminChannel = $guild->channels->get("id", CHANNEL_ADMIN);
	$logChannel   = $guild->channels->get("id", CHANNEL_LOG_PLAYING);

	// include "registerCommands.php";
});

$discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
	if ($message->author->bot) return; // Ignore bots bullshit

	if($message->member->roles->get("id", ROLE_AFK)) {
		$message->member->removeRole(ROLE_AFK);
	}

	include "chatJokes.php";

	echo "{$message->author->username}: {$message->content}", PHP_EOL;
});

$discord->on(Event::PRESENCE_UPDATE, function (PresenceUpdate $presence, Discord $discord) {
	global $logChannel, $tracker;

	$game    = $presence->activities->filter(fn ($activity) => $activity->type == Activity::TYPE_PLAYING)->first();
	$member  = $presence->member;
	$isAdmin = $member->roles->get("id", ROLE_ADMIN);
	$isAFK   = $member->roles->get("id", ROLE_AFK);

	if($member->status == "idle" && !$isAFK) {
		$member->addRole(ROLE_AFK);
		if($member->getVoiceChannel()) $member->moveMember(NULL, "Became AFK.");
	}
	elseif($member->status == "online" && $isAFK) {
		$member->removeRole(ROLE_AFK, "Came back online.");
	}
	
	// Check if this activity is actually different than what we've got saved already, if so then save
	if(!$tracker->set($member->username, $game?->name, $game?->state)) return;

	// Apply Ingame Role if inside Gameserver
	if($game?->name == SERVER_NAME || $game?->state == SERVER_NAME) {
		$member->addRole(ROLE_PLAYING);
		// Move player if he is inside a voice channel
		if($member->getVoiceChannel()) $member->moveMember(CHANNEL_VOICE_PLAYING, "Began playing.");
	} else {
		$member->removeRole(ROLE_PLAYING);
		// Move player if he is inside a voice channel
		if($member->getVoiceChannel() && !$isAdmin) $member->moveMember(CHANNEL_VOICE_MAIN, "Stopped playing.");
	}

	$logChannel->sendMessage("**{$member->username}** " . ($game ? ($game->state ? _U("game","playing", $game->name, $game->state) : "está agora a jogar **$game->name**") : _U("game", "not_playing")));
});

$discord->listenCommand('afk', function (Interaction $interaction) {
	global $mainChannel, $adminChannel;

	$member  = $interaction->member;
	$isAFK   = $member->roles->get("id", ROLE_AFK);    // Check if the member has the role or not
	$isAdmin = $member->roles->get("id", ROLE_ADMIN);

	if(!$isAFK) {
		$message = "$member ficou agora AFK";
		$member->addRole(ROLE_AFK);
		$member->moveMember(NULL); // Remove member from Voice Channels
		$mainChannel->sendMessage($message);
		if($isAdmin) $adminChannel->sendMessage($message);
	} else {
		$message = "$member não está mais AFK.";
		$member->removeRole(ROLE_AFK); // Add or Remove Role accordingly
		$mainChannel->sendMessage($message);
		if($isAdmin) $adminChannel->sendMessage($message);
	}

	$interaction->respondWithMessage(MessageBuilder::new()->setContent($isAFK ? _U("afk", "self_not_afk") : _U("afk", "self_afk")), true);
});

$discord->on(Event::VOICE_STATE_UPDATE, function(VoiceStateUpdate $voiceState, Discord $discord, $oldState) {
	$member 	= $voiceState->member;
	$isPlaying 	= $member->roles->get("id", ROLE_PLAYING);
	$isAdmin 	= $member->roles->get("id", ROLE_ADMIN);

	// Don't let the player move to the lobby channel, unless he's an admin or was pushed
	if(!$isAdmin && $isPlaying && $voiceState->channel_id == CHANNEL_VOICE_MAIN)
		$member->moveMember(CHANNEL_VOICE_PLAYING, "Tried switching back to the lobby.");
});

$discord->run();