<?php	// $Format:SambaDAV: commit %h @ %cd$

# Copyright (C) 2014  Bokxing IT, http://www.bokxing-it.nl
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as
# published by the Free Software Foundation, either version 3 of the
# License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
# Project page: <https://github.com/1afa/sambadav/>

namespace SambaDAV;

use Sabre\HTTP;

class Auth
{
	public $user = null;
	public $pass = null;
	public $anonymous = false;

	private $userhome = null;	// An URI instance
	private $samba_username = null;
	private $samba_domain = null;

	public function
	__construct ($config, $log, $baseuri = '/')
	{
		$this->config = $config;
		$this->log = $log;
		$this->baseuri = $baseuri;
	}

	public function
	exec ()
	{
		// If ANONYMOUS_ONLY is set to true in the config, don't require credentials;
		// also the 'logout' action makes no sense for an anonymous server:
		if ($this->config->anonymous_only) {
			$this->log->info("anonymous login accepted\n");
			$this->anonymous = true;
			return true;
		}
		$sapi = new HTTP\Sapi();
		$response = new HTTP\Response();
		$request = $sapi->getRequest();

		$auth = new HTTP\Auth\Basic('Web Folders', $request, $response);

		// If no basic auth creds set, but the variables "user" and "pass" were
		// posted to the page (e.g. from a/the login form), substitute those:
		if (!isset($_SERVER['PHP_AUTH_USER']) && !isset($_SERVER['PHP_AUTH_PW'])) {
			if (isset($_POST) && isset($_POST['user']) && isset($_POST['pass'])) {
				$_SERVER['PHP_AUTH_USER'] = $_POST['user'];
				$_SERVER['PHP_AUTH_PW'] = $_POST['pass'];

				// HACK: dynamically change the request method to GET, because
				// otherwise SambaDAV will throw an exception because there is
				// no POST handler installed. This change causes SabreDAV to
				// process this request just like any other basic auth login:
				$_SERVER['REQUEST_METHOD'] = 'GET';
			}
		}
		list($this->user, $this->pass) = $auth->getCredentials();

		if ($this->user === false || $this->user === '') $this->user = null;
		if ($this->pass === false || $this->pass === '') $this->pass = null;

		if (isset($_GET['logout']))
		{
			// If you're tagged with 'logout' but you're not passing a
			// username/pass, redirect to plain index:
			if ($this->user === null || $this->pass === null) {
				header("Location: {$this->baseuri}");
				return false;
			}
			// Otherwise, if you're tagged with 'logout', make sure
			// the authentication is refused, to make the browser
			// flush its cache:
			$this->showLoginForm($auth, $response);
			return false;
		}
		if ($this->checkAuth() === false) {
			sleep(2);
			$this->showLoginForm($auth, $response);
			return false;
		}
		$this->log->info("login accepted for '%s'\n", (is_null($this->user) ? '(none)' : $this->user));

		return true;
	}

	public function
	checkAuth ()
	{
		// If we did not get all creds, check whether that's okay or not:
		if ($this->user === null || $this->pass === null) {
			if ($this->config->anonymous_allow) {
				$this->log->debug("allowing anonymous login\n");
				$this->anonymous = true;
				return true;
			}
			$this->log->info("anonymous login denied by config\n");
			return false;
		}
		// Check if the Samba patterns can be filled:
		// These are the only patterns that are not exercised during this call:
		if ($this->checkSambaPatterns() === false) {
			return false;
		}
		// Set userhome to userhome pattern if defined:
		if ($this->user !== null) {
			if (is_string($this->config->userhome_pattern))
			{
				// Failed to fill the pattern?
				if (($filled = $this->fillPattern($this->config->userhome_pattern)) === false) {
					return false;
				}
				$this->userhome = new URI($filled);
			}
			else if (is_string($this->config->share_userhomes)) {
				$this->userhome = new URI($this->config->share_userhomes, $this->user);
			}
		}
	        if ($this->checkPwd() === false) {
                        return false;
                }
		if ($this->checkLdap() === false) {
			return false;
		}
		return true;
	}

