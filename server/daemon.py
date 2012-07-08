#!/usr/bin/python

from subprocess import Popen, PIPE, STDOUT, call
import time

while True:
	proc = Popen('ps xa | grep /var/www/html/server.php', shell=True, stdin=PIPE, stdout=PIPE, stderr=STDOUT, close_fds=True)
	output = proc.stdout.read().splitlines()

	if len(output) < 3:
		call('nohup php /var/www/html/server.php &', shell=True)
	
	time.sleep(3)