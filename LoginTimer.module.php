<?php namespace ProcessWire;

/**
 * Login Timer for ProcessWire
 * 
 * Enables normalization of login times so that a failed login 
 * is no faster than a successful login. 
 *
 * This prevents timing attacks from discovering any information
 * about good vs. bad user names or passwords based on the time
 * taken to execute the request. It does this by remembering how
 * long successful logins take and applying that same amount of
 * time to failed logins. 
 * 
 * When installed, this module automatically applies to any
 * login form that uses ProcessWire’s $session functions. 
 * It can be applied to any other login form using its API:
 * 
 * API USAGE
 * ~~~~~
 * if('login form is submitted') {
 *   $loginTimer = $modules->get('LoginTimer');
 *   $loginTimer->start('my-login-form'); 
 *   // …your code to test for login success goes here…
 *   if('login success') {
 *     // remember time of successful login
 *     $loginTimer->save();
 *   } else {
 *     // apply delay for failed login
 *     $loginTimer->apply();
 *   }
 * }
 * ~~~~~
 * 
 * Copyright (C) 2023 by Ryan Cramer
 * License: MIT
 * 
 * @property int $maxTime
 * @property int|bool $debugMode
 * 
 */
class LoginTimer extends WireData implements Module, ConfigurableModule {

	/**
	 * Elapsed time in milliseconds
	 * 
	 * @var float 
	 * 
	 */
	protected $elapsed = 0.0;

	/**
	 * The currently used Debug::timer
	 * 
	 * @var null 
	 * 
	 */
	protected $timer = null;

	/**
	 * The name for the current timer 
	 * 
	 * @var string 
	 * 
	 */
	protected $name = 'default';

	/**
	 * Has login timing started?
	 * 
	 * @var bool 
	 * 
	 */
	protected $started = false;
	
	/**
	 * Has login timing stopped?
	 *
	 * @var bool
	 *
	 */
	protected $stopped = false;

	/**
	 * Has login delay been applied? 
	 * 
	 * @var bool 
	 * 
	 */
	protected $applied = false;

	/**
	 * Has delay been saved?
	 * 
	 * @var bool 
	 * 
	 */
	protected $saved = false;

	/**
	 * Construct
	 * 
	 */
	public function __construct() {
		$this->set('maxTime', 1000);
		$this->set('debugMode', 0);
		parent::__construct();
	}

	/**
	 * API ready (when running as autoload module)
	 * 
	 */
	public function ready() {

		// user is already logged in
		if($this->wire()->user->isLoggedin()) return;

		// logins only occur during POST requests
		if(!$this->wire()->input->requestMethod('POST')) return;
	
		// add hooks
		$session = $this->wire()->session;
		$session->addHookBefore('login', $this, 'hookSessionLogin');
		$session->addHookBefore('loginSuccess', $this, 'hookSessionLoginSuccess');
		$session->addHookBefore('loginFailure', $this, 'hookSessionLoginFailure');

		// LoginRegisterPro
		if($this->wire()->input->post('pwlrp')) {
			// LoginRegisterPro checks that user exists with email before attempting a login
			// so we use hooks to ensure timer is always applied
			if($this->wire()->modules->isInstalled('LoginRegisterPro')) {
				$this->name = 'LoginRegisterPro';
				$this->addHookBefore('LoginRegisterProLogin::process', $this, 'hookSessionLogin');
				$this->addHookBefore('LoginRegisterProLogin::fail', $this, 'hookSessionLoginFailure');
			}
		}
	}

	/**
	 * Hooks for Session
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSessionLogin(HookEvent $event) { $this->start(); }
	public function hookSessionLoginSuccess(HookEvent $event) { $this->save(); }
	public function hookSessionLoginFailure(HookEvent $event) { $this->apply(); }

	/**
	 * Start timer
	 * 
	 * To be called once before validating username, and again before validating password.
	 * 
	 * @param string $name Name to use for timer (a-z A-Z 0-9)
	 *
	 */
	public function start($name = '') {
		if($this->started) return;
		if($name) $this->name = $name;
		$this->timer = Debug::startTimer();
		$this->started = true;
		$this->stopped = false;
	}

	/**
	 * Stop timer
	 * 
	 * To be called once after validating username, and again after validating password.
	 *
	 */
	public function stop() {
		if($this->stopped) return;
		$this->started = false;
		$this->stopped = true;
		if($this->timer === null) return;
		$ms = (float) rtrim(Debug::stopTimer($this->timer, 'ms', true), 'ms ');
		$this->elapsed += $ms;
		$this->timer = null;
		$this->debug("stop: {$ms}ms elapsed={$this->elapsed}ms"); 
	}

