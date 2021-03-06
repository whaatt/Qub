//Add Info To Console Display
function log(msg, color, fade) {
	if(fade){ $('#log').prepend('<span style=\'color: ' + color + ';\'>' + msg + '</span><br />').hide().fadeIn('fast'); }
	else{ $('#log').prepend('<span style=\'color: ' + color + ';\'>' + msg + '</span><br />') }
};

//Sanitize Command Data
function clean(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

//Change Status Indicator
function status(type) {
	$('#status').removeClass().addClass(type).html(type).hide().fadeIn();
}

//End Of Question Processing
function postProcess(){
	globalWords = '';
	wordsPos = 0;
	$('#qs').html('');
	$('#scroll').show();
	$('#scroll2').hide();
}


//Skip At End
function skip() {
	if (!hasBuzzed){
		hasBuzzed = true;
		isAutomatic = true;
		$('#prompt').val('skip ' + wordsPos.toString()); $('#command').submit();
	}
}

//Read Questions Recursively
function read(words){
	if (wordsPos < words.length && !hasBuzzed && !hasLeft && !hasAnswered){
		$('#qs').append('<span style=\'color: #A000D2;\'>' + words[wordsPos] + ' </span>');
		$('#qs').animate({ scrollTop: $('#qs').attr('scrollHeight') }, 'slow');
		
		wordsPos += 1;
		$.doTimeout('reader', 350, function(){ read(words); });
	}
	
	//Check If End Has Come
	if (wordsPos == words.length){
		isFinished = true;
		$.doTimeout('finished', 5000, skip);
	}
}

//Create WebSocket
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
	}
  
	$('#prompt').focus();
}

//Display Input Appropriately
function display(message) {
	switch(message.action){
		case 'nick':
			log('Nickname change request sent.<br>', '#4F8E00', true);
			break;
		case 'chat':
			break;
		case 'pm':
			break;
		case 'game':
			log('New game request sent.<br>', '#4F8E00', true);
			break;
		case 'join':
			log('Game join request sent.<br>', '#4F8E00', true);
			break;	
		case 'leave':
			log('Leave request sent.<br>', '#4F8E00', true);
			break;
		case 'start':
			log('Start request sent.<br>', '#4F8E00', true);
			break;
		case 'ping':
			log('Ping request sent.<br>', '#4F8E00', true);
			break;
		case 'help':
			log('Help request sent.<br>', '#4F8E00', true);
			break;
		case 'status':
			log('Status request sent.<br>', '#4F8E00', true);
			break;
		case 'headers':
			log('Header request sent.<br>', '#4F8E00', true);
			break;
		case 'list':
			log('List request sent.<br>', '#4F8E00', true);
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
			var data = clean(message.data)
			if (data != '') { log('Command: ' + clean(message.action) + ' ' + data.join(' ') + '<br>', '#4F8E00', true); }
			else { log('Command: ' + clean(message.action) + '<br>', '#4F8E00', true); }
			break;
	}
}

/* Display Output Appropriately:
There are several state variables
here that perform their stated purpose
in checks for context. They are
initialized at the end of this script. */

