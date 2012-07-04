function log(msg, color, fade) {
	if(fade){ $('#log').prepend('<span style=\'color: ' + color + ';\'>' + msg + '</span><br />').hide().fadeIn('fast'); }
	else{ $('#log').prepend('<span style=\'color: ' + color + ';\'>' + msg + '</span><br />') }
};

function status(type) {
	$('#status').removeClass().addClass(type).html(type).hide().fadeIn();
}

function postProcess(){
	$('#scroll').show();
	$('#scroll2').hide();
	$('#qs').html('');
}

function read(words){
	var startPos = wordsPos;
	
	if (wordsPos < words.length && !hasBuzzed && !hasLeft){
		$('#scroll').hide()
		$('#scroll2').show()
		$('#qs').append('<span style=\'color: purple;\'>' + words[wordsPos] + ' </span>');
		
		wordsPos += 1;
		setTimeout(function(){ read(words); }, 350);
	}
	
	function skip() {
		if (!hasBuzzed){
			hasBuzzed = true;
			$('#prompt').val('skip ' + wordsPos.toString()); $('#command').submit();
		}
	}
	
	if (startPos == words.length){
		setTimeout(skip, 5000);
	}
}

function initialize() {
	var host = 'ws://ec2-50-17-82-127.compute-1.amazonaws.com:8000/qub';
	//var host = 'ws://127.0.0.1:8000/qub';
	
	try {
		socket = new WebSocket(host);
		
		socket.onopen = function(msg){ $('#log').html(''); log('Server successfully connected.<br>', 'orange'); status('online'); };
		socket.onmessage = function(msg){ handle(JSON.parse(msg.data)); };
		socket.onclose = function(msg){ 
			status('offline'); 
			log('Client disconnected. Refresh to reconnect.<br>', 'orange');
			hasLeft = true;
			initialized = false;
			$('#scroll').show();
			$('#scroll2').hide();
		};
	}
  
	catch(ex) { 
		status('error')
		log('An error occurred in the connection.<br>Please try the newest version of a modern browser (not IE).', 'orange'); 
	}
  
	$('#prompt').focus();
}

function display(message) {
	switch(message.action){
		case 'nick':
			log('Nickname change request sent.<br>', 'green', true);
			break;
		case 'chat':
			break;
		case 'pm':
			break;
		case 'game':
			log('New game request sent.<br>', 'green', true);
			break;
		case 'join':
			log('Game join request sent.<br>', 'green', true);
			break;	
		case 'leave':
			log('Leave request sent.<br>', 'green', true);
			break;
		case 'start':
			log('Start request sent.<br>', 'green', true);
			break;
		case 'ping':
			log('Ping request sent.<br>', 'green', true);
			break;
		case 'help':
			log('Help request sent.<br>', 'green', true);
			break;
		case 'status':
			log('Status request sent.<br>', 'green', true);
			break;
		case 'headers':
			log('Header request sent.<br>', 'green', true);
			break;
		case 'list':
			log('List request sent.<br>', 'green', true);
			break;
		case 'continue':
			break;
		case 'next':
			break;
		case 'skip':
			break;
		case 'buzz':
			break;
		case 'answer':
			break;
		default:
			var data = message.data
			if (data != '') { log('Command: ' + message.action + ' ' + data.join(' ') + '<br>', 'green', true); }
			else { log('Command: ' + message.action + '<br>', 'green', true); }
			break;
	}
}

