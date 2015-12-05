<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2015 by i-MSCP Team <team@i-mscp.net>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * Initialize login
 *
 * @param \Zend\EventManager\EventManagerInterface $eventManager Events Manager
 * @return void
 */
function init_login($eventManager)
{
    // Purge expired sessions
    do_session_timeout();

    $cfg = \iMSCP\Core\Application::getInstance()->getConfig();

    if ($cfg['BRUTEFORCE']) {
        /** @var \iMSCP\Core\Plugin\PluginManager $pluginManager */
        $pluginManager = \iMSCP\Core\Application::getInstance()->getServiceManager()->get('PluginManager');
        $bruteforce = new iMSCP\Core\Plugin\Bruteforce($pluginManager);
        $bruteforce->attach($pluginManager->getEventManager());
    }

    // Attach listener that is responsible to check domain status and expire date
    $eventManager->attach(\iMSCP\Core\Authentication\AuthenticationEvent::onBeforeSetIdentity, 'login_checkDomainAccount');
}

/**
 * Check domain account state (status and expires date)
 *
 * Note: Listen to the onBeforeSetIdentity event triggered in the iMSCP_Authentication component.
 *
 * @param \Zend\EventManager\Event $event An Zend\EventManager\EventInterface object representing an onBeforeSetIdentity event.
 * @return void
 */
function login_checkDomainAccount($event)
{
    /** @var $identity stdClass */
    $identity = $event->getParam('identity');

    if ($identity->admin_type == 'user') {
        $query = '
            SELECT
                domain_expires, domain_status, admin_status
            FROM
                domain
            INNER JOIN
                admin ON(domain_admin_id = admin_id)
            WHERE
                domain_admin_id = ?
        ';
        $stmt = exec_query($query, $identity->admin_id);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $isAccountStateOk = true;

        if (($row['admin_status'] != 'ok') || ($row['domain_status'] != 'ok')) {
            $isAccountStateOk = false;
            set_page_message(
                tr('Your account is currently under maintenance or disabled. Please, contact your reseller.'), 'error'
            );
        } else {
            $domainExpireDate = $row['domain_expires'];

            if ($domainExpireDate && $domainExpireDate < time()) {
                $isAccountStateOk = false;
                set_page_message(tr('Your account has expired.'), 'error');
            }
        }

        if (!$isAccountStateOk) {
            redirectTo('index.php');
        }
    }
}

/**
 * Session garbage collector
 *
 * @return void
 */
function do_session_timeout()
{
    $cfg = \iMSCP\Core\Application::getInstance()->getConfig();

    // We must not remove bruteforce plugin data (AND `user_name` IS NOT NULL)
    exec_query('DELETE FROM login WHERE lastaccess < ? AND user_name IS NOT NULL', time() - $cfg['SESSION_TIMEOUT'] * 60);
}

/**
 * Check login
 *
 * @param string $userLevel User level (admin|reseller|user)
 * @param bool $preventExternalLogin If TRUE, external login is disallowed
 */
function check_login($userLevel = '', $preventExternalLogin = true)
{
    do_session_timeout();

    /** @var \iMSCP\Core\Authentication\Authentication $authentication */
    $authentication = \iMSCP\Core\Application::getInstance()->getServiceManager()->get('Authentication');

    if (!$authentication->hasIdentity()) {
        $authentication->unsetIdentity(); // Ensure deletion of all identity data

        if (is_xhr()) {
            header('Status: 401 Unauthorized');
            exit;
        }

        redirectTo('/index.php');
    }

    $cfg = \iMSCP\Core\Application::getInstance()->getConfig();

    $identity = $authentication->getIdentity();

    if ($cfg['MAINTENANCEMODE'] && $identity->admin_type != 'admin' &&
        (!isset($_SESSION['logged_from_type']) || $_SESSION['logged_from_type'] != 'admin')
    ) {
        $authentication->unsetIdentity();
        redirectTo('/index.php');
    }

    // Check user level
    if (!empty($userLevel) && ($userType = $identity->admin_type) != $userLevel) {
        if ($userType != 'admin' && (!isset($_SESSION['logged_from']) || $_SESSION['logged_from'] != 'admin')) {
            $loggedUser = isset($_SESSION['logged_from']) ? $_SESSION['logged_from'] : $identity->admin_name;
            write_log('Warning! user |' . $loggedUser . '| requested |' . tohtml($_SERVER['REQUEST_URI']) .
                '| with REQUEST_METHOD |' . $_SERVER['REQUEST_METHOD'] . '|', E_USER_WARNING);
        }

        redirectTo('/index.php');
    }

    // prevent external login / check for referer
    if ($preventExternalLogin && !empty($_SERVER['HTTP_REFERER'])) {
        // Extracting hostname from referer URL
        // Note2: We remove any braket in referer (ipv6 issue)
        $refererHostname = str_replace(['[', ']'], '', parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST));

        // The URL does contains the host element ?
        if (!is_null($refererHostname)) {
            // Note1: We don't care about the scheme, we only want make parse_url() happy
            // Note2: We remove any braket in hostname (ipv6 issue)
            $http_host = str_replace(['[', ']'], '', parse_url("http://{$_SERVER['HTTP_HOST']}", PHP_URL_HOST));

            // The referer doesn't match the panel hostname ?
            if (!in_array($refererHostname, [$http_host, $_SERVER['SERVER_NAME']])) {
                set_page_message(tr('Request from foreign host was blocked.'), 'info');

                # Quick fix for #96 (will be rewritten ASAP)
                isset($_SERVER['REDIRECT_URL']) ?: $_SERVER['REDIRECT_URL'] = '';

                if (!(substr($_SERVER['SCRIPT_FILENAME'], (int)-strlen($_SERVER['REDIRECT_URL']),
                        strlen($_SERVER['REDIRECT_URL'])) == $_SERVER['REDIRECT_URL'])
                ) {
                    redirectToUiLevel();
                }
            }
        }
    }

    // If all goes fine update session and lastaccess
    $_SESSION['user_login_time'] = time();
    exec_query('UPDATE login SET lastaccess = ? WHERE session_id = ?', [$_SESSION['user_login_time'], session_id()]);
}

