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

namespace iMSCP\Core\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use iMSCP_Registry as Registry;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Class DBALConnectionFactory
 * @package iMSCP\Core\Service
 */
class DBALConnectionFactory implements FactoryInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function createService(ServiceLocatorInterface $serviceLocator)
	{
		$systemConfig = $serviceLocator->get('SystemConfig');
		$config = new Configuration();

		// Ignore tables which are not managed through ORM service
		$config->setFilterSchemaAssetsExpression('/^(?:admin|aps_.*)$/');

		/** @var \PDO $pdo */
		$pdo = $serviceLocator->get('Database')->getRawInstance();
		$pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, ['Doctrine\\DBAL\\Driver\\PDOStatement', []]);

		$conn = DriverManager::getConnection(
			[
				'pdo' => $pdo, // Reuse PDO instance from Database service
				'host' => $systemConfig['DATABASE_HOST'], // Only there for later referral through connection object
				'port' => $systemConfig['DATABASE_PORT'] // Only there for later referral through connection object
			],
			$config
		);

		return $conn;
	}
}