function handle(response) {
	switch(response.action){
		case 'headers':
			log(response.data, 'blue', false);
			break;
		case 'chat':
			var prev = $('#log').html();
			prev = prev.replace(/(<([^>]+)>)/ig,'');
			
			if (prev.substr(0,1) != '['){
				response.data += '<br>';
			}
			
			log(response.data, 'yellow', false);
			break;
		case 'achat':
			var prev = $('#log').html();
			prev = prev.replace(/(<([^>]+)>)/ig,'');
			
			if (prev.substr(0,1) != '['){
				response.data += '<br>';
			}
			
			log(response.data, '#00F400', false);
			break;
		case 'schat':
			var prev = $('#log').html();
			prev = prev.replace(/(<([^>]+)>)/ig,'');
			
			if (prev.substr(0,1) != '['){
				response.data += '<br>';
			}
			
			log(response.data, 'white', false);
			break;
		case 'pm':
			var prev = $('#log').html();
			prev = prev.replace(/(<([^>]+)>)/ig,'');
			
			if (prev.substr(0,1) != '['){
				response.data += '<br>';
			}
			
			log(response.data, 'pink', false);
			break;
		case 'change':
			$('#log').html('')
			break;
		case 'finish':
			function switchScreen() { $('#log').html(''); $('#prompt').val('headers'); $('#command').submit(); }
			setTimeout(switchScreen, 5000);
			break;
		case 'leave':
			hasLeft = true;
			postProcess();
			$('#log').html('');
			break;
		case 'wrong':
			postProcess();
			hasAnswered = true;
			log(response.data, 'blue', false);
			$('#prompt').focus();
			break;
		case 'display':
			postProcess();
			$('#prompt').removeAttr('disabled');
			hasAnswered = true;
			hasBuzzed = true;
			log(lastQuestion + '<br>', 'purple', false);
			break;
		case 'stats':
			log(response.data, '#00CC66', false);
			break;
		case 'question':
			hasBuzzed = false; hasLeft = false; hasAnswered = false; 
			wordsPos = 0;
			lastQuestion = response.data;
			words = response.data.split(' ');
			read(words);
			break;
		case 'read':
			hasBuzzed = false;
			$('#buzz').remove();
			$('#prompt').removeAttr('disabled');
			read(words);
			break;
		case 'buzz':
			hasBuzzed = true;
			$('#qs').append('<span id=\'buzz\' style=\'color: yellow;\'>Buzz!! </span>');
			$('#prompt').attr('disabled', true);
			break;
		case 'sbuzz':
			hasBuzzed = true;
			$('#qs').append('<span style=\'color: yellow;\'>Buzz!! </span>'); isAnswering = true;
			function timeOut() { if (hasAnswered == false) { $('#prompt').val('answer'); $('#command').submit(); } }
			setTimeout(timeOut, 5000);
			break;
		case 'wait':
			log(response.data, 'blue', false);
			var nextText = 'Type \'next\' to continue to the next question.<br>Only one user needs to do this; please be considerate.<br>';
			nextText = nextText + 'You are guaranteed two minutes of wait time.';
			log(nextText, 'green', false);
			break;
		case 'finWait':
			log(response.data, 'green', false);
			log('Input temporarily disabling.', 'green', false);
			$('#prompt').attr('disabled', true);
			cont = function() { $('#prompt').val('continue'); $('#command').submit();  $('#prompt').removeAttr('disabled'); }
			setTimeout(cont, 5000);
			break;
		default:
			log(response.data, 'blue', false);
			break;
	}
}

$(document).ready(function() {
	
	$('#log').slimScroll({ color: '#444', alwaysVisible: true, start: 'bottom', distance: 3, height: 280 });
	$('#qs').slimScroll({ color: '#444', alwaysVisible: true, start: 'bottom', distance: 3, height: 280 });

	if(!initialized){ initialize(); }
	
	$('#prompt').blur(function() {
		$('#prompt').focus(); 
	});

	$('#command').submit(function() {
		var typed = $('#prompt').val();
		if (typed.replace(/\s/g, '') != ''){
			var msg = new Object();
			
			var action = typed;
			action = action.split(' ');
			action = action[0];
			action = action.toLowerCase();
			
			var data = typed
			data = data.split(' ');
			data.shift();

			if (action == 'buzz')
			{
				hasBuzzed = true;
			}
			
			if (isAnswering)
			{
				action = 'answer';
				data = typed;
				data = data.split(' ');
				data.push(wordsPos.toString());
				isAnswering = false;
			}
			
			msg.data = data;
			msg.action = action;
			$('#prompt').val('');
			
			socket.send(JSON.stringify(msg));
			display(msg);
		}
	});
});

//WEB_SOCKET_SWF_LOCATION = 'client/swf/WebSocketMain.swf';

var initialized = false;
var hasBuzzed = false;
var hasAnswered = false;
var isAnswering = false;
var hasLeft = false;

var noSubmit = function(){}
var wordsPos = 0;
var words = '';
var lastQuestion;
var socket;