function handle(response) {
	switch(response.action){
		case 'headers':
			log(response.data, '#006CDE', false);
			break;
		case 'chat':
			var prev = $('#log').html();
			prev = prev.replace(/(<([^>]+)>)/ig,'');
			
			if (prev.substr(0,1) != '['){
				response.data += '<br>';
			}
			
			log(response.data, '#FFA121', false);
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
			isWaited = false;
			isFinWaited = false;
			isJoined = false;
			break;
		case 'finish':
			function switchScreen() { $('#log').html(''); $('#prompt').val('headers'); $('#command').submit(); }
			$.doTimeout(5000, switchScreen);
			break;
		case 'leave':
			hasLeft = true;
			postProcess();
			$('#log').html('');
			break;
		case 'wrong':
			if (!isWronged){
				postProcess();
				isWaited = false;
				isFinWaited = false;
				isWronged = true;
				hasAnswered = true;
				isAnswering = false;
				hasBuzzed = false;
				isReading = false;
				log(response.data, '#006CDE', false);
				$('#prompt').focus();
			}
			break;
		case 'display':
			if (!isDisplayed && isJoined){
				postProcess();
				isWaited = false;
				isFinWaited = false;
				isDisplayed = true;
				hasAnswered = true;
				isAnswering = false;
				hasBuzzed = false;
				isReading = false;
				isDisabled = false;
				log(lastQuestion + '<br>', '#A000D2', false);
				$('#prompt').removeAttr('disabled');
			}
			break;
		case 'reset':
			postProcess();
			isWaited = false;
			isFinWaited = false;
			isDisplayed = true;
			hasAnswered = true;
			isAnswering = false;
			hasBuzzed = false;
			isReading = false;
			isDisabled = false;
			$('#prompt').removeAttr('disabled');
			break;
		case 'stats':
			if (!isStat && isJoined){
				isStat = true;
				log(response.data, '#00CC66', false);
			}
			break;
		case 'question':
			hasBuzzed = false; hasAnswered = false; isFinished = false;
			hasLeft = false; isReading = true; isStat = false;
			isDisplayed = false; isWronged = false; isJoined = true;
			
			$('#scroll').hide();
			$('#scroll2').show();
		
			$('#prompt').removeAttr('disabled');
			$('#prompt').focus(); isDisabled = false;
		
			wordsPos = 0; lastQuestion = response.data;
			globalWords = response.data.split(' ');
			read(globalWords);
			break;
		case 'read':
			if (!hasAnswered && !isReading && !isAnswering){
				$('#buzz').remove();
				hasBuzzed = false;
				
				$('#prompt').removeAttr('disabled');
				$('#prompt').focus(); isDisabled = false;
				
				isReading = true;
				read(globalWords);
			}
			break;
		case 'buzz':
			hasBuzzed = true;
			if (hasAnswered == false)
			{
				if (isFinished){
					$.doTimeout('finished'); //Cancel end timeout
				}
				
				else{
					$.doTimeout('reader'); //Cancel read timeout
				}
				
				isReading = false;
				isDisabled = true;
				
				$('#qs').append('<span id=\'buzz\' style=\'color: yellow;\'>Buzz!! </span>');
				$('#prompt').attr('disabled', true);
			}
			break;
		case 'sbuzz':
			if (isFinished){
				$.doTimeout('finished'); //Cancel end timeout
			}			
		
			else{
				$.doTimeout('reader'); //Cancel read timeout
			}
			
			hasBuzzed = true; isReading = false;
			$('#qs').append('<span style=\'color: yellow;\'>Buzz!! </span>'); isAnswering = true;
			function timeUp() { if (hasAnswered == false) { $('#prompt').val('User Negged'); $('#command').submit(); } }
			$.doTimeout(9000, timeUp);
			break;
		case 'wait':
			if (!isWaited){
				isWaited = true;
				log(response.data, '#006CDE', false);
				var nextText = 'Type <i>next</i> to continue to the next question.<br>Only one user needs to do this; please be considerate.<br>';
				nextText = nextText + 'You are guaranteed two minutes of wait time.';
				log(nextText, '#4F8E00', false);
			}
			break;
		case 'finWait':
			if (!isFinWaited){
				isFinWaited = true;
				isDisabled = true;
				log(response.data, '#4F8E00', false);
				log('Input temporarily disabling.', '#4F8E00', false);
				$('#prompt').attr('disabled', true);
				cont = function() { $('#prompt').val('continue'); $('#command').submit(); }
				$.doTimeout(5000, cont);
			}
			break;
		default:
			log(response.data, '#006CDE', false);
			break;
	}
}

//Handle Page Load, Initialize Stuff
$(document).ready(function() {
	//Pretty Scrollbar
	$('#log').slimScroll({ color: '#444', alwaysVisible: true, start: 'bottom', distance: 3, height: 280 });
	$('#qs').slimScroll({ color: '#444', alwaysVisible: false, start: 'bottom', distance: 3, height: 280 });

	if(!initialized){ initialize(); }
	
	//Keep Focus On Input
	$('#prompt').focus();
	$('body').click(function(){
		if (!$('#prompt').is(':focus')){
			$('#prompt').focus();
		}
	});

	//Deal With Backspace During Disable
	$(document).keydown(function(event){
		if (event.keyCode == 8 && isDisabled) {
			event.preventDefault();
		}
	});
	
	//Submit Command, Parsed
	$('#command').submit(function() {
		var typed = $.trim($('#prompt').val());
		if (typed.replace(/\s/g, '') != ''){
			var msg = new Object();
			
			var action = typed;
			action = action.split(' ');
			action = action[0];
			action = action.toLowerCase();
			
			var data = typed
			data = data.split(' ');
			data.shift();
			
			if (action == 'answer')
			{
				$('#prompt').val('');
				return false;
			}
			
			if (action == 'buzz')
			{
				if (hasAnswered || !isReading)
				{
					$('#prompt').val('');
					return false;
				}
			}
			
			if (action == 'continue')
			{
				if (!isFinWaited)
				{
					$('#prompt').val('');
					return false;
				}
			}
			
			if (action == 'skip' && !isAutomatic){
				$('#prompt').val('');
				return false;
			}
			
			if (action == 'next' && !isWaited){
				$('#prompt').val('');
				return false;
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
			
			isAutomatic = false;
			socket.send(JSON.stringify(msg));
			display(msg);
		}
	});
});

/* This is where I initialize
a billion different variables
used throughout the client
code. As previously stated,
they should be self-explanatory */

var initialized = false;
var hasBuzzed = false;
var hasAnswered = false;
var isAnswering = false;
var isReading = false;
var isDisplayed = false;
var isWronged = false;
var isWaited = false
var isFinWaited = false;
var isFinished = false;
var isJoined = false;
var isDisabled = false;
var isAutomatic = false;
var isStat = false;
var hasLeft = false;

var noSubmit = function(){}
var wordsPos = 0;
var globalWords = '';
var lastQuestion = '';
var socket;