<?php

namespace WebSocket\Application; #Set Namespace
date_default_timezone_set('America/New_York'); #Set Time Zone

class QubApplication extends Application
{

	//Initialize Server State Variables
	private $_clients = array();
	private $_nicknames = array();
	private $_locations = array();
	private $_games = array();
	private $_floodProtection = array();

	//Handle Client Connections
	public function onConnect($client)
	{
		$id = $client->getClientId();
		$this->_clients[$id] = $client;
		$this->_nicknames[$id] = 'Anonymous'; #Nick Anonymous
		$this->_locations[$id] = 'main'; #Lobby
		$this->_actionHeaders('',$client); #Send Headers
		
		return true;
	}

	//Handle Client Disconnects
	public function onDisconnect($client)
	{
		$id = $client->getClientId();
		
		if (!isset($this->_nicknames[$id]) and !isset($this->_clients[$id]) and !isset($this->_locations[$id])) #Already Disconnected
		{
			return true;
		}
		
		//Boilerplate Code
		$clientLoc = $this->_locations[$id];
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
		
		//Execute If Client In Game
		if ($this->_locations[$id] != 'main')
		{
			if (isset($this->_games[$gameNumber]['state']['isReading']) and $this->_games[$gameNumber]['state']['isReading'])
			{
				$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
				
				foreach ($usersID as $clientsID)
				{
					if ($clientsID != $id)
					{
						$this->_clients[$clientsID]->send($this->_encodeData('read', '')); #Induce Read, if Buzzed
					}
				}
			}
			
			$this->_actionLeave('', $client, true); #Leave Game With Disconnect Header
			$this->_gamePing($gameNumber); #Ping For Safety, Consistency
		}
		
		unset($this->_nicknames[$id]);
		unset($this->_clients[$id]); 		
		unset($this->_locations[$id]);
		
		return true;
	}

	//Deal With Incoming Commands
	public function onData($data, $client)
	{
		$decodedData = $this->_decodeData($data);		
		if($decodedData === false){ return false; }
		
		$actionName = '_action' . ucfirst(strtolower(($decodedData['action']))); #Parse Action	
		$actionNameWO = htmlentities(strtolower(($decodedData['action']))); #Parse Action
		$clientID = $client->getClientId();
		$decodedData = implode(' ', $decodedData['data']);

		//Deal With Lengthy Commands
		if (strlen($decodedData) > 300 or strlen($actionName) > 40){
			$client->send($this->_encodeData('notice', 'One or more parts of your command were too long.<br>'));
			return false;
		}
		
		//NO-SPAM
		if($this->_floodCheck($clientID) === true)
		{
			$client->send($this->_encodeData('notice', 'Please do not flood the server!<br>'));
			return false;
		}
		
		//Execute Client Method
		if(method_exists($this, $actionName))
		{			
			echo ('User ' . $this->_nicknames[$clientID] . ' performed ' . $actionNameWO . ': ' . strval(memory_get_usage()) . "\n"); 
			call_user_func(array($this, $actionName), $decodedData, $client);
		}
		
		//Command Not Found
		else
		{
			$client->send($this->_encodeData('default', 'Command ' . $actionNameWO . ' not found.<br>Type \'help\' for help.<br>'));
		}
	}
	
	//Start New Game
	private function _actionGame($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		
		//Location Must Be Lobby
		if ($this->_locations[$clientID] != 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.'));
			return false;
		}
		
		//Nick Must Be Set
		if ($clientNick == 'Anonymous')
		{
			$client->send($this->_encodeData('notice', 'You must have a nickname to start a game.'));
			return false;
		}
		
		$data = explode(' ', $data);
		
		//Game Limit
		if (count($this->_games) >= 5)
		{
			$client->send($this->_encodeData('notice', 'Please join a game in progress.<br>'));
			$client->send($this->_encodeData('notice', 'At this time, the server supports a maximum of five games.<br>'));
			return false;
		}
		
		$position = 0;
		
		//Initialize Game Variables
		while (isset($this->_games[$position])){ $position += 1; }
		$this->_games[$position] = array(
			'parameters' => array(), 
			'users' => array(), 
			'scores' => array(), 
			'teams' => array(), 
			'state' => array(),
			'lqueue' => array(),
			'equeue' => array());
		
		$gameNumber = $position;
		array_push($this->_games[$gameNumber]['users'], $clientID);
		
		$this->_locations[$clientID] = 'game-' . strval($gameNumber);
		$client->send($this->_encodeData('change', ''));
		
		$defaults = '';
		
		//Here Starts A Mess Of Code
		//To Set Game Parameters
		//Go If You Dare
		
		if (isset($data[0]))
		{
			switch($data[0])
			{
				case 'endless':
					$this->_games[$gameNumber]['parameters']['length'] = 'endless'; break;
				default:
					if (is_numeric($data[0]) and $data[0] >= 1)
					{ 
						$this->_games[$gameNumber]['parameters']['length'] = intval($data[0]); break;
					}
					
					else 
					{ 
						$defaults .= 'Invalid game length selected. Defaulting to Endless length.<br>'; 
						$this->_games[$gameNumber]['parameters']['length'] = 'endless'; break;
					}
			}
		}
		else 
		{ 
			$defaults .= 'Invalid game length selected. Defaulting to Endless length.<br>';
			$this->_games[$gameNumber]['parameters']['length'] = 'endless';
		}
		
		if (isset($data[1]))
		{
			switch($data[1])
			{
				case 'public':
					$this->_games[$gameNumber]['parameters']['type'] = 'public'; break;
				default:
					if ($data[1] == 'private'){ $defaults .= 'Private games are not supported at this time.<br>'; }
					else { $defaults .= 'Invalid game type selected. Defaulting to Public type.<br>'; }
					$this->_games[$gameNumber]['parameters']['type'] = 'public'; break;
			}
		}
		else
		{
			$defaults .= 'Invalid game type selected. Defaulting to Public type.<br>';
			$this->_games[$gameNumber]['parameters']['type'] = 'public';
		}
		
		if (isset($data[2]))
		{
			switch($data[2])
			{
				case 'friendly':
					$this->_games[$gameNumber]['parameters']['level'] = 'friendly'; break;
				default:
					if ($data[2] == 'competitive'){ $defaults .= 'Competitive games are not supported at this time.<br>'; }
					else { $defaults .= 'Invalid game level selected. Defaulting to Friendly level.<br>'; }
					$this->_games[$gameNumber]['parameters']['level'] = 'friendly'; break;
			}
		}
		else 
		{ 
			$defaults .= 'Invalid game level selected. Defaulting to Friendly level.<br>';
			$this->_games[$gameNumber]['parameters']['level'] = 'friendly';
		}
		
		if (isset($data[3]))
		{
			switch($data[3])
			{
				case 'solo':
					$this->_games[$gameNumber]['parameters']['style'] = 'solo'; break;
				default:
					if ($data[3] == 'team'){ $defaults .= 'Team games are not supported at this time.<br>'; }
					else { $defaults .= 'Invalid game style selected. Defaulting to Solo style.<br>'; }
					$this->_games[$gameNumber]['parameters']['style'] = 'solo'; break;
			}
		}
		else 
		{ 
			$defaults .= 'Invalid game style selected. Defaulting to Solo style.<br>';
			$this->_games[$gameNumber]['parameters']['style'] = 'solo';
		}
		
		$client->send($this->_encodeData('notice', $defaults));
		$this->_actionHeaders('', $client);
		return true;
	}
	
