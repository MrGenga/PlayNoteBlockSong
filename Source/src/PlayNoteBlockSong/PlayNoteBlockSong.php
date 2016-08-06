<?php
namespace PlayNoteBlockSong;

use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat as Color;
use pocketmine\event\TranslationContainer as Translation;
use pocketmine\level\sound\NoteblockSound;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\scheduler\CallbackTask;
use PlayNoteBlockSong\task\LoadSongAsyncTask;
use PlayNoteBlockSong\task\PlaySongTask;

class PlayNoteBlockSong extends PluginBase{
	const SONG = 0;
	const NAME = 1;

	private $songs = [], $index = 0, $song, $play = false;

	public function onEnable(){
		$this->getServer()->getScheduler()->scheduleAsyncTask(new LoadSongAsyncTask());
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new PlaySongTask($this), 2);
	}

	public function onCommand(CommandSender $sender, Command $cmd, $label, array $sub){
		$ir = $this->isRussian();
		if(!isset($sub[0]) || $sub[0] == ""){
			return false;
		}
		switch(strtolower($sub[0])){
 			case "play":
 			case "p":
				if(!$sender->hasPermission("playnoteblocksong.cmd.play")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif($this->play){
					$r = Color::RED . "[PlayNBS] " . ($ir ? "Песня уже играет!" : "Song already playing");
				}elseif(count($this->songs) <= 0){
					$r = Color::RED . "[PlayNBS] " . ($ir ? "У вас нет ни одной песни" : "You don't have any song");
				}else{
					if(!$this->song instanceof SongPlayer){
						$this->song = clone $this->songs[$this->index][self::SONG];
					}
					$this->play = true;
					$r = Color::YELLOW . "[PlayNBS] " . ($ir ? "Играет песня:" : "Playing the sound: ") . $this->songs[$this->index][self::NAME];
				}
			break;
			case "stop":
			case "s":
				if(!$sender->hasPermission("playnoteblocksong.cmd.stop")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(!$this->play){
					$r = Color::RED . "[PlayNBS] " . ($ir ? "Песня не играет!" : "Song is not playing");
				}else{
					$this->play = false;
					$r = Color::YELLOW . "[PlayNBS] " . ($ir ? "Остановка песни" : "Stop the song");
				}
			break;
			case "next":
			case "n":
				if(!$sender->hasPermission("playnoteblocksong.cmd.next")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(count($this->songs) <= 0){
					$r = Color::RED . "[PlayNBS] " . ($ir ? "У вас нет ни одной песни!" : "You don't have any song");
				}else{
					if(!isset($this->songs[$this->index + 1])){
						$this->index = 0;
					}else{
						$this->index++;
					}
					$this->song = clone $this->songs[$this->index][self::SONG];
					$this->getLogger()->notice(Color::AQUA . "Play next song: " . $this->songs[$this->index][self::NAME]);
					$r = Color::YELLOW . "[PlayNBS] " . ($ir ? "Играет следующая песня: " : "Play next song: ") . $this->songs[$this->index][self::NAME];
				}
			break;
			case "prev":
			case "pr":
				if(!$sender->hasPermission("playnoteblocksong.cmd.prev")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(count($this->songs) <= 0){
					$r = Color::RED . "[PlayNBS] " . ($ir ? "У вас нет ни одной песни" : "You don't have any song");
				}else{
					if(!isset($this->songs[$this->index - 1])){
						$this->index = 0;
					}else{
						$this->index--;
					}
					$this->song = clone $this->songs[$this->index][self::SONG];
					$this->getLogger()->notice(Color::AQUA . "Play prev song: " . $this->songs[$this->index][self::NAME]);
					$r = Color::YELLOW . "[PlayNBS] " . ($ir ? "Играет предыдущая песня: " : "Play prev song: ") . $this->songs[$this->index][self::NAME];
				}
			break;
			case "shuffle":
			case "sh":
				if(!$sender->hasPermission("playnoteblocksong.cmd.shuffle")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(count($this->songs) <= 0){
					$r = Color::RED . "[PlayNBS] " . ($ir ? "У вас нет ни одной песни" : "You don't have any song");
				}else{
					shuffle($this->songs);
					$this->index = 0;
					$this->song = clone $this->songs[$this->index][self::SONG];
					$this->getLogger()->notice(Color::AQUA . "Song list is shuffled. Now song: " . $this->songs[$this->index][self::NAME]);
					$r = Color::YELLOW . "[PlayNBS] " . ($ir ? "Список песен перемешан. Сейчас играет: " : "Song list is shuffled. Now song: ") . $this->songs[$this->index][self::NAME];
				}
			break;
			case "list":
			case "l":
				if(!$sender->hasPermission("playnoteblocksong.cmd.list")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(count($this->songs) <= 0){
					$r = Color::RED . "[PlayNBS] " . ($ir ? "У вас нет ни одной песни" : "You don't have any song");
				}else{
					$lists = array_chunk($this->songs, 5);
					$r = Color::YELLOW . "[PlayNBS] " . ($ir ? "Список песен (Страница: " : "Song list (Page: ") . ($page = min(isset($sub[1]) && is_numeric($sub[1]) && isset($lists[$sub[1] - 1]) ? $sub[1] : 1, count($lists))). "/" . count($lists) . ") (" . count($this->songs) . ")";
					if(isset($lists[--$page])){
						foreach($lists[$page] as $key => $songData){
							$r .= "\n" . Color::GOLD . "    [" . (($page * 5 + $key) + 1) .  "] " . $songData[self::NAME];
						}
					}
				}
			break;
			case "reload":
			case "r":
				if(!$sender->hasPermission("playnoteblocksong.cmd.reload")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}else{
					$this->loadSong();
					$r = Color::YELLOW . "[PlayNBS] " . ($ir ? "Список песен перезагружен" : "Reloaded songs.");
				}
			break;
			default:
				return false;
			break;
		}
		if(isset($r)){
			$sender->sendMessage($r);
		}
		return true;
	}

	public function loadSong(){
		$this->songs = [];
		$logger = $this->getLogger();
		@mkdir($folder = $this->getDataFolder());
		$opendir = opendir($folder);
		$logger->notice(Color::AQUA . "Load song...");
		while(($file = readdir($opendir)) !== false){
			if(($pos = stripos($file, ".nbs")) !== false){
				$this->songs[] = [new SongPlayer($this, $folder . $file), $name = substr($file, 0, $pos)];
				$logger->notice(Color::AQUA . "$name is loaded");
			}
		}
		if(count($this->songs) >= 1){
			$logger->notice(Color::AQUA . "Load complete");
		}else{
			$logger->notice(Color::DARK_RED . "You don't have song");			
			$logger->notice(Color::DARK_RED . "Please put in the song to $folder");			
		}
	}

	public function playSong(){
		if($this->play){
			if($this->song === null || $this->song->isStop()){
				if(!isset($this->songs[$this->index + 1])){
					$this->index = 0;
				}else{
					$this->index++;
				}
				$this->song = clone $this->songs[$this->index][self::SONG];
				$this->getLogger()->notice(Color::AQUA . "Play next song : " . $this->songs[$this->index][self::NAME]);
			}
			$this->song->onRun();
		}
	}

	public function sendSound($pitch, $type = NoteblockSound::INSTRUMENT_PIANO){
		foreach($this->getServer()->getOnlinePlayers() as $player){
            $pk = new UpdateBlockPacket();
            $pk->x = $player->x;
            $pk->y = $player->y + 4;
            $pk->z = $player->z;
            $pk->blockId = 25;
            $pk->blockData = 0;
            $pk->flags = UpdateBlockPacket::FLAG_ALL;
            $player->dataPacket($pk);
            $player->level->addSound(new NoteblockSound(new Vector3($player->x, $player->y + 4, $player->z), $type, $pitch), array($player));
            $pk1 = clone $pk;
            $pk1->blockId = $player->level->getBlockIdAt($pk->x, $pk->y, $pk->z);
            $pk1->blockData = $player->level->getBlockDataAt($pk->x, $pk->y, $pk->z);
            $player->dataPacket($pk1);
		}
	}

	public function getPlaySongName(){
		if(!isset($this->songs[$this->index][self::NAME])){
			return null;
		}else{
			return $this->songs[$this->index][self::NAME];
		}
	}

	public function isRussian(){
		return $this->getServer()->getLanguage()->getName() == "Русский";
	}
}