<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2015 by Laurent Declercq <l.declercq@nuxwin.com>
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
namespace iMSCP\Core\Authentication;

use Zend\EventManager\Event;

/**
 * Class AuthenticationEvent
 * @package iMSCP\Core\Authentication
 */
class AuthenticationEvent extends Event
{
    const onBeforeAuthentication = 'onBeforeAuthentication';
    const onAuthentication = 'onAuthentication';
    const onAfterAuthentication = 'onAfterAuthentication';
    const onBeforeSetIdentity = 'onBeforeSetIdentity';
    const onAfterSetIdentity = 'onAfterSetIdentity';
    const onBeforeUnsetIdentity = 'onBeforeUnsetIdentity';
    const onAfterUnsetIdentity = 'onAfterUnsetIdentity';

    /**
     * Set authentication result object
     *
     * @param AuthenticationResult $authResult
     */
    public function setAuthResult(AuthenticationResult $authResult)
    {
        $this->setParam('authResult', $authResult);
    }

    /**
     * Get authentication result object
     *
     * @return AuthenticationResult
     */
    public function getAuthResult()
    {
        return $this->getParam('authResult');
    }
}