	//Start Loaded Game
	private function _actionStart($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		//Location Must Be Room
		if ($this->_locations[$clientID] == 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.'));
			return false;
		}
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
		
		//Check If Game Started, Using Position State Variable
		if (isset($this->_games[$gameNumber]['state']['position']))
		{
			$client->send($this->_encodeData('notice', 'This game is already in progress.'));
			return false;
		}

		$client->send($this->_encodeData('notice', 'Game successfully started.'));
		$this->_games[$gameNumber]['state']['position'] = 0;
		$this->_gameRun($gameNumber);
		return true;
	}
	
	//List Games in Progress
	private function _actionList($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		
		//Location Must Be Lobby
		if ($this->_locations[$clientID] != 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.'));
			return false;
		}
		
		//List Rooms
		if (count($this->_games) != 0)
		{
			$list = 'Type \'join [number]\' to enter a particular game room.<br><br>';
			
			foreach ($this->_games as $key => $value)
			{
				$list = $list . 'Game Room #' . strval(intval($key)+1);
				
				$list = $list . ' - ' . ucfirst(strval($this->_games[$key]['parameters']['type']));
				$list = $list . ' - ' . ucfirst(strval($this->_games[$key]['parameters']['length']));
				$list = $list . ' - ' . ucfirst(strval($this->_games[$key]['parameters']['level']));
				$list = $list . ' - ' . ucfirst(strval($this->_games[$key]['parameters']['style']));
				
				$list = $list . '<br>';
			}
		}
		
		//No Games Found
		else
		{
			$list = 'No games found! You can start a game using the \'game\' command.<br>';
		}
		
		$client->send($this->_encodeData('list', $list));
		return true;
	}
	
