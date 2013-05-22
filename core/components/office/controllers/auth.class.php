<?php

class officeAuthController extends officeDefaultController {

	public function setDefault($config = array()) {
		if (defined('MODX_ACTION_MODE') && MODX_ACTION_MODE && !empty($_SESSION['Office']['Auth'])) {
			$this->config = $_SESSION['Office']['Auth'];
			$this->config['json_response'] = true;
		}
		else {
			$this->config = array_merge(array(
				'tplLogin' => 'tpl.Office.auth.login'
				,'tplLogout' => 'tpl.Office.auth.logout'
				,'tplProfile' => 'tpl.Office.auth.profile'
				,'tplActivate' => 'tpl.Office.auth.activate'

				,'groups' => ''
				,'loginResourceId' => $this->modx->getOption('office_page_id', null, $this->modx->getOption('site_start'))
				,'logoutResourceId' => $this->modx->getOption('office_page_id', null, $this->modx->getOption('site_start'))
				,'rememberme' => true
				,'loginContext' => $this->modx->context->key
				,'addContexts' => ''
			), $config);

			$_SESSION['Office']['Auth'] = $this->config;
		}
	}


	public function getLanguageTopics() {
		return array('office:auth');
	}


	public function defaultAction() {
		$this->modx->regClientScript($this->office->config['jsUrl'] . 'auth/default.js');

		if (!$this->modx->user->isAuthenticated()) {
			return $this->modx->getChunk($this->config['tplLogin']);
		}
		else {
			$user = $this->modx->user->toArray();
			$profile = $this->modx->user->getOne('Profile')->toArray();
			return $this->modx->getChunk($this->config['tplLogout'], array_merge($profile, $user));
		}
	}


	public function sendLink($data = array()) {
		$email = trim(@$data['email']);
		if (empty($email)) {
			return $this->error($this->modx->lexicon('office_auth_err_email_ns'));
		}
		else if (!preg_match('/.+@.+..+/i', $email)) {
			return $this->error($this->modx->lexicon('office_auth_err_email'));
		}

		if ($this->modx->getCount('modUser', array('username' => $email))) {
			return $this->sendMail($email);
		}
		else {
			return $this->createUser($email);
		}
	}


	public function sendMail($email) {
		/* @var modUser $user */
		if (!$user = $this->modx->getObject('modUser', array('username' => $email))) {
			return $this->error($this->modx->lexicon('office_auth_err_email_nf'));
		}

		$activationHash = md5(uniqid(md5($user->get('email') . '/' . $user->get('id')), true));

		$this->modx->getService('registry', 'registry.modRegistry');
		$this->modx->registry->getRegister('user', 'registry.modDbRegister');
		$this->modx->registry->user->connect();
		$this->modx->registry->user->subscribe('/pwd/reset/');
		$this->modx->registry->user->send('/pwd/reset/', array(md5($user->get('username')) => $activationHash), array('ttl' => 86400));

		$newPassword = $user->generatePassword();

		$user->set('cachepwd', $newPassword);
		$user->save();

		/* send activation email */
		$link = $this->modx->makeUrl($this->config['loginResourceId'], '', array(
				'action' => 'auth/login'
				,'email' => $email
				,'hash' => $activationHash.':'.$newPassword
			)
			, 'full'
		);

		$send = $user->sendEmail(
			$this->modx->getChunk(
				$this->config['tplActivate']
				,array_merge(
					$user->getOne('Profile')->toArray()
					,$user->toArray()
					,array('link' => $link)
				)
			)
			,array(
				'subject' => $this->modx->lexicon('office_auth_email_subject')
			)
		);

		if ($send !== true) {
			$errors = $this->modx->mail->mailer->errorInfo();
			$this->modx->log(modX::LOG_LEVEL_ERROR, '[Office] Unable to send email to '.$email.'. Message: '.$errors);
			return $this->error($this->modx->lexicon('office_auth_err_send', array('errors' => $errors)));
		}

		return $this->success($this->modx->lexicon('office_auth_email_send'));
	}


	public function createUser($email) {
		$response = $this->office->runProcessor('auth/create', array(
			'username' => $email
			,'email' => $email
			,'active' => false
			,'blocked' => false
			,'groups' => $this->config['groups']
		));

		if ($response->isError()) {
			$errors = implode(', ', $response->getAllErrors());
			$this->modx->log(modX::LOG_LEVEL_ERROR, '[Office] Unable to create user '.$email.'. Message: '.$errors);
			return $this->error($this->modx->lexicon('office_auth_err_create', array('errors' => $errors)));

		}
		else {
			return $this->sendMail($email);
		}
	}


	public function Login($data) {
		/* @var modUser $user */
		if ($user = $this->modx->getObject('modUser', array('username' => @$data['email']))) {
			list($hash, $password) = explode(':', @$data['hash']);
			if ($activate = $user->activatePassword($hash)) {
				$user->set('active', 1);
				$user->save();

				$data = array(
					'username' => $data['email']
					,'password' => $password
					,'rememberme' => $this->config['rememberme']
					,'loginContext' => $this->config['loginContext']
					,'addContexts' => $this->config['addContexts']
				);
				$response = $this->modx->runProcessor('security/login', $data);
				if ($response->isError()) {
					$errors = implode(', ',$response->getAllErrors());
					$this->modx->log(modX::LOG_LEVEL_ERROR, '[Office] unable to login user '.$data['email'].'. Message: '.$errors);
					return $this->modx->lexicon('office_auth_err_login', array('errors' => $errors));
				}

				$this->modx->sendRedirect($this->modx->makeUrl($this->config['loginResourceId']));
			}
		}

		return false;
	}


	public function Logout() {
		$response = $this->modx->runProcessor('security/logout');
		if ($response->isError()) {
			$errors = implode(', ',$response->getAllErrors());
			$this->modx->log(modX::LOG_LEVEL_ERROR, '[Office] logout error. Username: '.$this->modx->user->get('username').', uid: '.$this->modx->user->get('id').'. Message: '.$errors);
		}

		$this->modx->sendRedirect($this->modx->makeUrl($this->config['logoutResourceId']));
	}

}

return 'officeAuthController';