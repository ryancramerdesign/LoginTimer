<?php namespace ProcessWire;

$info = [
	'title' => 'Login Timer',
	'version' => 1,
	'author' => 'Ryan Cramer',
	'summary' => 'Normalize successful and failed login times to prevent timing attacks.',
	'icon' => 'shield',
	'requires' => 'ProcessWire>=3.0.27',
	'autoload' => function() { 
		return wire()->user->isGuest() && wire()->input->requestMethod('POST'); 
	}
];