	//Leave Game Room
	private function _actionLeave($data, $client, $disconnect = false)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		
		//Location Must Be Room
		if ($this->_locations[$clientID] == 'main' and !$disconnect)
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.'));
			return false;
		}
		
		//Code To Prompt Client Change
		if (!$disconnect) 
		{
			$client->send($this->_encodeData('leave', ''));
		}
		
		$currentLoc = $this->_locations[$clientID];
		$currentLoc = intval(substr($currentLoc,5,strlen($currentLoc)-5));
		
		$toUs = array_search($clientID, $this->_games[$currentLoc]['users']);
		array_splice($this->_games[$currentLoc]['users'], $toUs, 1);
		
		//If Game Is Not Started, Just Spit It Out
		if (!isset($this->_games[$currentLoc]['state']['position']))
		{
			$usersID = array_keys($this->_locations, 'game-' . strval($currentLoc));
			$notItem = 'User ' . $clientNick . ' has left the room.<br>';
				
			foreach ($usersID as $clientsID)
			{
				if ($clientsID != $clientID)
				{
					$this->_clients[$clientsID]->send($this->_encodeData('notice', $notItem));
				}
			}
		}
		
		//Otherwise, Enqueue The Leave
		else
		{
			array_push($this->_games[$currentLoc]['lqueue'], 'User ' . $clientNick . ' has left the room.<br>');
		}
			
		//Check If Game Is Empty	
		if (count($this->_games[$currentLoc]['users']) == 0)
		{
			$this->_gameDestroy($currentLoc);
		}
		
		//Works As If Leaving User Negged
		if (isset($this->_games[$currentLoc]['state']['isReading']) and $this->_games[$currentLoc]['state']['isReading'])
		{
			if (count($this->_games[$currentLoc]['state']['negs']) >= count($this->_games[$currentLoc]['users']))
			{
				$this->_gameSkip($currentLoc);
			}
		}
		
		$this->_locations[$clientID] = 'main';
		
		if (!$disconnect)
		{
			$this->_actionHeaders('', $client);
		}
		
		return true;
	}
	
	//Join Game Room
	private function _actionJoin($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		
		//Location Must Be Lobby
		if ($this->_locations[$clientID] != 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.'));
			return false;
		}
		
		//Nick Must Be Set
		if ($clientNick == 'Anonymous')
		{
			$client->send($this->_encodeData('notice', 'You must have a nickname to join a game.'));
			return false;
		}
		
		$data = explode(' ', $data);
		
		//Game Must Exist To Join
		if (!isset($data[0]) or !is_numeric($data[0]) or !isset($this->_games[intval($data[0])-1]))
		{
			$client->send($this->_encodeData('notice', 'You must select a valid game to join.'));
			return false;
		}
		
		//Limit User Count
		if (count($this->_games[intval($data[0])-1]['users']) > 10)
		{
			$client->send($this->_encodeData('notice', 'You must join a game with fewer than ten people.'));
			return false;
		}
		
		//Join Automatically If Game Not Started
		if (!isset($this->_games[intval($data[0])-1]['state']['position']))
		{
			array_push($this->_games[intval($data[0])-1]['users'], $clientID);
			$this->_locations[$clientID] = 'game-' . strval(intval($data[0])-1);
			$usersID = array_keys($this->_locations, 'game-' . strval(intval($data[0])-1));
			
			$client->send($this->_encodeData('change', ''));
			$this->_actionHeaders('', $client);
			
			foreach ($usersID as $clientsID)
			{
				$this->_clients[$clientsID]->send($this->_encodeData('notice', 'User ' . $clientNick . ' has entered the room.<br>'));
			}
		}
		
		//Otherwise Enqueue Join Request
		else
		{			
			$timeLeft = $this->_gamePing(intval($data[0])-1);
			$length = $this->_games[intval($data[0])-1]['parameters']['length'];
			$posTemp = $this->_games[intval($data[0])-1]['state']['position'];
			
			//Don't Join Ending Game
			if (is_numeric($length))
			{
				if ($length + 1 == $posTemp)
				{
				$busy = 'The game is currently on the last question.<br>';
				$busy = $busy . 'Please start or join a different game.<br><br>';
				
				$client->send($this->_encodeData('notice', $busy));
				return true;
				}
			}
			
			if (isset($timeLeft[0]) and $timeLeft[0] == 0)
			{
				$busy = 'The game is currently waiting for a question.<br>';
				$busy = $busy . 'When ready, you will be redirected automatically.<br><br>';
				$busy = $busy . 'If the game seems stalled, please retry joining in ' . strval($timeLeft[1]) . ' seconds.<br>';
				$busy = $busy . 'There will still be read time after that, but this will serve as a pinging tool.<br>';
			}
			
			else if (isset($timeLeft[0]) and $timeLeft[0] == 1)
			{
				$busy = 'The game is currently in the middle of a question.<br>';
				$busy = $busy . 'When ready, you will be redirected automatically.<br>';
				$busy = $busy . 'If the game seems hung, please retry joining in ' . strval($timeLeft[1]) . ' seconds.<br>';
			}
			
			else{
				return true;
			}
			
			$client->send($this->_encodeData('notice', $busy));
			$isIn = false;
			
			foreach ($this->_games[intval($data[0])-1]['equeue'] as $key => $entry)
			{
				if ($entry[0] == $clientID)
				{
					$this->_games[intval($data[0])-1]['equeue'][$key] = array($clientID, 'User ' . $clientNick . ' has entered the room.<br>');
				}
				
				$isIn = true;
			}
			
			if(!$isIn)
			{
				array_push($this->_games[intval($data[0])-1]['equeue'], array($clientID, 'User ' . $clientNick . ' has entered the room.<br>'));
			}
		}

		return true;
	}

	//Show Nick And Location
	private function _actionStatus($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		if ($clientLoc == 'main') { $clientLoc = 'the Lobby'; }
		else if (substr($clientLoc,0,5) == 'game-') { $clientLoc = 'Game Room #' . strval(intval(substr($clientLoc,5,strlen($clientLoc)-5))+1); }
		
		$status = 'Your current nickname is ' . $clientNick . '.<br>';
		$status = $status . 'Your current location is ' . $clientLoc . '.<br>';
		
		$client->send($this->_encodeData('status', $status));
		return true;
	}
	
	//Show Current Location Information
	private function _actionHeaders($data, $client)
	{
		$clientID = $client->getClientId();
		
		if ($this->_locations[$clientID] == 'main')
		{
			$headers = 'Welcome to Qub.<br><br>This is important -- gameplay has changed.<br>';
			$headers = $headers . 'Now you buzz in first, then get a chance to answer.<br><br>';
			$headers = $headers . 'Type \'help\' for help.<br>Type \'headers\' to see these messages again.';
			$headers = $headers . '<br><br>Today is ' . date('F j, Y') . ', and the time is ' . date('g:i a') . '.';
			
			if (count($this->_clients) == 1)
			{
				$headers = $headers . '<br>There is currently ' . strval(count($this->_clients)) . ' user online.<br>';
			}
			
			else
			{	
				$headers = $headers . '<br>There are currently ' . strval(count($this->_clients)) . ' users online.<br>';
			}
			
			$headers = $headers . '<br>Questions snagged from (thanks to) QuizbowlDB.com.<br>';
		}
		
		else
		{
			$gameNumber = intval(substr($this->_locations[$clientID],5,strlen($this->_locations[$clientID])-5));
			$headers = 'Welcome to Game Room #' . strval($gameNumber+1) . '.<br>Users Here: ';
			
			foreach ($this->_games[$gameNumber]['users'] as $userID)
			{
				$headers = $headers . $this->_nicknames[$userID] . ', ';
			}
			
			$headers = substr($headers,0,strlen($headers)-2);
			$headers = $headers . '<br><br>Game Length: ' . ucfirst(strval($this->_games[$gameNumber]['parameters']['length'])) . '<br>';
			$headers = $headers . 'Game Type: ' . ucfirst(strval($this->_games[$gameNumber]['parameters']['type'])) . '<br>';
			$headers = $headers . 'Game Level: ' . ucfirst(strval($this->_games[$gameNumber]['parameters']['level'])) . '<br>';
			$headers = $headers . 'Game Style: ' . ucfirst(strval($this->_games[$gameNumber]['parameters']['style'])) . '<br>';
		}
		
		$client->send($this->_encodeData('headers', $headers));
		return true;
	}
	
	//Send Global Chat For Location
	private function _actionChat($data, $client)
	{
		$clientID = $client->getClientId();
		$data = htmlentities(strip_tags($data));
		
		if (strlen(str_replace(array('\n', '\r', '\t', ' '), '', $data)) == 0)
		{
			$client->send($this->_encodeData('notice', 'Your chat must have content.<br>'));
			return false;
		}
		
		$data = '['.$this->_nicknames[$clientID].'] ' . $data; 
	
		//Send Appropriate Color Based On User
		foreach($this->_clients as $clientRec)
		{
			if ($this->_locations[$clientID] == $this->_locations[$clientRec->getClientId()])
			{
				if ($clientRec != $client)
				{
					if ($this->_nicknames[$clientID] == 'admin')
					{
						$clientRec->send($this->_encodeData('achat', $data));
					}
					
					else
					{
						$clientRec->send($this->_encodeData('chat', $data));
					}
				}
				
				else
				{
					$clientRec->send($this->_encodeData('schat', $data));
				}
			}
		}
		
		return true;
	}
	
	//Private Message Specific User
	private function _actionPm($data, $client)
	{
		$data = explode(' ', $data);
	
		$clientID = $client->getClientId();
		$user = array_shift($data);
		$sendTo = array_search($user,$this->_nicknames);
		
		//User Must Exist, Can't Be Anonymous
		if (!$sendTo or $user == 'Anonymous')
		{
			$client->send($this->_encodeData('notice', 'You must select a valid recipient to PM.<br>'));
			return false;
		}
		
		$data = htmlentities(strip_tags(implode(' ', $data)));
		
		if (strlen(str_replace(array('\n', '\r', '\t', ' '), '', $data)) == 0)
		{
			$client->send($this->_encodeData('notice', 'Your chat must have content.<br>'));
			return false;
		}
		
		$recp = '[' . $this->_nicknames[$clientID] . '] ' . $data;
		$sndr = '[' . $this->_nicknames[$clientID] . ' &#8594; ' .$user . '] ' . $data;
	
		$this->_clients[$sendTo]->send($this->_encodeData('pm', $recp));
		$client->send($this->_encodeData('pm', $sndr));
		return true;
	}
	
	//Change User Nickname
	private function _actionNick($data, $client)
	{
		$clientID = $client->getClientId();
		$nick = preg_replace('#[^a-z0-9]#i', '', $data);
		
		//The Following Checks
		//Are For Nickname Validity
		
		if(empty($nick))
		{
			$client->send($this->_encodeData('notice', 'Invalid nickname selected.'));
			return false;
		}
		
		if(strlen($nick) > 20)
		{
			$client->send($this->_encodeData('notice', 'Nicknames must be fewer than twenty characters.'));
			return false;
		}
		
		if(in_array($nick, $this->_nicknames) and $nick != 'Anonymous')
		{
			$client->send($this->_encodeData('notice', 'Sorry, your chosen nickname is already in use.'));
			return false;
		}
		
		//Protect The Admin!
		$begin = strtolower(substr($nick, 0, 5));
		if($begin == 'admin')
		{
			$split = explode(' ', $data);
			
			if (isset($split[1]) and $split[1] == 'nimda') 
			{
				$nick = 'admin';
			}
			
			else 
			{  
				$client->send($this->_encodeData('notice', 'Administrator usernames are password protected.'));
				return false;
			} 
		}
		
		$this->_nicknames[$clientID] = $nick;
		$client->send($this->_encodeData('notice', 'Your nickname is now ' . $nick . '.'));
		return true;
	}
	
	//NO-SPAM
	private function _floodCheck($clientID)
	{
		if(!isset($this->_floodProtection[$clientID]))
		{
			$this->_floodProtection[$clientID] = array(
				'last_msg' => time(),
				'count' => 1
			);
			return false;
		}
		
		//This Increments Messages With Less Than Ten Seconds Of Separation
		if(time() - $this->_floodProtection[$clientID]['last_msg'] < 10)
		{
			if($this->_floodProtection[$clientID]['count'] === 10)
			{
				return true;
			}
		
			else
			{
				$this->_floodProtection[$clientID]['count']++;
				return false;
			}
		}
		
		else
		{
			$this->_floodProtection[$clientID] = array(
				'last_msg' => time(),
				'count' => 1
			);
			return false;
		}
	}
	
	//Continue To Question
	private function _actionContinue($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		//Location Must Be Room
		if ($this->_locations[$clientID] == 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.<br>'));
			return false;
		}
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
	
		//Context Must Be Appropriate
		if (!isset($this->_games[$gameNumber]['state']['continues']))
		{
			$client->send($this->_encodeData('notice', 'This command does not apply now.<br>'));
			return false;
		}
		
		if ($this->_games[$gameNumber]['state']['isContinued'])
		{
			return false;
		}
		
		//Add Non-Duplicate Continues To Tracker
		if (time() - $this->_games[$gameNumber]['state']['runTime'] >= 5 and $this->_games[$gameNumber]['state']['isNexted'])
		{
			if (!in_array($clientID, $this->_games[$gameNumber]['state']['continues']))
			{
			array_push($this->_games[$gameNumber]['state']['continues'], $clientID);
			}
		}
		
		else
		{
			return false;
		}

		//Question Processing
		if (count($this->_games[$gameNumber]['state']['continues']) >= count($this->_games[$gameNumber]['users']))
		{	
			$this->_games[$gameNumber]['state']['isContinued'] = true;
		
			$URI = 'http://ec2-107-20-11-96.compute-1.amazonaws.com/api/tossup.search?params[difficulty]=HS&params[random]=true';
			$questionInfo = json_decode(file_get_contents($URI));
			
			$this->_games[$gameNumber]['state']['QID'] = $questionInfo->offset;
			$this->_games[$gameNumber]['state']['answer'] = $questionInfo->results[0]->answer;
			
			$source = $questionInfo->results[0]->tournament;
			$year = $questionInfo->results[0]->year;
			$category = $questionInfo->results[0]->category;
			$question = $questionInfo->results[0]->question;
			
			$this->_games[$gameNumber]['state']['question'] = $question;
		
			$info = 'Question Category: ' . $category . '<br>';
			$info = $info . 'Question Source: ' . $source . '<br>';
			$info = $info . 'Tournament Year: ' . $year . '<br>';
		
			$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
			
			foreach ($usersID as $clientsID)
			{
				$this->_clients[$clientsID]->send($this->_encodeData('notice', $info));
				$this->_clients[$clientsID]->send($this->_encodeData('question', $question));
			}
			
			$this->_games[$gameNumber]['state']['runTime'] = time();
			$this->_games[$gameNumber]['state']['isReading'] = true;
		}
		
		return true;
	}
	
	//Force Continue Externally
	private function _gameContinue($gameNumber)
	{
		if ($this->_games[$gameNumber]['state']['isContinued'])
		{
			return false;
		}
	
		$this->_games[$gameNumber]['state']['isContinued'] = true;
	
		$URI = 'http://ec2-107-20-11-96.compute-1.amazonaws.com/api/tossup.search?params[difficulty]=HS&params[random]=true';
		$questionInfo = json_decode(file_get_contents($URI));
		
		$this->_games[$gameNumber]['state']['QID'] = $questionInfo->offset;
		$this->_games[$gameNumber]['state']['answer'] = $questionInfo->results[0]->answer;
		
		$source = $questionInfo->results[0]->tournament;
		$year = $questionInfo->results[0]->year;
		$category = $questionInfo->results[0]->category;
		$question = $questionInfo->results[0]->question;
		
		$this->_games[$gameNumber]['state']['question'] = $question;
	
		$info = 'Question Category: ' . $category . '<br>';
		$info = $info . 'Question Source: ' . $source . '<br>';
		$info = $info . 'Tournament Year: ' . $year . '<br>';
	
		$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
		
		foreach ($usersID as $clientsID)
		{
			$this->_clients[$clientsID]->send($this->_encodeData('notice', $info));
			$this->_clients[$clientsID]->send($this->_encodeData('question', $question));
		}
		
		$this->_games[$gameNumber]['state']['runTime'] = time();
		$this->_games[$gameNumber]['state']['isReading'] = true;
		
		return true;
	}
	
	//User Buzz Action
	private function _actionBuzz($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		//Location Must Be Room
		if ($this->_locations[$clientID] == 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.<br>'));
			return false;
		}
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
	
		//Context Must Be Appropriate
		if(!isset($this->_games[$gameNumber]['state']['isReading']) or !$this->_games[$gameNumber]['state']['isReading'])
		{
			$client->send($this->_encodeData('notice', 'This command does not apply now.<br>'));
			return false;
		}
		
		//No Duplicate Buzzes
		if(!empty($this->_games[$gameNumber]['state']['buzzer']))
		{
			if (in_array($this->_games[$gameNumber]['state']['buzzer'], $this->_games[$gameNumber]['users']))
			{
				return false;
			}
		}
		
		$this->_games[$gameNumber]['state']['buzzer'] = $clientID;
		$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
		
		//Send Buzz To All Users Appropriately
		foreach ($usersID as $clientsID)
		{
			if ($clientsID != $clientID)
			{
				$this->_clients[$clientsID]->send($this->_encodeData('buzz', $this->_nicknames[$clientID]));
			}
			
			else
			{
				$this->_clients[$clientsID]->send($this->_encodeData('sbuzz', ''));
			}
		}
	
	}
	
	//Invoked When Client Sends Answer To Buzz
	private function _actionAnswer($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		//Location Must Be Room
		if ($this->_locations[$clientID] == 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.<br>'));
			return false;
		}
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
	
		//Context Must Be Appropriate
		if(!$this->_games[$gameNumber]['state']['isReading'] or empty($this->_games[$gameNumber]['state']['buzzer']))
		{
			$client->send($this->_encodeData('notice', 'This command does not apply now.<br>'));
			return false;
		}
		
		$this->_games[$gameNumber]['state']['buzzer'] = null;

		$data = explode(' ', $data);
		$score = array_pop($data);
		
		if (!isset($data) or empty($data))
		{
			$answer = '';
		}
		
		else
		{
			$answer = urlencode(implode(' ', $data));
		}
		
		$correct = urlencode($this->_games[$gameNumber]['state']['answer']);
		
		$URI = 'http://ec2-107-20-11-96.compute-1.amazonaws.com/api/answer.check?canon=' . $correct . '&answer=' . $answer;
		$isRight = json_decode(file_get_contents($URI))->value; #Check Answer Validity
		
		//Echo Stats And Display For Correct Answers
		if ($isRight)
		{
			array_push($this->_games[$gameNumber]['state']['correct'], array($clientID));
			$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
			$isTaken = $this->_games[$gameNumber]['state']['isTaken'];
		
			if ($isTaken)
			{
				return false;
			}
		
			$this->_games[$gameNumber]['state']['isTaken'] = true;
			$this->_games[$gameNumber]['state']['isReading'] = false;
		
			foreach ($usersID as $clientsID)
			{
				$this->_clients[$clientsID]->send($this->_encodeData('display', ''));
			}
			
			$client->send($this->_encodeData('notice', 'Correct answer. Great job!<br>'));
			$stats = 'Correct Player: ' . $this->_nicknames[$clientID] . ' [' . strval($score) . ']<br>';

			if (count($this->_games[$gameNumber]['state']['negs']) > 0)
			{
				$stats = $stats . 'Wrong Players: ';
				foreach ($this->_games[$gameNumber]['state']['negs'] as $negger)
				{
					if (isset($this->_nicknames[$negger[0]]))
					{
						$stats = $stats . $this->_nicknames[$negger[0]] . ' [' . $negger[1] . '], ';
					}
				}
				$stats = substr($stats, 0, strlen($stats)-2);
				$stats = $stats . '<br>';
			}
			
			$stats = $stats . 'Right Answer: ' . $this->_games[$gameNumber]['state']['answer'] . '<br>';
			
			foreach ($usersID as $clientsID)
				{
					$this->_clients[$clientsID]->send($this->_encodeData('stats', $stats));
				}
				
			$this->_games[$gameNumber]['state']['inQuestion'] = false;
			$this->_gameRun($gameNumber);
		}
		
		//Wrong Handler
		else
		{
			array_push($this->_games[$gameNumber]['state']['negs'], array($clientID, $score));
			$wrong = 'Your answer was incorrect. Please wait for the next question.<br>';
			$wrong = $wrong . 'If the game does not continue within a minute or so, type \'ping\'.<br>';
			$client->send($this->_encodeData('wrong', $wrong));
			
			//See If Everyone Negged, Deal Appropriately
			if(count($this->_games[$gameNumber]['state']['negs']) >= count($this->_games[$gameNumber]['users']))
			{
				$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));						
				$isTaken = $this->_games[$gameNumber]['state']['isTaken'];
		
				if ($isTaken)
				{
					return false;
				}
		
				$this->_games[$gameNumber]['state']['isTaken'] = true;
				$this->_games[$gameNumber]['state']['isReading'] = false;

				foreach ($usersID as $clientsID)
				{
					$this->_clients[$clientsID]->send($this->_encodeData('display', ''));
				}
		
				$stats = 'Everyone negged on this question! Better luck next time.<br>';
				$stats = $stats . 'Right Answer: ' . $this->_games[$gameNumber]['state']['answer'] . '<br>';
				
				foreach ($usersID as $clientsID)
				{
					$this->_clients[$clientsID]->send($this->_encodeData('stats', $stats));
				}
				
				
				$this->_games[$gameNumber]['state']['inQuestion'] = false;
				$this->_gameRun($gameNumber);
			}
			
			//Otherwise, Treat It Normally
			else
			{
				$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
				foreach ($usersID as $clientsID)
				{
					if ($clientsID != $clientID)
					{
						$this->_clients[$clientsID]->send($this->_encodeData('read', ''));
					}
				}
			}
		}
		
		return true;
	}
	
	//Skip Question, Triggered When Question Ends
	private function _actionSkip($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
		
		//Location Must Be Room
		if ($this->_locations[$clientID] == 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.<br>'));
			return false;
		}
		
		$isTaken = $this->_games[$gameNumber]['state']['isTaken'];
		
		//Question Must Not Be Taken Already
		if ($isTaken)
		{
			return false;
		}
		
		//When Skipped, Do The Following
		//Regardless Of Other Players
		//And Their Game States
		
		$this->_games[$gameNumber]['state']['isTaken'] = true;
		$this->_games[$gameNumber]['state']['isReading'] = false;
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
		$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
	
		foreach ($usersID as $clientsID)
		{
			$this->_clients[$clientsID]->send($this->_encodeData('display', ''));
		}
		
		$stats = 'Everyone skipped or negged this question! Better luck next time.<br>';
		$stats = $stats . 'Right Answer: ' . $this->_games[$gameNumber]['state']['answer'] . '<br>';
		
		foreach ($usersID as $clientsID)
		{
			$this->_clients[$clientsID]->send($this->_encodeData('stats', $stats));
		}
		
		$this->_games[$gameNumber]['state']['inQuestion'] = false;
		$this->_gameRun($gameNumber);
		return true;
	}
	
	//Force Skip Externally
	private function _gameSkip($gameNumber)
	{	
		$isTaken = $this->_games[$gameNumber]['state']['isTaken'];
		
		if ($isTaken)
		{
			return false;
		}
		
		$this->_games[$gameNumber]['state']['isTaken'] = true;
		$this->_games[$gameNumber]['state']['isReading'] = false;
		
		$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
	
		foreach ($usersID as $clientsID)
		{
			$this->_clients[$clientsID]->send($this->_encodeData('display', ''));
		}
		
		$stats = 'Everyone skipped or negged this question! Better luck next time.<br>';
		$stats = $stats . 'Right Answer: ' . $this->_games[$gameNumber]['state']['answer'] . '<br>';
		
		foreach ($usersID as $clientsID)
		{
			$this->_clients[$clientsID]->send($this->_encodeData('stats', $stats));
		}
		
		$this->_games[$gameNumber]['state']['inQuestion'] = false;
		$this->_gameRun($gameNumber);
		return true;
	}
	
	//Ping Players In Question Externally
	private function _actionPing($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
		
		//Location Must Be Room
		if ($this->_locations[$clientID] == 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.'));
			return false;
		}
		
		//Make Sure Reading Is Going On
		if (isset($this->_games[$gameNumber]['state']['isReading']) and $this->_games[$gameNumber]['state']['isReading'])
		{
			$now = time();
			$time = $this->_games[$gameNumber]['state']['runTime'];
			$readTime = count(explode(' ', $this->_games[$gameNumber]['state']['question']))*.350 + 20;
			
			if (($now - $time) > ceil($readTime))
			{
				$this->_actionSkip($data, $client);
			}
			
			else
			{
				$pingBack = 'You cannot ping yet.<br>Please wait ' . strval(ceil($readTime) - ($now - $time) + 1) . ' seconds.<br>';
				$client->send($this->_encodeData('notice', $pingBack));
				
				return false;
			}
		}
		
		else
		{
			$client->send($this->_encodeData('notice', 'This command does not apply now.'));
			return false;
		}
		
		return true;
	}
	
	//Ping Externally
	private function _gamePing($gameNumber)
	{
		//Reading Question State
		if (isset($this->_games[$gameNumber]['state']['isReading']) and $this->_games[$gameNumber]['state']['isReading'])
		{
			$now = time();
			$time = $this->_games[$gameNumber]['state']['runTime'];
			$readTime = count(explode(' ', $this->_games[$gameNumber]['state']['question']))*.350 + 20;
			
			if (($now - $time) > ceil($readTime))
			{
				$this->_gameSkip($gameNumber);
				return true;
			}
			
			$estJoin = ceil($readTime) - ($now - $time) + 1;
			return array(1, ceil($estJoin));
		}
		
		//Chilling Before A Question State
		else if (isset($this->_games[$gameNumber]['state']['isNexted']) and !$this->_games[$gameNumber]['state']['isNexted'])
		{
			$now = time();
			$time = $this->_games[$gameNumber]['state']['startTime'];
			
			if(($now - $time) > 120)
			{
				$this->_gameNext($gameNumber);
				
				$now_d = time();
				$time_d = $this->_games[$gameNumber]['state']['runTime'];
				
				$estJoin_d = 120*.350 + 25 - ($now_d - $time_d);
				return array(1, ceil($estJoin_d));
			}
			
			$estJoin = 120 - ($now - $time) + 1;
			return array(0, ceil($estJoin));
		}
		
		//Waiting For Question State
		else if (isset($this->_games[$gameNumber]['state']['isNexted']) and $this->_games[$gameNumber]['state']['isNexted'])
		{
			if ($this->_games[$gameNumber]['state']['isNexted'] and !$this->_games[$gameNumber]['state']['isReading'])
			{
				$now = time();
				$time = $this->_games[$gameNumber]['state']['runTime'];
			
				if (($now - $time) > 5)
				{
					$this->_gameContinue($gameNumber);
					
					$now_d = time();
					$time_d = $this->_games[$gameNumber]['state']['runTime'];
					$readTime_d = count(explode(' ', $this->_games[$gameNumber]['state']['question']))*.350 + 20;
				
					$estJoin_d = ceil($readTime_d) - ($now_d - $time_d) + 1;
					return array(1, ceil($estJoin_d));
				}
			
				$estJoin = 120*.350 + 25 - ($now - $time);
				return array(1, ceil($estJoin));
			}
		}
		
		return true;
	}
	
	//Process Game Before Questions
	private function _gameRun($gameNumber)
	{
		//Increment Question Number
		$this->_games[$gameNumber]['state']['position'] += 1;
		$length = $this->_games[$gameNumber]['parameters']['length'];
		$posTemp = $this->_games[$gameNumber]['state']['position'];

		//Process Enqueue
		foreach ($this->_games[$gameNumber]['equeue'] as $entUser)
		{
			if (isset($this->_clients[$entUser[0]]) and $this->_locations[$entUser[0]] == 'main' and !($length + 1 == $posTemp))
			{
				array_push($this->_games[$gameNumber]['users'], $entUser[0]);
				$this->_locations[$entUser[0]] = 'game-' . strval($gameNumber);
	
				$this->_clients[$entUser[0]]->send($this->_encodeData('change', ''));
				$this->_actionHeaders('', $this->_clients[$entUser[0]]);
			}
		}
		
		$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
		
		//Check If Game Over
		if($length != 'endless' and $length + 1 == $this->_games[$gameNumber]['state']['position'])
		{
			$this->_gameDestroy($gameNumber);
			foreach ($usersID as $clientsID)
			{
				$this->_locations[$clientsID] = 'main';
				$this->_clients[$clientsID]->send($this->_encodeData('finish', ''));
				$this->_clients[$clientsID]->send($this->_encodeData('notice', 'Your game is over. You are being redirected to the main lobby.<br>'));
			}
			return false;
		}
		
		//Initialize A Truckload Of State Variables
		$this->_games[$gameNumber]['state']['runTime'] = 0;
		$this->_games[$gameNumber]['state']['startTime'] = time();
		$this->_games[$gameNumber]['state']['inQuestion'] = true;
		$this->_games[$gameNumber]['state']['isReading'] = false;
		$this->_games[$gameNumber]['state']['isTaken'] = false;
		$this->_games[$gameNumber]['state']['isNexted'] = false;
		$this->_games[$gameNumber]['state']['isContinued'] = false;
		$this->_games[$gameNumber]['state']['answer'] = null;
		$this->_games[$gameNumber]['state']['question'] = null;
		$this->_games[$gameNumber]['state']['buzzer'] = null;
		$this->_games[$gameNumber]['state']['continues'] = array();
		$this->_games[$gameNumber]['state']['negs'] = array();
		$this->_games[$gameNumber]['state']['correct'] = array();
		
		$hereLoc = 'game-' . strval($gameNumber);
		
		//Process Both Enqueues, Show Users Entering and Leaving
		foreach ($usersID as $clientsID)
		{
			foreach ($this->_games[$gameNumber]['lqueue'] as $notItem)
			{
				$this->_clients[$clientsID]->send($this->_encodeData('notice', $notItem));
			}
		
			foreach ($this->_games[$gameNumber]['equeue'] as $notItem)
			{	
				if (isset($this->_clients[$notItem[0]]) and $this->_locations[$notItem[0]] == $hereLoc and !($length + 1 == $posTemp))
				{
					$this->_clients[$clientsID]->send($this->_encodeData('notice', $notItem[1]));
				}
			}
			
			$this->_clients[$clientsID]->send($this->_encodeData('wait', ''));
		}
		
		$this->_games[$gameNumber]['equeue'] = array();
		$this->_games[$gameNumber]['lqueue'] = array();
		
		return true;
	}
	
	//Go On To Next Question
	private function _actionNext($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$clientLoc = $this->_locations[$clientID];
		
		$gameNumber = intval(substr($clientLoc,5,strlen($clientLoc)-5));
		
		//Check Location, As Usual
		if ($this->_locations[$clientID] == 'main')
		{
			$client->send($this->_encodeData('notice', 'This command does not apply here.<br>'));
			return false;
		}
		
		//Check Context, As Usual
		if (!isset($this->_games[$gameNumber]['state']['isNexted']) or $this->_games[$gameNumber]['state']['isNexted'])
		{
			$client->send($this->_encodeData('notice', 'This command does not apply now.<br>'));
			return false;
		}
		
		$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
		$this->_games[$gameNumber]['state']['runTime'] = time();
		$this->_games[$gameNumber]['state']['isNexted'] = true;
		
		//Print Time Left
		foreach ($usersID as $clientsID)
		{
			$notification = 'Question #' . strval($this->_games[$gameNumber]['state']['position']) . ' will start in 5 seconds.<br>';
			$this->_clients[$clientsID]->send($this->_encodeData('finWait', $notification));
		}
		
		return true;
	}
	
	//Externally Initiate Next Request
	private function _gameNext($gameNumber)
	{
		$usersID = array_keys($this->_locations, 'game-' . strval($gameNumber));
		$this->_games[$gameNumber]['state']['runTime'] = time();
		$this->_games[$gameNumber]['state']['isNexted'] = true;
		
		foreach ($usersID as $clientsID)
		{
			$notification = 'Question #' . strval($this->_games[$gameNumber]['state']['position']) . ' will start in 5 seconds.<br><br>';
			$notification = $notification . 'A waiting user has auto-nexted this!<br>';
			$this->_clients[$clientsID]->send($this->_encodeData('finWait', $notification));
		}
		
		return true;
	}
	
	//Delete Game From Master List
	private function _gameDestroy($gameNumber)
	{
		unset($this->_games[$gameNumber]);
		return true;
	}
	
	//Help!
	private function _actionHelp($data, $client)
	{
		$clientID = $client->getClientId();
		$clientNick = $this->_nicknames[$clientID];
		$data = explode(' ', $data);
		
		$help = 'This is the Qub user guide.<br>Please let me know if anything doesn\'t make sense.<br><br>';
		
		$help = $help . 'To get into a game: Begin either by typing \'game\' or \'list\'. The former will start a game, ';
		$help = $help . 'while the latter will list games in progress. To join a game in progress, type \'join\' followed ';
		$help = $help . 'by the game\'s number. To start a game with a finite number of questions, type \'game\' followed ';
		$help = $help . 'by the number of questions. Other options are currently in development and are disabled.<br><br>';
		
		$help = $help . 'To play in a game: After you have entered a game room, a game may or may not be in progress. If a ';
		$help = $help . 'question does not appear shortly, type \'start\' to try starting the game. Once questions begin to ';
		$help = $help . 'appear, you may type \'buzz\' to buzz in, and then your answer. Type \'leave\' to exit the game you ';
		$help = $help . 'are playing. For game info, type \'headers\'. You will be partially guided through this in-game.<br><br>';
		
		$help = $help . 'To chat with other players: Chatting to everybody in the main lobby or game rooms is as simple as ';
		$help = $help . 'typing \'chat\' followed by your message. To private message (PM) someone, type \'pm\' followed by ';
		$help = $help . 'the user\'s nickname and then your message. To change your nickname, type \'nick\' followed by your ';
		$help = $help . 'new desired nickname. The default nickname is \'Anonymous\'. Administrator nicknames are protected.<br>';
		
		$client->send($this->_encodeData('notice', $help));
	}
}

?>