	/**
	 * Save timer the timer so that it can be applied later
	 * 
	 * To be called immediately after successful login.
	 * 
	 */
	public function save() {
		if($this->saved) return;
		if($this->timer !== null) {
			$this->stop();
		}
		if(!$this->name) {
			$this->debug("save: aborted because no name defined"); 
			return;
		}
		$file = $this->getFile();
		if($this->elapsed < 1) {
			$this->debug("save: aborted because elapsed=$this->elapsed"); 
			return;
		}
		// update this time at most once per day
		if(is_file($file) && filemtime($file) > time() - 3600) {
			$this->debug("save: skip because file already updated within last hour");
			return;
		}
		if($this->elapsed > $this->maxTime) {
			$this->elapsed = $this->maxTime;
		}
		$this->wire()->files->filePutContents($file, $this->elapsed, LOCK_EX);
		$this->debug("save: {$this->elapsed}ms ($file)");
		$this->saved = true;
	}

	/**
	 * Apply timer delay (when appropriate)
	 * 
	 * To be called immediately after failed login.
	 * 
	 */
	public function apply() {
		if($this->applied) return;
		$milliseconds = $this->getFileMS();
		$milliseconds -= $this->elapsed;
		if($milliseconds < 1) {
			$this->debug("apply NO: delay $milliseconds < $this->elapsed elapsed");
			return;
		}
		$max = (float) $this->maxTime;
		if($milliseconds > $max) $milliseconds = $max;
		$microseconds = (int) ($milliseconds * 1000);
		usleep($microseconds);
		$this->debug("apply YES: {$milliseconds}ms delay (usleep=$microseconds)");
		$this->applied = true;
	}

	/**
	 * Get file to use for saving timer
	 * 
	 * @return string
	 * 
	 */
	protected function getFile() {
		return $this->getPath() . "$this->name.timer";
	}

	/**
	 * Get milliseconds from timer file
	 * 
	 * @return float
	 * 
	 */
	protected function getFileMS() {
		$file = $this->getFile();
		if(!is_readable($file)) return 0.0; // no successful login timed yet
		return (float) $this->wire()->files->fileGetContents($file);
	}

	/**
	 * Get path where LoginTimer files are stored
	 * 
	 * @return string
	 * 
	 */
	protected function getPath() {
		$path = $this->wire()->config->paths->cache . $this->className() . '/';
		if(!is_dir($path)) $this->wire()->files->mkdir($path);
		return $path;
	}

	/**
	 * @param string $msg
	 * 
	 */
	protected function debug($msg) {
		if(!$this->debugMode) return;
		$this->log($msg);
		if(function_exists("bd")) bd($msg, $this->className());
	}

	/**
	 * Install
	 * 
	 */
	public function ___install() {
		$this->getPath();
	}

	/**
	 * Uninstall
	 *
	 */
	public function ___uninstall() {
		$path = $this->getPath();
		if(is_dir($path)) $this->wire()->files->rmdir($path, true);
	}

	/**
	 * Module config
	 * 
	 * @param InputfieldWrapper $inputfields
	 * 
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$f = $inputfields->InputfieldInteger;
		$f->attr('name', 'maxTime');
		$f->label = $this->_('Max allowed login delay (in milliseconds)');
		$f->notes = $this->_('1000 = 1 second');
		$f->val($this->maxTime);
		$inputfields->add($f);
		
		$f = $inputfields->InputfieldToggle; 
		$f->attr('name', 'debugMode');
		$f->label = $this->_('Debug mode?');
		$f->description = $this->_('Logs activity to Tracy Debugger and Setup > Logs > login-timer.');
		$f->val($this->debugMode);
		if(!$this->debugMode) $f->collapsed = true;
		$inputfields->add($f);
	}
	
	/*
	// Future use: option to hook ProcessLogin for greater granularity
	$this->addHook('ProcessLogin::loginAttemptReady', $this, 'hookLoginAttemptReady');
	$this->addHook('ProcessLogin::loginAttempted', $this, 'hookLoginAttempted');
	$this->addHook('ProcessLogin::loginFormProcessReady', $this, 'hookLoginFormProcessReady');
	$this->addHook('ProcessLogin::loginFormProcessed', $this, 'hookLoginFormProcessed');
	$this->addHookBefore('ProcessLogin::loginSuccess', $this, 'hookLoginSuccess');
	$this->addHookBefore('ProcessLogin::loginFailed', $this, 'hookLoginFailed');
	public function hookLoginAttemptReady(HookEvent $event) { $this->start('admin'); }
	public function hookLoginAttempted(HookEvent $event) { $this->stop(); }
	public function hookLoginFormProcessReady(HookEvent $event) { $this->start(); }
	public function hookLoginFormProcessed(HookEvent $event) { $this->stop(); }
	public function hookLoginSuccess(HookEvent $event) { $this->save(); }
	public function hookLoginFailed(HookEvent $event) { $this->apply(); }
	*/

}