/**
 * Switch between user's interfaces
 *
 * @param int $fromId User ID to switch from
 * @param int $toId User ID to switch on
 * @return void
 */
function change_user_interface($fromId, $toId)
{
    $toActionScript = false;

    while (1) { // We loop over nothing here, it's just a way to avoid code repetition
        $query = '
            SELECT
                admin_id, admin_name, admin_type, email, created_by
            FROM
                admin
            WHERE
                admin_id IN(?, ?)
            ORDER BY
                FIELD(admin_id, ?, ?)
            LIMIT
                2
        ';
        $stmt = exec_query($query, [$fromId, $toId, $fromId, $toId]);

        if ($stmt->rowCount() < 2) {
            set_page_message(tr('Wrong request.'), 'error');
        }

        list($from, $to) = $stmt->fetchAll(PDO::FETCH_OBJ);

        $fromToMap = [];
        $fromToMap['admin']['BACK'] = 'manage_users.php';
        $fromToMap['admin']['reseller'] = 'index.php';
        $fromToMap['admin']['user'] = 'index.php';
        $fromToMap['reseller']['user'] = 'index.php';
        $fromToMap['reseller']['BACK'] = 'users.php';

        if (!isset($fromToMap[$from->admin_type][$to->admin_type]) || ($from->admin_type == $to->admin_type)) {
            if (isset($_SESSION['logged_from_id']) && $_SESSION['logged_from_id'] == $to->admin_id) {
                $toActionScript = $fromToMap[$to->admin_type]['BACK'];
            } else {
                set_page_message(tr('Wrong request.'), 'error');
                write_log(
                    sprintf("%s tried to switch onto %s's interface", $from->admin_name, decode_idna($to->admin_name)),
                    E_USER_WARNING
                );
                break;
            }
        }

        $toActionScript = ($toActionScript) ? $toActionScript : $fromToMap[$from->admin_type][$to->admin_type];

        // Set new identity
        /** @var \iMSCP\Core\Authentication\Authentication $authentication */
        $authentication = \iMSCP\Core\Application::getInstance()->getServiceManager()->get('Authentication');
        $authentication->unsetIdentity();
        $authentication->setIdentity($to);

        if ($from->admin_type != 'user' && $to->admin_type != 'admin') {
            // Set additional data about user from wich we are logged from
            $_SESSION['logged_from_type'] = $from->admin_type;
            $_SESSION['logged_from'] = $from->admin_name;
            $_SESSION['logged_from_id'] = $from->admin_id;

            write_log(
                sprintf("%s switched onto %s's interface", $from->admin_name, decode_idna($to->admin_name)),
                E_USER_NOTICE
            );
        } else {
            write_log(
                sprintf("%s switched back from %s's interface", $to->admin_name, decode_idna($from->admin_name)),
                E_USER_NOTICE
            );
        }

        break;
    }

    redirectToUiLevel($toActionScript);
}

/**
 * Redirects to user ui level
 *
 * @param string $actionScript Action script on which user should be redirected
 * @return void
 */
function redirectToUiLevel($actionScript = 'index.php')
{
    /** @var \iMSCP\Core\Authentication\Authentication $authentication */
    $authentication = \iMSCP\Core\Application::getInstance()->getServiceManager()->get('Authentication');

    if ($authentication->hasIdentity()) {
        $userType = $authentication->getIdentity()->admin_type;
        switch ($userType) {
            case 'user':
            case 'admin':
            case 'reseller':
                // Prevents display of any old message when switching to another user level
                unset($_SESSION['pageMessages']);
                redirectTo('/' . (($userType == 'user') ? 'client' : $userType . '/' . $actionScript));
                exit;
            default:
                throw new InvalidArgumentException('Unknown UI level');
        }
    }
}