	private function
	checkLdap ()
	{
		// Check LDAP for group membership:
		// $ldap_groups is sourced from config/config.inc.php:
		if ($this->config->ldap_auth !== true) {
			$this->log->debug("skipping LDAP authentication\n");
			return true;
		}
		// Should we do an AD-style bind or a fast bind?
		$method = ($this->config->ldap_method === 'bind')
			? LDAP::METHOD_BIND
			: LDAP::METHOD_FASTBIND;

		$ldap = new LDAP($method, $this->config->ldap_host, $this->config->ldap_basedn, $this->config->ldap_authdn, $this->config->ldap_authpass);

		if (($username = $this->ldapUsername()) === false) {
			$this->log->info("LDAP username not found\n");
			return false;
		}
		if ($ldap->verify($username, $this->pass, $this->config->ldap_groups, $this->config->share_userhome_ldap) === false) {
			$this->log->info("failed to verify credentials in LDAP with username '%s'\n", $username);
			return false;
		}
		if ($ldap->userhome !== null) {
			$this->userhome = new URI($ldap->userhome);
			$this->log->debug("LDAP userhome found: '%s'\n", $this->userhome);
		}
		return true;
	}

        private function
        checkPwd ()
        {
                $connection = ssh2_connect('localhost', 22);

                if (ssh2_auth_password($connection, $this->user, $this->pass)) {
                        return true;
                } else {
                        return false;
                }
        }

	public function
	checkSambaPatterns ()
	{
		$this->samba_username = $this->user;
		$this->samba_domain = null;

		// Check if the Samba username pattern, if given, can be satisfied:
		if (is_string($this->config->samba_username_pattern)) {
			if (($filled = $this->fillPattern($this->config->samba_username_pattern)) === false) {
				$this->log->info("failed to fill Samba username pattern\n");
				return false;
			}
			$this->samba_username = $filled;
		}
		if (is_string($this->config->samba_domain_pattern)) {
			if (($filled = $this->fillPattern($this->config->samba_domain_pattern)) === false) {
				$this->log->info("failed to fill Samba domain pattern\n");
				return false;
			}
			$this->samba_domain = $filled;
		}
		return true;
	}

	private function
	showLoginForm ($auth, $response)
	{
		$auth->requireLogin();
		$loginForm = new LoginForm($this->baseuri);
		$response->setBody($loginForm->getBody());
		HTTP\Sapi::sendResponse($response);
	}

	public function
	ldapUsername ()
	{
		if (is_string($this->config->ldap_username_pattern)) {
			return $this->fillPattern($this->config->ldap_username_pattern);
		}
		return $this->user;
	}

	public function
	sambaUsername ()
	{
		return $this->samba_username;
	}

	public function
	sambaDomain ()
	{
		return $this->samba_domain;
	}

	public function
	getUserhome ()
	{
		return $this->userhome;
	}

	public function
	fillPattern ($pattern)
	{
		// Username can be split up as follows:
		// workgroup\username@domain
		//  %w = workgroup
		//  %u = username
		//  %d = domain

		if (($slashPos = strpos($this->user, '\\')) === false) {
			$atPos = strpos($this->user, '@');
		}
		else {
			if (($atPos = strpos(substr($this->user, $slashPos), '@')) !== false) {
				$atPos += $slashPos;
			}
		}
		$workgroup = ($slashPos === false)
			? ''
			: substr($this->user, 0, $slashPos);

		$userStart = ($slashPos === false) ? 0 : $slashPos + 1;
		$userEnd = ($atPos === false) ? strlen($this->user) - 1 : $atPos - 1;

		$username = substr($this->user, $userStart, $userEnd - $userStart + 1);

		$domain = ($atPos === false)
			? ''
			: substr($this->user, $atPos + 1);

		$subst = array
			( '%w' => $workgroup
			, '%u' => $username
			, '%d' => $domain
			) ;

		// Check if all the placeholders specified in the string are
		// available, else return false:
		foreach ($subst as $repl => $str) {
			if (strpos($pattern, $repl) === false) {
				continue;
			}
			if ($str === '') {
				$this->log->debug("pattern '%s' could not be filled\n", $repl);
				return false;
			}
		}
		return str_replace(array_keys($subst), array_values($subst), $pattern);
	}
}
