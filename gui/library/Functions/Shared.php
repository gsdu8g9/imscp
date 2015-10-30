<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 *
 * The contents of this file are subject to the Mozilla Public License
 * Version 1.1 (the "License"); you may not use this file except in
 * compliance with the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS"
 * basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 * License for the specific language governing rights and limitations
 * under the License.
 *
 * The Original Code is "VHCS - Virtual Hosting Control System".
 *
 * The Initial Developer of the Original Code is moleSoftware GmbH.
 * Portions created by Initial Developer are Copyright (C) 2001-2006
 * by moleSoftware GmbH. All Rights Reserved.
 *
 * Portions created by the ispCP Team are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 *
 * Portions created by the i-MSCP Team are Copyright (C) 2010-2015 by
 * i-MSCP - internet Multi Server Control Panel. All Rights Reserved.
 */

/***********************************************************************************************************************
 * This file contains functions that are used at many level (eg. admin, reseller, client)
 */

/***********************************************************************************************************************
 * Account functions
 */

/**
 * Returns user name matching identifier
 *
 * @param  int $user_id User unique identifier
 * @return string Username
 */
function get_user_name($user_id)
{
	$query = "SELECT `admin_name` FROM `admin` WHERE `admin_id` = ?";
	$stmt = exec_query($query, $user_id);

	return $stmt->fields('admin_name');
}

/***********************************************************************************************************************
 * Domain related functions
 */

/**
 * Checks if the given domain name already exist
 *
 * Rules:
 *
 * A domain is considered as existing if:
 *
 * - It is found either in the domain table or in the domain_aliasses table
 * - It is a subzone of another domain which doesn't belong to the given reseller
 * - It already exist as subdomain (whatever the subdomain type (sub,alssub)
 *
 * @param string $domainName Domain name to match
 * @param int $resellerId Reseller unique identifier
 * @return bool TRUE if the domain already exist, FALSE otherwise
 */
function imscp_domain_exists($domainName, $resellerId)
{
	// Be sure to work with ASCII domain name
	$domainName = encode_idna($domainName);

	// Does the domain already exist in the domain table?
	$stmt = exec_query('SELECT COUNT(domain_id) AS cnt FROM domain WHERE domain_name = ?', $domainName);
	$row = $stmt->fetchRow(PDO::FETCH_ASSOC);
	if($row['cnt']) return true;

	// Does the domain already exists in the domain_aliasses table?
	$stmt = exec_query(
		'SELECT COUNT(alias_id) AS cnt FROM domain_aliasses INNER JOIN domain USING(domain_id) WHERE alias_name = ?',
		$domainName
	);
	$row = $stmt->fetchRow(PDO::FETCH_ASSOC);
	if($row['cnt']) return true;

	# Does the domain is a subzone of another domain which doesn't belong to the given reseller?

	$queryDomain = '
		SELECT
			COUNT(domain_id) AS cnt
		FROM
			domain
		INNER JOIN
			admin ON(admin_id = domain_admin_id)
		WHERE
			domain_name = ?
		AND
			created_by <> ?
	';

	$queryAliases = '
		SELECT
			COUNT(alias_id) AS cnt
		FROM
			domain_aliasses
		INNER JOIN
			domain USING(domain_id)
		INNER JOIN
			admin ON(admin_id = domain_admin_id)
		WHERE
			alias_name = ?
		AND
			created_by <> ?
	';

	$domainLabels = explode('.', trim($domainName));
	$domainPartCnt = 0;

	for ($i = 0, $countDomainLabels = count($domainLabels) - 1; $i < $countDomainLabels; $i++) {
		$domainPartCnt = $domainPartCnt + strlen($domainLabels[$i]) + 1;
		$parentDomain = substr($domainName, $domainPartCnt);

		// Execute query the redefined queries for domains/accounts and aliases tables
		$stmt = exec_query($queryDomain, array($parentDomain, $resellerId));
		$row = $stmt->fetchRow(PDO::FETCH_ASSOC);
		if($row['cnt']) return true;

		$stmt = exec_query($queryAliases, array($parentDomain, $resellerId));
		$row = $stmt->fetchRow(PDO::FETCH_ASSOC);
		if($row['cnt']) return true;
	}

	// Does the domain already exists as subdomain?

	$stmt = exec_query(
		"
			SELECT
				'found'
			FROM
				subdomain
			INNER JOIN
				domain USING(domain_id)
			WHERE
				CONCAT(subdomain_name, '.', domain_name) = ?
		",
		 $domainName
	);
	if($stmt->rowCount()) return true;

	$stmt = exec_query(
		"
			SELECT
				'found'
			FROM
				subdomain_alias
			INNER JOIN
				domain_aliasses USING(alias_id)
			WHERE
				CONCAT(subdomain_alias_name, '.', alias_name) = ?
		",
		$domainName
	);
	if($stmt->rowCount()) return true;

	return false;
}

/**
 * Returns domain default properties
 *
 * Note: For performance reasons, the data are retrieved once per request.
 *
 * @param int $domainAdminId Customer unique identifier
 * @param int|null $createdBy OPTIONAL reseller unique identifier
 * @return array Returns an associative array where each key is a domain propertie name.
 */
function get_domain_default_props($domainAdminId, $createdBy = null)
{
	static $domainProperties = null;

	if (null === $domainProperties) {
		if(is_null($createdBy)) {
			$stmt = exec_query('SELECT * FROM domain WHERE domain_admin_id = ?', $domainAdminId);
		} else {
			$stmt = exec_query(
				'
					SELECT
						*
					FROM
						domain
					INNER JOIN
						admin ON(admin_id = domain_admin_id)
					WHERE
						domain_admin_id = ?
					AND
						created_by = ?
				',
				array($domainAdminId, $createdBy)
			);
		}

		if(!$stmt->rowCount()) {
			showBadRequestErrorPage();
		}

		$domainProperties = $stmt->fetchRow(PDO::FETCH_ASSOC);
	}

	return $domainProperties;
}

/**
 * Return main domain unique identifier of the given customer
 *
 * @throws iMSCP_Exception in case the domain id cannot be found
 * @param int $customeId Customer unique identifier
 * @return int main domain unique identifier
 */
function get_user_domain_id($customeId)
{
	static $domainId = null;

	if(null === $domainId) {
		$query = 'SELECT `domain_id` FROM `domain` WHERE `domain_admin_id` = ?';
		$stmt = exec_query($query, $customeId);

		if($stmt->rowCount()) {
			$domainId = $stmt->fields['domain_id'];
		} else {
			throw new iMSCP_Exception("Unable to found domain ID of user with ID '$customeId''");
		}
	}

	return $domainId;
}

/**
 * Get the total number of consumed and max available items for the given customer
 *
 * @param  int $userId Domain unique identifier
 * @return array
 */
function shared_getCustomerProps($userId)
{
	$cfg = iMSCP_Registry::get('config');

	$stmt = exec_query("SELECT * FROM domain WHERE domain_admin_id = ?", $userId);

	if (!$stmt->rowCount()) {
		return array_fill(0, 14, 0);
	}

	$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

	// Retrieves total number of subdomains already consumed by the customer
	$subConsumed = records_count('subdomain', 'domain_id', $row['domain_id']);

	// Retrieves max available number of subdomains for the customer
	$subMax = $row['domain_subd_limit'];

	// Retrieves total number of domain aliases already consumed by the customer
	$alsConsumed = records_count('domain_aliasses', 'domain_id', $row['domain_id']);

	// Retrieves max available number of domain aliases for the customer
	$alsMax = $row['domain_alias_limit'];

	// Retrieves total number of mail accounts already consumed by the customer
	// This works with the admin option (Count default email addresses)
	if ($cfg['COUNT_DEFAULT_EMAIL_ADDRESSES']) {
		$mailConsumed = records_count('mail_users', "mail_type NOT RLIKE '_catchall' AND domain_id", $row['domain_id']);
	} else {
		$where = "
				`mail_acc` != 'abuse'
			AND
				`mail_acc` != 'postmaster'
			AND
				`mail_acc` != 'webmaster'
			AND
				`mail_type` NOT RLIKE '_catchall'
			AND
				`domain_id`
		";

		$mailConsumed = records_count('mail_users', $where, $row['domain_id']);
	}

	// Retrieves max available number of mail accounts for the customer
	$mailMax = $row['domain_mailacc_limit'];

	// Retrieve total number of ftp accounts already consumed by the customer
	$ftpConsumed = sub_records_rlike_count(
		'domain_name', 'domain', 'domain_id', $row['domain_id'], 'userid', 'ftp_users', 'userid', '@', ''
	);
	$ftpConsumed += sub_records_rlike_count(
		'alias_name', 'domain_aliasses', 'domain_id', $row['domain_id'], 'userid', 'ftp_users', 'userid', '@', ''
	);

	// Retrieves max available number of mail accounts for the customer
	$ftpMax = $row['domain_ftpacc_limit'];

	// Retrieves total number of SQL databases already consumed by the customer
	$sqlDbConsumed = records_count('sql_database', 'domain_id', $row['domain_id']);

	// Retrieves max available number of SQL databases for the customer
	$sqlDbMax = $row['domain_sqld_limit'];

	// Retrieves total number of SQL user already consumed by the customer
	$sqlUserConsumed = sub_records_count(
		'sqld_id', 'sql_database', 'domain_id', $row['domain_id'], 'sqlu_id', 'sql_user', 'sqld_id', 'sqlu_name', ''
	);

	// Retrieves max number of SQL user for the customer
	$sqlUserMax = $row['domain_sqlu_limit'];

	// Retrieves max available montly traffic volume for the customer
	$trafficMax = $row['domain_traffic_limit'];

	// Retrieve max available diskspace limit for the customer
	$diskMax = $row['domain_disk_limit'];

	return array(
		$subConsumed, $subMax, $alsConsumed, $alsMax, $mailConsumed, $mailMax, $ftpConsumed, $ftpMax, $sqlDbConsumed,
		$sqlDbMax, $sqlUserConsumed, $sqlUserMax, $trafficMax, $diskMax
	);
}

/**
 * Returns translated item status
 *
 * @param string $status Item status to translate
 * @return string Translated status
 */
function translate_dmn_status($status)
{
	switch ($status) {
		case 'ok':
			return tr('Ok');
		case 'toadd':
			return tr('Addition in progress...');
		case 'tochange':
			return tr('Modification in progress...');
		case 'todelete':
			return tr('Deletion in progress...');
		case 'disabled':
			return tr('Deactivated');
		case 'toenable':
			return tr('Activation in progress...');
		case 'todisable':
			return tr('Deactivation in progress...');
		case 'ordered':
			return tr('Awaiting for approval');
		default:
			return tr('Unexpected error');
	}
}

/**
 * Recalculates limits for the given reseller
 *
 * Important:
 *
 * This is not based on the objects consumed by customers. This is based on objects assigned by the reseller to its
 * customers.
 *
 * @param int $resellerId unique reseller identifier
 * @return void
 */
function update_reseller_c_props($resellerId)
{
	exec_query(
		"
			UPDATE
				reseller_props AS t1
			INNER JOIN (
				SELECT
					COUNT(domain_id) AS dmn_count,
					IFNULL(SUM(IF(domain_subd_limit >= 0, domain_subd_limit, 0)), 0) AS sub_count,
					IFNULL(SUM(IF(domain_alias_limit >= 0, domain_alias_limit, 0)), 0) AS als_limit,
					IFNULL(SUM(IF(domain_mailacc_limit >= 0, domain_mailacc_limit, 0)), 0) AS mail_limit,
					IFNULL(SUM(IF(domain_ftpacc_limit >= 0, domain_ftpacc_limit, 0)), 0) AS ftp_limit,
					IFNULL(SUM(IF(domain_sqld_limit >= 0, domain_sqld_limit, 0)), 0) AS sqld_limit,
					IFNULL(SUM(IF(domain_sqlu_limit >= 0, domain_sqlu_limit, 0)), 0) AS sqlu_limit,
					IFNULL(SUM(domain_disk_limit), 0) AS disk_limit,
					IFNULL(SUM(domain_traffic_limit), 0) AS traffic_limit,
					created_by
				FROM
					domain
				LEFT JOIN
					admin ON(domain_admin_id = admin_id)
				WHERE
					domain_status <> 'todelete'
				AND
					created_by = :reseller_id
			) as t2
			SET
				t1.current_dmn_cnt = t2.dmn_count,
				t1.current_sub_cnt = t2.sub_count,
				t1.current_als_cnt = t2.als_limit,
				t1.current_mail_cnt = t2.mail_limit,
				t1.current_ftp_cnt = t2.ftp_limit,
				t1.current_sql_db_cnt = t2.sqld_limit,
				t1.current_sql_user_cnt = t2.sqlu_limit,
				t1.current_disk_amnt = t2.disk_limit,
				t1.current_traff_amnt = t2.traffic_limit
			WHERE
				t1.reseller_id = :reseller_id
		",
		array('reseller_id' => $resellerId)
	);
}

/**
 * Activate or deactivate the given customer account
 *
 * @throws iMSCP_Exception|iMSCP_Exception_Database
 * @param int $customerId Customer unique identifier
 * @param string $action Action to schedule
 * @return void
 */
function change_domain_status($customerId, $action)
{
	/** @var $cfg iMSCP_Config_Handler_File */
	$cfg = iMSCP_Registry::get('config');

	if ($action == 'deactivate') {
		$newStatus = 'todisable';
	} else if ($action == 'activate') {
		$newStatus = 'toenable';
	} else {
		throw new iMSCP_Exception("Unknow action: $action");
	}

	$stmt = exec_query(
		'
			SELECT
				domain_id, admin_name
			FROM
				domain
			INNER JOIN
				admin ON(admin_id = domain_admin_id)
			WHERE
				domain_admin_id = ?
		',
		$customerId
	);

	if($stmt->rowCount()) {
		$row = $stmt->fetchRow(PDO::FETCH_ASSOC);
		$domainId = $row['domain_id'];
		$adminName = decode_idna($row['admin_name']);

		/** @var $db iMSCP_Database */
		$db = iMSCP_Database::getInstance();

		try {
			$db->beginTransaction();

			iMSCP_Events_Aggregator::getInstance()->dispatch(
				iMSCP_Events::onBeforeChangeDomainStatus, array('customerId' => $customerId, 'action' => $action)
			);

			if($action == 'deactivate') {
				if($cfg['HARD_MAIL_SUSPENSION']) { # SMTP/IMAP/POP disabled
					exec_query(
						'UPDATE mail_users SET status = ?, po_active = ? WHERE domain_id = ?',
						array( 'todisable', 'no', $domainId)
					);
				} else { # IMAP/POP disabled
					exec_query(
						'UPDATE mail_users SET po_active = ? WHERE domain_id = ?', array( 'no', $domainId)
					);
				}
			} else {
				exec_query(
					'UPDATE mail_users SET status = ?, po_active = ? WHERE domain_id = ? AND status = ?',
					array( 'toenable', 'yes', $domainId, 'disabled')
				);
				exec_query(
					'UPDATE mail_users SET po_active = ? WHERE domain_id = ? AND status <> ?',
					array('yes', $domainId, 'disabled')
				);
			}

			# TODO implements customer deactivation
			# exec_query('UPDATE admin SET admin_status = ? WHERE admin_id = ?', array($newStatus, $customerId));
			exec_query("UPDATE domain SET domain_status = ? WHERE domain_id = ?", array($newStatus, $domainId));
			exec_query("UPDATE subdomain SET subdomain_status = ? WHERE domain_id = ?", array($newStatus, $domainId));
			exec_query("UPDATE domain_aliasses SET alias_status = ? WHERE domain_id = ?", array($newStatus, $domainId));
			exec_query(
				'
					UPDATE
						subdomain_alias
					INNER JOIN
						domain_aliasses USING(alias_id)
					SET
						subdomain_alias_status = ?
					WHERE
						domain_id = ?
				',
				array($newStatus, $domainId)
			);
			exec_query('UPDATE domain_dns SET domain_dns_status = ? WHERE domain_id = ?', array($newStatus, $domainId));

			iMSCP_Events_Aggregator::getInstance()->dispatch(
				iMSCP_Events::onAfterChangeDomainStatus, array('customerId' => $customerId, 'action' => $action)
			);

			$db->commit();
		} catch (iMSCP_Exception $e) {
			$db->rollBack();
			throw $e;
		}

		// Send request to i-MSCP daemon
		send_request();

		if ($action == 'deactivate') {
			write_log(
				sprintf("%s: scheduled deactivation of customer account: %s", $_SESSION['user_logged'], $adminName),
				E_USER_NOTICE
			);
			set_page_message(tr('Customer account successfully scheduled for deactivation.'), 'success');
		} else {
			write_log(
				sprintf("%s: scheduled activation of customer account: %s",$_SESSION['user_logged'], $adminName),
				E_USER_NOTICE
			);
			set_page_message(tr('Customer account successfully scheduled for activation.'), 'success');
		}
	} else {
		throw new iMSCP_Exception(sprintf("Unable to found domain for user with ID %s", $customerId));
	}
}

/**
 * Deletes an SQL user
 *
 * @throws iMSCP_Exception_Database
 * @param  int $domainId Domain unique identifier
 * @param  int $sqlUserId Sql user unique identifier
 * @param bool $flushPrivileges Whether or not privilege must be flushed
 * @return bool TRUE if $sqlUserId has been found and successfully removed, FALSE otherwise
 */
function sql_delete_user($domainId, $sqlUserId, $flushPrivileges = true)
{
	iMSCP_Events_Aggregator::getInstance()->dispatch(
		iMSCP_Events::onBeforeDeleteSqlUser, array('sqlUserId' => $sqlUserId)
	);

	$db = iMSCP_Database::getInstance();

	try {
		$db->beginTransaction();

		$stmt = exec_query(
			'
				SELECT
					sqlu_name, sqlu_host, sqld_name
				FROM
					sql_user
				INNER JOIN
					sql_database USING(sqld_id)
				WHERE
					sqlu_id = ?
				AND
					domain_id = ?
			',
			array($sqlUserId, $domainId)
		);

		if (!$stmt->rowCount()) {
			return false;
		}

		$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

		$sqlUserName = $row['sqlu_name'];
		$sqlUserHost = $row['sqlu_host'];
		$sqlDbName = $row['sqld_name'];

		$stmt = exec_query(
			'SELECT COUNT(sqlu_id) AS cnt FROM sql_user WHERE sqlu_name = ? AND sqlu_host = ?',
			array($sqlUserName, $sqlUserHost)
		);

		$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

		if ($row['cnt'] == '1') {
			// SQL user is assigned to one database only. We can remove it completely
			exec_query('DELETE FROM mysql.user WHERE User = ? AND Host = ?', array($sqlUserName, $sqlUserHost));
			exec_query('DELETE FROM mysql.db WHERE Host = ? AND User = ?', array($sqlUserHost, $sqlUserName));
		} else {
			// SQL user is assigned to many databases. We remove its privileges for the involved database only
			exec_query(
				'DELETE FROM mysql.db WHERE Host = ? AND Db = ? AND User = ?',
				array($sqlUserHost, $sqlDbName, $sqlUserName)
			);
		}

		// Delete SQL user from i-MSCP database
		exec_query('DELETE FROM sql_user WHERE sqlu_id = ?', $sqlUserId);

		$db->commit();

		// Flush SQL privileges
		if($flushPrivileges) {
			execute_query('FLUSH PRIVILEGES');
		}

		iMSCP_Events_Aggregator::getInstance()->dispatch(
			iMSCP_Events::onAfterDeleteSqlUser, array('sqlUserId' => $sqlUserId)
		);
	} catch(iMSCP_Exception_Database $e) {
		$db->rollBack();
		throw $e;
	}

	return true;
}

/**
 * Deletes the given SQL database
 *
 * @throws iMSCP_Exception in case an SQL user which belong to the given database cannot be removed
 * @param  int $domainId Domain unique identifier
 * @param  int $databaseId Databse unique identifier
 * @return bool TRUE when $databaseId has been found and successfully deleted, FALSE otherwise
 */
function delete_sql_database($domainId, $databaseId)
{
	$db = iMSCP_Database::getInstance();

	iMSCP_Events_Aggregator::getInstance()->dispatch(iMSCP_Events::onBeforeDeleteSqlDb, array('sqlDbId' => $databaseId));

	try {
		$db->beginTransaction();

		// Get name of database
		$stmt = exec_query(
			'SELECT sqld_name FROM sql_database WHERE domain_id = ? AND sqld_id = ?', array($domainId, $databaseId)
		);

		if ($stmt->rowCount()) {
			$row = $stmt->fetchRow(PDO::FETCH_ASSOC);
			$databaseName = quoteIdentifier($row['sqld_name']);

			// Get list of SQL users assigned to the database being removed

			$stmt = exec_query(
				'
					SELECT
						sqlu_id
					FROM
						sql_user
					INNER JOIN
						sql_database USING(sqld_id)
					WHERE
						sqld_id = ?
					AND
						domain_id = ?
				',
				array($databaseId, $domainId)
			);

			if ($stmt->rowCount()) {
				while ($row = $stmt->fetchRow(PDO::FETCH_ASSOC)) {
					if (!sql_delete_user($domainId, $row['sqlu_id'], false)) {
						return false;
					}
				}
			}

			exec_query('DELETE FROM sql_database WHERE domain_id = ? AND sqld_id = ?', array($domainId, $databaseId));

			// Must be done last due to the implicit commit
			execute_query("DROP DATABASE IF EXISTS $databaseName");
			execute_query('FLUSH PRIVILEGES');

			iMSCP_Events_Aggregator::getInstance()->dispatch(
				iMSCP_Events::onAfterDeleteSqlDb, array('sqlDbId' => $databaseId)
			);

			return true;
		}
	} catch(iMSCP_Exception_Database $e) {
		$db->rollBack();
		throw $e;
	}

	return false;
}

/**
 * Deletes the given customer
 *
 * @throws iMSCP_Exception
 * @param integer $customerId Customer unique identifier
 * @param boolean $checkCreatedBy Tell whether or not customer must have been created by logged-in user
 * @return bool TRUE on success, FALSE otherwise
 */
function deleteCustomer($customerId, $checkCreatedBy = false)
{
	$customerId = (int)$customerId;

	$cfg = iMSCP_Registry::get('config');

	iMSCP_Events_Aggregator::getInstance()->dispatch(
		iMSCP_Events::onBeforeDeleteCustomer, array('customerId' => $customerId)
	);

	// Get username, uid and gid of domain user
	$query = '
		SELECT
			admin_name, created_by, domain_id
		FROM
			admin
		INNER JOIN
			domain ON(domain_admin_id = admin_id)
		WHERE
			admin_id = ?
	';

	if ($checkCreatedBy) {
		$query .= 'AND created_by = ?';
		$stmt = exec_query($query, array($customerId, $_SESSION['user_id']));
	} else {
		$stmt = exec_query($query, $customerId);
	}

	if (!$stmt->rowCount()) {
		return false;
	}

	$customerName = $stmt->fields['admin_name'];
	$mainDomainId = $stmt->fields['domain_id'];
	$resellerId = $stmt->fields['created_by'];
	$deleteStatus = 'todelete';

	$db = iMSCP_Database::getInstance();

	try {
		// First, remove customer sessions to prevent any problems
		exec_query('DELETE FROM login WHERE user_name = ?', $customerName);

		// Remove customer's databases and Sql users
		$stmt = exec_query('SELECT sqld_id FROM sql_database WHERE domain_id = ?', $mainDomainId);

		while ($row = $stmt->fetchRow(PDO::FETCH_ASSOC)) {
			delete_sql_database($mainDomainId, $row['sqld_id']);
		}

		$db->beginTransaction();

		// Deletes all protected areas data (areas, groups and users)

		exec_query(
			'
				DELETE
					t2, t3, t4
				FROM
					domain AS t1
				LEFT JOIN
					htaccess AS t2 ON (t2.dmn_id = t1.domain_id)
				LEFT JOIN
					htaccess_users AS t3 ON (t3.dmn_id = t1.domain_id)
				LEFT JOIN
					htaccess_groups AS t4 ON (t4.dmn_id = t1.domain_id)
				WHERE
					t1.domain_id = ?
			',
			$mainDomainId
		);

		// Deletes domain traffic entries
		exec_query('DELETE FROM domain_traffic WHERE domain_id = ?', $mainDomainId);

		// Deletes custom DNS records
		exec_query('DELETE FROM domain_dns WHERE domain_id = ?', $mainDomainId);

		// Deletes FTP accounts (users and groups)

		if($cfg['FTPD_SERVER'] == 'vsftpd') {
			exec_query('UPDATE ftp_users SET status = ? WHERE admin_id = ?', array('todelete', $customerId));
		} else {
			exec_query('DELETE FROM ftp_users WHERE admin_id = ?', $customerId);
		}

		exec_query('DELETE FROM ftp_group WHERE groupname = ?', $customerName);

		// Deletes quota entries
		exec_query('DELETE FROM quotalimits WHERE name = ?', $customerName);
		exec_query('DELETE FROM quotatallies WHERE name = ?', $customerName);

		// Deletes support tickets
		exec_query('DELETE FROM tickets WHERE ticket_from = ? OR ticket_to = ?', array($customerId, $customerId));

		// Deletes user gui properties
		exec_query('DELETE FROM user_gui_props WHERE user_id = ?', $customerId);

		// Deletes own php.ini entry
		exec_query('DELETE FROM php_ini WHERE domain_id = ?', $mainDomainId);

		// Delegated tasks - begin

		// Schedule mail accounts deletion
		exec_query('UPDATE mail_users SET status = ? WHERE domain_id = ?', array($deleteStatus, $mainDomainId));

		// Schedule subdomain's aliasses deletion

		exec_query(
			'
				UPDATE
					subdomain_alias AS t1
				JOIN
					domain_aliasses AS t2 ON(t2.domain_id = ?)
				SET
					t1.subdomain_alias_status = ?
				WHERE
					t1.alias_id = t2.alias_id
			',
			array($mainDomainId, $deleteStatus)
		);

		// Schedule Domain aliases deletion
		exec_query(
			'UPDATE domain_aliasses SET alias_status = ? WHERE domain_id = ?', array($deleteStatus, $mainDomainId)
		);

		// Schedule domain's subdomains deletion
		exec_query(
			'UPDATE subdomain SET subdomain_status = ? WHERE domain_id = ?', array($deleteStatus, $mainDomainId)
		);

		// Schedule domain deletion
		exec_query('UPDATE domain SET domain_status = ? WHERE domain_id = ?', array($deleteStatus, $mainDomainId));

		// Schedule user deletion
		exec_query('UPDATE admin SET admin_status = ? WHERE admin_id = ?', array($deleteStatus, $customerId));

		// Schedule SSL certificates deletion
		exec_query(
			"UPDATE ssl_certs SET status = ? WHERE domain_type = 'dmn' AND domain_id = ?",
			array($deleteStatus, $mainDomainId)
		);
		exec_query(
			"
				UPDATE
					ssl_certs
				SET
					status = ?
				WHERE
					domain_id IN (SELECT alias_id FROM domain_aliasses WHERE domain_id = ?)
				AND
					domain_type = ?
			",
			array($deleteStatus, $mainDomainId, 'als')
		);
		exec_query(
			"
				UPDATE
					ssl_certs SET status = ?
				WHERE
					domain_id IN (SELECT subdomain_id FROM subdomain WHERE domain_id = ?)
				AND
					domain_type = ?
			",
			array($deleteStatus, $mainDomainId, 'sub')
		);
		exec_query(
			"
				UPDATE
					ssl_certs SET status = ?
				WHERE
					domain_id IN (
						SELECT
							subdomain_alias_id
						FROM
							subdomain_alias
						WHERE
							alias_id IN (SELECT alias_id FROM domain_aliasses WHERE domain_id = ?)
					)
				AND
					domain_type = ?
			",
			array($deleteStatus, $mainDomainId, 'alssub')
		);

		// Delegated tasks - end

		// Updates resellers properties
		update_reseller_c_props($resellerId);

		// Commit all changes to database server
		$db->commit();

		iMSCP_Events_Aggregator::getInstance()->dispatch(
			iMSCP_Events::onAfterDeleteCustomer, array('customerId' => $customerId)
		);
	} catch (iMSCP_Exception $e) {
		$db->rollBack();
		throw new iMSCP_Exception($e->getMessage(), $e->getCode(), $e);
	}

	// We are now ready to send a request to the daemon for delegated tasks.
	// Note: We are safe here. If the daemon doesn't answer, some entities will not be removed. In such case the
	// sysadmin will have to fix the problem causing deletion break and send a request to the daemon manually via the
	// panel, or run the imscp-rqst-mngr script manually.
	send_request();

	return true;
}

/**
 * Delete the given domain alias (including any related entities)
 *
 * @throws iMSCP_Exception_Database
 * @param int $aliasId Domain alias unique identifier
 * @param string $aliasName Domain alias name
 * @throws iMSCP_Exception
 */
function deleteDomainAlias($aliasId, $aliasName)
{
	iMSCP_Events_Aggregator::getInstance()->dispatch(
		iMSCP_Events::onBeforeDeleteDomainAlias, array('domainAliasId' => $aliasId, 'domainAliasName' => $aliasName)
	);


	$cfg = iMSCP_Registry::get('config');

	/** @var $db iMSCP_Database */
	$db = iMSCP_Database::getInstance();

	try {
		$db->beginTransaction();

		// Delete any FTP account that belongs to the domain alias

		$stmt = exec_query(
			"
				SELECT
					t1.groupname, t1.gid, t1.members
				FROM
					ftp_group AS t1
				LEFT JOIN
					domain_aliasses AS t3 ON(alias_id = ?)
				LEFT JOIN
					subdomain_alias AS t4 ON(t4.alias_id = t3.alias_id)
				LEFT JOIN
					ftp_users AS t2 ON(
						userid LIKE CONCAT('%@', t4.subdomain_alias_name, '.', t3.alias_name)
						OR
						userid LIKE CONCAT('%@', t3.alias_name)
					)
				WHERE
					t1.gid = t2.gid
				LIMIT
					1
			",
			$aliasId
		);

		if ($stmt->rowCount()) {
			$row = $stmt->fetchRow(PDO::FETCH_ASSOC);
			$ftpGname = $row['groupname'];
			$ftpGgid = $row['gid'];
			$ftpMembers = preg_split('/,/', $row['members'], -1, PREG_SPLIT_NO_EMPTY);

			$nFtpMembers = array();
			foreach ($ftpMembers as $ftpMember) {
				if (!preg_match("/@(?:.+?\\.)*$aliasName$/", $ftpMember)) {
					$nFtpMembers[] = $ftpMember;
				}
			}

			if (!empty($nFtpMembers)) {
				exec_query('UPDATE ftp_group SET members = ? WHERE gid = ?', array(implode(',', $nFtpMembers), $ftpGgid));
			} else {
				exec_query('DELETE FROM ftp_group WHERE groupname = ?', $ftpGname);
				exec_query('DELETE FROM quotalimits WHERE name = ?', $ftpGname);
				exec_query('DELETE FROM quotatallies WHERE name = ?', $ftpGname);
			}
		}

		if($cfg['FTPD_SERVER'] == 'vsftpd') {
			exec_query(
				"
					UPDATE
						ftp_users
					LEFT JOIN
						domain_aliasses AS t2 ON(alias_id = ?)
					LEFT JOIN
						subdomain_alias USING(alias_id)
					SET
						status = 'todelete'
					WHERE (
						userid LIKE CONCAT('%@', subdomain_alias_name, '.', alias_name)
					OR
						userid LIKE CONCAT('%@', alias_name)
					)
				",
				$aliasId
			);
		} else {
			exec_query(
				"
				DELETE
					ftp_users
				FROM
					ftp_users
				LEFT JOIN
					domain_aliasses ON(alias_id = ?)
				LEFT JOIN
					subdomain_alias USING(alias_id)
				WHERE (
					userid LIKE CONCAT('%@', subdomain_alias_name, '.', alias_name)
				OR
					userid LIKE CONCAT('%@', alias_name)
				)
			",
				$aliasId
			);
		}

		// Delete any custom DNS and external mail server record that belongs to the domain alias
		exec_query('DELETE FROM domain_dns WHERE alias_id = ?', $aliasId);

		// Schedule deletion of any mail account that belongs to the domain alias
		exec_query(
			"
				UPDATE
					mail_users
				SET
					status = ?
				WHERE
					(sub_id = ? AND mail_type LIKE ?)
				OR (
					sub_id IN (SELECT subdomain_alias_id FROM subdomain_alias WHERE alias_id = ?)
				AND
					mail_type LIKE ?
				)
			",
			array('todelete', $aliasId, '%alias_%', $aliasId, '%alssub_%')
		);

		# Schedule deletion of any SSL certificate that belongs to the domain alias

		exec_query(
			'
				UPDATE
					ssl_certs
				SET
					status = ?
				WHERE
					domain_id IN (SELECT subdomain_alias_id FROM subdomain_alias WHERE alias_id = ?)
				AND
					domain_type = ?
			',
			array('todelete', 'alssub', $aliasId)
		);

		exec_query(
			'UPDATE ssl_certs SET status = ? WHERE domain_id = ? and domain_type = ?', array('todelete', $aliasId, 'als')
		);

		# Schedule deletion of any subdomain that belongs to the domain alias
		exec_query(
			'UPDATE subdomain_alias SET subdomain_alias_status = ? WHERE alias_id = ?', array('todelete', $aliasId)
		);

		# Schedule deletion of the domain alias
		exec_query('UPDATE domain_aliasses SET alias_status = ? WHERE alias_id = ?', array('todelete', $aliasId));

		iMSCP_Events_Aggregator::getInstance()->dispatch(
			iMSCP_Events::onAfterDeleteDomainAlias,
			array('domainAliasId' => $aliasId, 'domainAliasName' => $aliasName)
		);

		$db->commit();

		send_request();
		write_log(
			sprintf('%s scheduled deletion of the %s domain alias', decode_idna($_SESSION['user_logged']), $aliasName),
			E_USER_NOTICE
		);
		set_page_message(tr('Domain alias successfully scheduled for deletion.'), 'success');
	} catch (iMSCP_Exception_Database $e) {
		$db->rollBack();
		throw $e;
	}
}

/**
 * Returns number of items in a database table with optional search criterias
 *
 * @param string $field
 * @param string $table
 * @param string $where
 * @param string $value
 * @param string $subfield
 * @param string $subtable
 * @param string $subwhere
 * @param string $subgroupname
 * @return int
 */
function sub_records_count($field, $table, $where, $value, $subfield, $subtable, $subwhere, $subgroupname)
{
	if ($where != '') {
		$query = "SELECT $field AS `field` FROM $table WHERE $where = ?";
		$stmt = exec_query($query, $value);
	} else {
		$query = "SELECT $field AS `field` FROM $table";
		$stmt = execute_query($query);
	}

	$result = 0;

	if (!$stmt->rowCount()) {
		return $result;
	}

	if ($subgroupname != '') {
		$sqld_ids = array();

		while (!$stmt->EOF) {
			array_push($sqld_ids, $stmt->fields['field']);
			$stmt->moveNext();
		}

		$sqld_ids = implode(',', $sqld_ids);

		if ($subwhere != '') {
			$query = "SELECT COUNT(DISTINCT $subgroupname) AS `cnt` FROM $subtable WHERE `sqld_id` IN ($sqld_ids)";
			$subres = execute_query($query);
			$result = $subres->fields['cnt'];
		} else {
			return $result;
		}
	} else {
		while (!$stmt->EOF) {
			$contents = $stmt->fields['field'];

			if ($subwhere != '') {
				$query = "SELECT COUNT(*) AS `cnt` FROM $subtable WHERE $subwhere = ?";
			} else {
				return $result;
			}

			$subres = exec_query($query, $contents);
			$result += $subres->fields['cnt'];
			$stmt->moveNext();
		}
	}

	return $result;
}

/**
 * Must be documented
 *
 * @param string $field
 * @param string $table
 * @param string $where
 * @param string $value
 * @param string $subfield
 * @param string $subtable
 * @param string $subwhere
 * @param string $a
 * @param string $b
 * @return int
 */
function sub_records_rlike_count($field, $table, $where, $value, $subfield, $subtable, $subwhere, $a, $b)
{

	if ($where != '') {
		$stmt = exec_query("SELECT $field AS `field` FROM $table WHERE $where = ?", $value);
	} else {
		$stmt = execute_query("SELECT $field AS `field` FROM $table");
	}

	$result = 0;

	if (!$stmt->rowCount()) {
		return $result;
	}

	while (!$stmt->EOF) {
		$contents = $stmt->fields['field'];

		if ($subwhere != '') {
			$query = "SELECT COUNT(*) AS `cnt` FROM $subtable WHERE $subwhere RLIKE ?";
		} else {
			return $result;
		}

		$stmt2 = exec_query($query, $a . $contents . $b);
		$result += $stmt2->fields['cnt'];
		$stmt->moveNext();
	}

	return $result;
}

/***********************************************************************************************************************
 * Reseller related functions
 */

/**
 * Returns properties for the given reseller
 *
 * @throws iMSCP_Exception When reseller properties are not found
 * @param int $resellerId Reseller unique identifier
 * @param bool $forceReload Whether or not force properties reload from database
 * @return array
 */
function imscp_getResellerProperties($resellerId, $forceReload = false)
{
	static $properties = null;

	if (null === $properties || $forceReload) {
		$stmt = exec_query('SELECT * FROM reseller_props WHERE reseller_id = ? LIMIT 1', $resellerId);

		if (!$stmt->rowCount()) {
			throw new iMSCP_Exception(tr('Properties for reseller with ID %d were not found in database.', $resellerId));
		}

		$properties = $stmt->fetchRow(PDO::FETCH_ASSOC);
	}

	return $properties;
}

/**
 * Update reseller properties
 *
 * @param  int $resellerId Reseller unique identifier.
 * @param  array $props Array that contain new properties values
 * @return iMSCP_Database_ResultSet|null
 */
function update_reseller_props($resellerId, $props)
{
	if (empty($props)) {
		return null;
	}

	list(
		$dmnCur, $dmnMax, $subCur, $subMax, $alsCur, $alsMax, $mailCur, $mailMax, $ftpCur, $ftpMax, $sqlDbCur,
		$sqlDbMax, $sqlUserCur, $sqlUserMax, $traffCur, $traffMax, $diskCur, $diskMax
	) = explode(';', $props);

	$stmt = exec_query(
		'
			UPDATE
				reseller_props
			SET
				current_dmn_cnt = ?, max_dmn_cnt = ?, current_sub_cnt = ?, max_sub_cnt = ?, current_als_cnt = ?,
				max_als_cnt = ?, current_mail_cnt = ?, max_mail_cnt = ?, current_ftp_cnt = ?, max_ftp_cnt = ?,
				current_sql_db_cnt = ?, max_sql_db_cnt = ?, current_sql_user_cnt = ?, max_sql_user_cnt = ?,
				current_traff_amnt = ?, max_traff_amnt = ?, current_disk_amnt = ?, max_disk_amnt = ?
			WHERE
				reseller_id = ?
		',
		array(
			$dmnCur, $dmnMax, $subCur, $subMax, $alsCur, $alsMax, $mailCur, $mailMax, $ftpCur, $ftpMax, $sqlDbCur,
			$sqlDbMax, $sqlUserCur, $sqlUserMax, $traffCur, $traffMax, $diskCur, $diskMax, $resellerId
		)
	);

	return $stmt;
}

/***********************************************************************************************************************
 * Mail functions
 */

/**
 * Encode a string to be valid as mail header
 *
 * @source php.net/manual/en/function.mail.php
 *
 * @param string $string String to be encoded [should be in the $charset charset]
 * @param string $charset OPTIONAL charset in that string will be encoded
 * @return string encoded string
 */
function encode_mime_header($string, $charset = 'UTF-8')
{
	$string = (string)$string;

	if ($string && $charset) {
		if(function_exists('mb_encode_mimeheader')) {
			$string = mb_encode_mimeheader($string, $charset, 'Q', "\r\n", 8);
		} elseif ($string && $charset) {
			// define start delimiter, end delimiter and spacer
			$end = '?=';
			$start = '=?' . $charset . '?B?';
			$spacer = $end . "\r\n " . $start;

			// Determine length of encoded text withing chunks and ensure length is even
			$length = 75 - strlen($start) - strlen($end);
			$length = floor($length / 4) * 4;

			// Encode the string and split it into chunks with spacers after each chunk
			$string = base64_encode($string);
			$string = chunk_split($string, $length, $spacer);

			// Remove trailing spacer and add start and end delimiters
			$spacer = preg_quote($spacer);
			$string = preg_replace('/' . $spacer . '$/', '', $string);
			$string = $start . $string . $end;
		}
	}

	return $string;
}

/**
 * Synchronizes mailboxes quota that belong to the given domain using the given quota limit
 *
 * Algorythm:
 *
 * 1. In case the new quota limit is 0 (unlimited), equal or bigger than the sum of current quotas, we do nothing
 * 2. We have a running total, which start at zero
 * 3. We divide the quota of each mailbox by the sum of current quotas, then we multiply the result by the new quota limit
 * 4. We store the original value of the running total elsewhere, then we add the amount we have just calculated in #3
 * 5. We ensure that new quota is a least 1 MiB (each mailbox must have 1 MiB minimum quota)
 * 5. We round both old value and new value of the running total to integers, and take the difference
 * 6. We update the mailbox quota result calculated in step 5
 * 7. We repeat steps 3-6 for each quota
 *
 * This algorythm guarantees to have the total amount prorated equal to the sum of all quota after update. It also
 * ensure that each mailboxes has 1 MiB quota minimum.
 *
 * Note:  For the sum calculation of current quotas, we consider that a mailbox with a value equal to 0 (unlimited) is
 * equal to the new quota limit.
 *
 * @author Laurent Declercq <l.declercq@nuxwin.com>
 * @param int $domainId Customer main domain unique identifier
 * @param int $newQuota New quota limit in bytes
 * @return void
 */
function sync_mailboxes_quota($domainId, $newQuota)
{
	if ($newQuota != 0) {
		/** @var iMSCP_Config_Handler_File $cfg */
		$cfg = iMSCP_Registry::get('config');

		$stmt = exec_query(
			'SELECT `mail_id`, `quota` FROM `mail_users` WHERE `domain_id` = ? AND `quota` IS NOT NULL', $domainId
		);

		if($stmt->rowCount()) {
			$mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);
			$totalQuota = 0;

			foreach ($mailboxes as $mailbox) {
				$totalQuota += ($mailbox['quota'] == 0) ? $newQuota : $mailbox['quota'];
			}

			$totalQuota /= 1048576;
			$newQuota /= 1048576;

			if (
				$newQuota < $totalQuota || (isset($cfg['EMAIL_QUOTA_SYNC_MODE']) && $cfg['EMAIL_QUOTA_SYNC_MODE']) ||
				$totalQuota == 0
			) {
				$db = iMSCP_Database::getRawInstance();
				$stmt = $db->prepare('UPDATE `mail_users` SET `quota` = ? WHERE `mail_id` = ?');
				$result = 0;

				foreach ($mailboxes as $mailbox) {
					$oldResult = $result;
					$mailboxQuota = (($mailbox['quota']) ? $mailbox['quota'] / 1048576  : $newQuota);
					$result += $newQuota * $mailboxQuota / $totalQuota;
					if($result < 1) $result = 1;
					$stmt->execute(array(((int)$result - (int)$oldResult) * 1048576, $mailbox['mail_id']));
				}
			}
		}
	}
}

/***********************************************************************************************************************
 * Utils functions
 */

/**
 * Redirect to the given location
 *
 * @param string $location URL to redirect to
 * @return void
 */
function redirectTo($location)
{
	header('Location: ' . $location);
	exit;
}

/**
 * Should be documented
 *
 * @param  $array
 * @param bool $asPath
 * @return string
 */
function array_decode_idna($array, $asPath = false)
{
	if ($asPath && !is_array($array)) {
		return implode('/', array_decode_idna(explode('/', $array)));
	}

	foreach ($array as $k => $v) {
		$arr[$k] = decode_idna($v);
	}

	return $array;
}

/**
 * Must be documented
 *
 * @param array $array Indexed array that containt
 * @param bool $asPath
 * @return string
 */
function array_encode_idna($array, $asPath = false)
{
	if ($asPath && !is_array($array)) {
		return implode('/', array_encode_idna(explode('/', $array)));
	}

	foreach ($array as $k => $v) {
		$array[$k] = encode_idna($v);
	}

	return $array;
}

/**
 * Convert a domain name or email to IDNA ASCII form
 *
 * @param  string String to convert
 * @return bool|string String encoded in ASCII-compatible form or FALSE on failure
 */
function encode_idna($string)
{
	$idn = new idna_convert(array('idn_version' => '2008'));

	return $idn->encode($string);
}

/**
 * Convert a domain name or email from IDNA ASCII to Unicode
 *
 * @param  string String to convert
 * @return bool|string Unicode string or FALSE on failure.
 */
function decode_idna($string)
{

	$idn = new idna_convert(array('idn_version' => '2008'));

	return $idn->decode($string);
}

/**
 * Utils function to upload file
 *
 * @author Laurent Declercq <l.declercq@nuxwin.com>
 * @param string $inputFieldName upload input field name
 * @param string|Array $destPath Destination path string or an array where the first item is an anonymous function to
 *                               run before moving file and any other items the arguments passed to the anonymous
 *                               function. The anonymous function must return a string that is the destination path or
 *                               FALSE on failure.
 *
 * @return string|bool File destination path on success, FALSE otherwise
 */
function utils_uploadFile($inputFieldName, $destPath)
{
	if (isset($_FILES[$inputFieldName]) && $_FILES[$inputFieldName]['error'] == UPLOAD_ERR_OK) {
		$tmpFilePath = $_FILES[$inputFieldName]['tmp_name'];

		if (!is_readable($tmpFilePath)) {
			set_page_message(tr('File is not readable.'), 'error');
			return false;
		}

		if (!is_string($destPath) && is_array($destPath)) {
			if (!($destPath = call_user_func_array(array_shift($destPath), $destPath))) {
				return false;
			}
		}

		if (!@move_uploaded_file($tmpFilePath, $destPath)) {
			set_page_message(tr('Unable to move file.'), 'error');
			return false;
		}
	} else {
		switch ($_FILES[$inputFieldName]['error']) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				set_page_message(tr('File exceeds the size limit.'), 'error');
				break;
			case UPLOAD_ERR_PARTIAL:
				set_page_message(tr('The uploaded file was only partially uploaded.'), 'error');
				break;
			case UPLOAD_ERR_NO_FILE:
				set_page_message(tr('No file was uploaded.'), 'error');
				break;
			case UPLOAD_ERR_NO_TMP_DIR:
				set_page_message(tr('Temporary folder not found.'), 'error');
				break;
			case UPLOAD_ERR_CANT_WRITE:
				set_page_message(tr('Failed to write file to disk.'), 'error');
				break;
			case UPLOAD_ERR_EXTENSION:
				set_page_message(tr('A PHP extension stopped the file upload.'), 'error');
				break;
			default:
				set_page_message(tr('An unknown error occurred during file upload: %s', $_FILES[$inputFieldName]['error']), 'error');
		}

		return false;
	}

	return $destPath;
}

/**
 * Generates a random string
 *
 * @param int $length random string length
 * @return array|string
 */
function utils_randomString($length = 10)
{
	$base = 'ABCDEFGHKLMNOPQRSTWXYZabcdefghjkmnpqrstwxyz123456789';
	$max = strlen($base) - 1;
	$string = '';

	mt_srand((double)microtime() * 1000000);

	while (strlen($string) < $length + 1) {
		$string .= $base{mt_rand(0, $max)};
	}

	return $string;
}

/**
 * Returns Upload max file size in bytes
 *
 * @return int Upload max file size in bytes
 */
function utils_getMaxFileUpload()
{
	$uploadMaxFilesize = utils_getPhpValueInBytes(ini_get('upload_max_filesize'));
	$postMaxSize = utils_getPhpValueInBytes(ini_get('post_max_size'));
	$memoryLimit = utils_getPhpValueInBytes(ini_get('memory_limit'));

	return min($uploadMaxFilesize, $postMaxSize, $memoryLimit);
}

/**
 * Returns PHP directive value in bytes
 *
 * Note: If $value do not come with shorthand byte value, the value is retured as this.
 * See http://fr2.php.net/manual/en/faq.using.php#faq.using.shorthandbytes for further explaination
 *
 * @throws iMSCP_Exception
 * @param int|string PHP directive value
 * @return int Value in bytes
 */
function utils_getPhpValueInBytes($value)
{
	$val = trim($value);
	$last = strtolower($val[strlen($value)-1]);

	switch($last) {
		case 'g':
			$val *= 1024;
		case 'm':
			$val *= 1024;
		case 'k':
			$val *= 1024;
		break;
	}

	return $val;
}

/**
 * Remove the given directory recursively
 *
 * @param string $directory Path of directory to remove
 * @return boolean TRUE on success, FALSE otherwise
 */
function utils_removeDir($directory)
{
	$directory = rtrim($directory, '/');

	if (! is_dir($directory)) {
		return false;
	} elseif (is_readable($directory)) {
		$handle = opendir($directory);

		while (false !== ($item = readdir($handle))) {
			if ($item != '.' && $item != '..') {
				$path = $directory . '/' . $item;

				if (is_dir($path)) {
					utils_removeDir($path);
				} else {
					@unlink($path);
				}
			}
		}

		closedir($handle);

		if (!@rmdir($directory)) {
			return false;
		}
	}

	return true;
}

/**
 * Merge two arrays
 *
 * For duplicate keys, the following is done:
 *  - Nested arrays are recursively merged
 *  - Items in $array2 with INTEGER keys are appended
 *  - Items in $array2 with STRING keys overwrite current values
 *
 * @param array $array1
 * @param array $array2
 * @return array
 */
function utils_arrayMergeRecursive(array $array1, array $array2)
{
	foreach ($array2 as $key => $value) {
		if (array_key_exists($key, $array1)) {
			if (is_int($key)) {
				$array1[] = $value;
			} elseif (is_array($value) && is_array($array1[$key])) {
				$array1[$key] = utils_arrayMergeRecursive($array1[$key], $value);
			} else {
				$array1[$key] = $value;
			}
		} else {
			$array1[$key] = $value;
		}
	}

	return $array1;
}

/**
 * Compares array1 against array2 (recursively) and returns the difference
 *
 * @param array $array1 The array to compare from
 * @param array $array2 An array to compare against
 * @return array An array containing all the entries from array1 that are not present in $array2.
 */
function utils_arrayDiffRecursive(array $array1, array $array2)
{
	$diff = array();

	foreach ($array1 as $key => $value) {
		if (array_key_exists($key, $array2)) {
			if (is_array($value)) {
				$arrDiff = utils_arrayDiffRecursive($value, $array2[$key]);

				if (count($arrDiff)) {
					$diff[$key] = $arrDiff;
				}
			} elseif ($value != $array2[$key]) {
				$diff[$key] = $value;
			}
		} else {
			$diff[$key] = $value;
		}
	}

	return $diff;
}

/***********************************************************************************************************************
 * Checks functions
 */

/**
 * Checks if all of the characters in the provided string are numerical
 *
 * @param string $number string to be checked
 * @return bool TRUE if all characters are numerical, FALSE otherwise
 */
function is_number($number)
{
	return (bool)preg_match('/^[0-9]+$/D', $number);
}

/**
 * Checks if all of the characters in the provided string match like a basic string.
 *
 * @param  $string string to be checked
 * @return bool TRUE if all characters match like a basic string, FALSE otherwise
 */
function is_basicString($string)
{
	return (bool)preg_match('/^[\w\-]+$/D', $string);
}

/**
 * Is the request a Javascript XMLHttpRequest?
 *
 * Returns true if the request‘s "X-Requested-With" header contains "XMLHttpRequest".
 *
 * Note: jQuery and Prototype Javascript libraries sends this header with every Ajax request.
 *
 * @author Laurent Declercq <l.declercq@nuxwin.com>
 * @return boolean  TRUE if the request‘s "X-Requested-With" header contains "XMLHttpRequest", FALSE otherwise
 */
function is_xhr()
{
	if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stristr($_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') !== false) {
		return true;
	}

	return false;
}

/**
 * Check if a data is serialized.
 *
 * @author Laurent Declercq (nuxwin) <l.declercq@nuxwin.com>
 * @param string $data Data to be checked
 * @return boolean TRUE if serialized data, FALSE otherwise
 */
function isSerialized($data)
{
	if (!is_string($data)) {
		return false;
	}

	$data = trim($data);

	if ('N;' == $data) {
		return true;
	}

	if (preg_match("/^[aOs]:[0-9]+:.*[;}]\$/s", $data) ||
		preg_match("/^[bid]:[0-9.E-]+;\$/", $data)
	) {
		return true;
	}

	return false;
}

/**
 * Check if the given string look like json data
 *
 * @author Laurent Declercq (nuxwin) <l.declercq@nuxwin.com>
 * @param $string $string $string to be checked
 * @return boolean TRUE if the given string look like json data, FALSE otherwise
 */
function isJson($string)
{
	json_decode($string);
	return (json_last_error() == JSON_ERROR_NONE);
}

/**
 * Is https secure request
 *
 * @return boolean TRUE if is https secure request, FALSE otherwise
 */
function isSecureRequest()
{
	if (
		(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
		(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
	) {
		return true;
	}

	return false;
}

/**
 * Get URI scheme
 *
 * @return string
 */
function getUriScheme()
{
	return isSecureRequest() ? 'https://' : 'http://';
}

/**
 * Get URI port
 *
 * @return string
 */
function getUriPort()
{
	$config = iMSCP_Registry::get('config');

	return (isSecureRequest())
		? (($config['BASE_SERVER_VHOST_HTTPS_PORT'] == 443 ) ? '' : $config['BASE_SERVER_VHOST_HTTPS_PORT'])
		: (($config['BASE_SERVER_VHOST_HTTP_PORT'] == 80 ) ? '' : $config['BASE_SERVER_VHOST_HTTP_PORT']);
}

/**
 * Get base URL
 *
 * @return string
 */
function getBaseUrl()
{
	$port = getUriPort();
	return getUriScheme() . $_SERVER['SERVER_NAME'] . (($port) ? ':' . $port : '');
}

/***********************************************************************************************************************
 * Accounting related functions
 */

/**
 * Return usage in percent
 *
 * @param  int $amount Current value
 * @param  int $total (0 = unlimited)
 * @return int Usage in percent
 */
function make_usage_vals($amount, $total)
{
	return ($total) ? sprintf('%.2f', (($percent = ($amount / $total) * 100)) > 100 ? 100 : $percent) : 0;
}

/**
 * Get statistiques for the given user
 *
 * @param int $adminId User unique identifier
 * @return array
 */
function shared_getCustomerStats($adminId)
{
	$curMonth = date('m');
	$curYear = date('Y');
	$fromTimestamp = mktime(0, 0, 0, $curMonth, 1, $curYear);

	if ($curMonth == 12) {
		$toTImestamp = mktime(0, 0, 0, 1, 1, $curYear + 1);
	} else {
		$toTImestamp = mktime(0, 0, 0, $curMonth + 1, 1, $curYear);
	}

	$stmt = exec_query(
		'
			SELECT
				domain_id, IFNULL(domain_disk_usage, 0) AS diskspace_usage,
				IFNULL(domain_traffic_limit, 0) AS monthly_traffic_limit,
				IFNULL(domain_disk_limit, 0) AS diskspace_limit,
				admin_name
			FROM
				domain
			INNER JOIN
				admin on(admin_id = domain_admin_id)
			WHERE
				domain_admin_id = ?
			ORDER BY
				domain_name
		',
		$adminId
	);

	if (!$stmt->rowCount()) {
		showBadRequestErrorPage();
	}

	$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

	$diskspaceUsage = $row['diskspace_usage'];
	$monthlyTrafficLimit = $row['monthly_traffic_limit'];
	$diskspaceLimit = $row['diskspace_limit'];
	$adminName = $row['admin_name'];
	$domainId = $row['domain_id'];

	$stmt = exec_query(
		'
			SELECT
				IFNULL(SUM(dtraff_web), 0) AS webTraffic,
				IFNULL(SUM(dtraff_ftp), 0) AS ftpTraffic,
				IFNULL(SUM(dtraff_mail), 0) AS smtpTraffic,
				IFNULL(SUM(dtraff_pop), 0) AS popTraffic,
				IFNULL(SUM(dtraff_web), 0) + IFNULL(SUM(dtraff_ftp), 0) +
				IFNULL(SUM(dtraff_mail), 0) + IFNULL(SUM(dtraff_pop), 0) AS totalTraffic
			FROM
				domain_traffic
			WHERE
				domain_id = ?
			AND
				dtraff_time >= ?
			AND
				dtraff_time < ?
		',
		array($domainId, $fromTimestamp, $toTImestamp)
	);

	$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

	return array(
		$adminName, $domainId, $row['webTraffic'], $row['ftpTraffic'], $row['smtpTraffic'], $row['popTraffic'],
		$row['totalTraffic'], $diskspaceUsage, $monthlyTrafficLimit, $diskspaceLimit
	);
}

/**
 * Must be documented
 *
 * @param  $value
 * @param  $value_max
 * @param  $bar_width
 * @return int
 * @deprecated
 */
function calc_bar_value($value, $value_max, $bar_width)
{
	if ($value_max == 0) {
		return 0;
	} else {
		$ret_value = ($value * $bar_width) / $value_max;
		return ($ret_value > $bar_width) ? $bar_width : $ret_value;
	}
}

/***********************************************************************************************************************
 * Logging related functions
 */

/**
 * Writes a log message in the database and sends it to the administrator by email according log level
 *
 * @param string $msg Message to log
 * @param int $logLevel Log level Loggin level from which log is sent via mail
 * @return void
 */
function write_log($msg, $logLevel = E_USER_WARNING)
{
	if(!defined('IMSCP_SETUP')) {
		/** @var $cfg iMSCP_Config_Handler_File */
		$cfg = iMSCP_Registry::get('config');

		$clientIp = getIpAddr() ? getIpAddr() : 'unknown';

		$msg = replace_html($msg . '<br /><small>User IP: ' . $clientIp . '</small>', ENT_COMPAT, 'UTF-8');

		exec_query("INSERT INTO `log` (`log_time`,`log_message`) VALUES(NOW(), ?)", $msg);

		$msg = strip_tags(str_replace('<br />', "\n", $msg));
		$to = isset($cfg->DEFAULT_ADMIN_ADDRESS) ? $cfg->DEFAULT_ADMIN_ADDRESS : '';

		if($to != '' && $logLevel <= $cfg->LOG_LEVEL) {
			$hostname = isset($cfg->SERVER_HOSTNAME) ? $cfg->SERVER_HOSTNAME : 'unknown';
			$baseServerIp = isset($cfg->BASE_SERVER_IP) ? $cfg->BASE_SERVER_IP : 'unknown';
			$version = isset($cfg->Version) ? $cfg->Version : 'unknown';
			$buildDate = !empty($cfg->BuildDate) ? $cfg->BuildDate : 'unavailable';
			$subject = "i-MSCP $version on $hostname ($baseServerIp)";

			if($logLevel == E_USER_NOTICE) {
				$severity = 'Notice (You can ignore this message)';
			} elseif($logLevel == E_USER_WARNING) {
				$severity = 'Warning';
			} elseif($logLevel == E_USER_ERROR) {
				$severity = 'Error';
			} else {
				$severity = 'Unknown';
			}

			$message = <<<AUTO_LOG_MSG

i-MSCP Log

Server : $hostname ($baseServerIp)
Version: $version
Build  : $buildDate
Message severity: $severity

Message: ----------------[BEGIN]--------------------------

$msg

Message: ----------------[END]----------------------------

_________________________
i-MSCP Log Mailer

Note: If you want no longer receive messages for this log
level, you can change it via the settings page.

AUTO_LOG_MSG;

			$headers = "From: \"i-MSCP Logging Mailer\" <" . $to . ">\n";
			$headers .= "MIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n";
			$headers .= "Content-Transfer-Encoding: 7bit\n";
			$headers .= "X-Mailer: i-MSCP Mailer";

			if(!mail($to, $subject, $message, $headers)) {
				$log_message = "Logging Mailer Mail To: |$to|, From: |$to|, Status: |NOT OK|!";
				exec_query("INSERT INTO `log` (`log_time`,`log_message`) VALUES(NOW(), ?)", $log_message, false);
			}
		}
	}
}

/**
 * Send add user email
 *
 * @param int $adminId Admin unique identifier
 * @param string $uname Username
 * @param string $upass User password
 * @param string $uemail User email
 * @param string $ufname User firstname
 * @param string $ulname User lastname
 * @param string $utype User type
 * @return void
 */
function send_add_user_auto_msg($adminId, $uname, $upass, $uemail, $ufname, $ulname, $utype)
{
	/** @var $cfg iMSCP_Config_Handler_File */
	$cfg = iMSCP_Registry::get('config');

	$data = get_welcome_email($adminId, $_SESSION['user_type']);

	if ($data['sender_name']) {
		$from = encode_mime_header($data['sender_name']) . " <{$data['sender_email']}>";
	} else {
		$from = $data['sender_email'];
	}

	if ($ufname && $ulname) {
		$to = encode_mime_header($ufname . ' ' . $ulname) . " <$uemail>";
		$name = "$ufname $ulname";
	} else {
		$name = $uname;
		$to = $uemail;
	}

	$baseServerVhostPrefix = $cfg['BASE_SERVER_VHOST_PREFIX'];
	$port = ($baseServerVhostPrefix == 'http://')
		? (($cfg['BASE_SERVER_VHOST_HTTP_PORT'] == '80') ? '' : ':' . $cfg['BASE_SERVER_VHOST_HTTP_PORT'])
		: (($cfg['BASE_SERVER_VHOST_HTTPS_PORT'] == '443') ? '' : ':' . $cfg['BASE_SERVER_VHOST_HTTPS_PORT']);

	$search = array();
	$replace = array();

	$search[] = '{USERNAME}';
	$replace[] = decode_idna($uname);

	$search[] = '{USERTYPE}';
	$replace[] = $utype;

	$search[] = '{NAME}';
	$replace[] = decode_idna($name);

	$search[] = '{PASSWORD}';
	$replace[] = $upass;

	$search[] = '{BASE_SERVER_VHOST}';
	$replace[] = $cfg['BASE_SERVER_VHOST'];

	$search[] = '{BASE_SERVER_VHOST_PREFIX}';
	$replace[] = $baseServerVhostPrefix;

	$search[] = '{BASE_SERVER_VHOST_PORT}';
	$replace[] = $port;

	$data['subject'] = str_replace($search, $replace, $data['subject']);
	$message = str_replace($search, $replace, $data['message']);

	$headers = "From: $from\r\n";
	$headers .= "MIME-Version: 1.0\r\n";
	$headers .= "Content-Type: text/plain; charset=utf-8\r\n";
	$headers .= "Content-Transfer-Encoding: 8bit\r\n";
	$headers .= "X-Mailer: i-MSCP Mailer";

	$mailStatus = mail($to, encode_mime_header($data['subject']), $message, $headers, "-f {$data['sender_email']}")
		? 'OK' : 'NOT OK';

	$name = tohtml($name);
	$fromName = tohtml($data['sender_name']);

	$logEntry = (!$fromName) ? $data['sender_email'] : "$fromName - {$data['sender_email']}";

	write_log(
		"{$_SESSION['user_logged']}: Auto Add User To: |$name - $uemail |, From: |$logEntry|, Status: |$mailStatus|!",
		E_USER_NOTICE
	);
}

/***********************************************************************************************************************
 * iMSCP daemon related functions
 */

/**
 * Read an answer from i-MSCP daemon
 *
 * @param resource &$socket
 * @return bool TRUE on success, FALSE otherwise
 */
function daemon_readAnswer(&$socket)
{
	if(($answer = @socket_read($socket, 1024, PHP_NORMAL_READ)) !== false) {
		list($code) = explode(' ', $answer);
		$code = intval($code);

		if($code != 250) {
			write_log(sprintf('i-MSCP daemon returned an unexpected answer: %s', $answer), E_USER_ERROR);
			return false;
		}
	} else {
		write_log(
			sprintf('Unable to read answer from i-MSCP daemon: %s'. socket_strerror(socket_last_error())), E_USER_ERROR
		);
		return false;
	}

	return true;
}

/**
 * Send a command to i-MSCP daemon
 *
 * @param resource &$socket
 * @param string $command Command
 * @return bool TRUE on success, FALSE otherwise
 */
function daemon_sendCommand(&$socket, $command)
{
	$command .= "\n";
	$commandLength = strlen($command);

	while (true) {
		if (($bytesSent = @socket_write($socket, $command, $commandLength)) !== false) {
			if ($bytesSent < $commandLength) {
				$command = substr($command, $bytesSent);
				$commandLength -= $bytesSent;
			} else {
				return true;
			}
		} else {
			write_log(
				sprintf('Unable to send command to i-MSCP daemon: %s', socket_strerror(socket_last_error())),
				E_USER_ERROR
			);
			return false;
		}
	}

	return false;
}

/**
 * Send a request to the daemon
 *
 * @return bool TRUE on success, FALSE otherwise
 */
function send_request()
{
	if(
		($socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) !== false &&
		@socket_connect($socket, '127.0.0.1', 9876) !== false
	) {
		$cfg = iMSCP_Registry::get('config');

		if(
			daemon_readAnswer($socket) && // Read Welcome message from i-MSCP daemon
			daemon_sendCommand($socket, "helo {$cfg->Version}") && // Send helo command to i-MSCP daemon
			daemon_readAnswer($socket) && // Read answer from i-MSCP daemon
			daemon_sendCommand($socket, 'execute query') && // Send execute query command to i-MSCP daemon
			daemon_readAnswer($socket) && // Read answer from i-MSCP daemon
			daemon_sendCommand($socket, 'bye') && // Send bye command to i-MSCP daemon
			daemon_readAnswer($socket) // Read answer from i-MSCP daemon
		) {
			$ret = true;
		} else {
			$ret = false;
		}

		socket_close($socket);
	} else {
		write_log(sprintf(
			'Unable to connect to the i-MSCP daemon: %s', socket_strerror(socket_last_error())), E_USER_ERROR
		);
		$ret = false;
	}

	return $ret;
}

/***********************************************************************************************************************
 * Database related functions
 */

/**
 * Executes a SQL statement.
 *
 * Note: You may pass additional parameters. They will be treated as though you
 * called PDOStatement::setFetchMode() on the resultant statement object that is
 * wrapped by the iMSCP_Database_ResultSet object.
 *
 * @see iMSCP_Database::execute()
 * @throws iMSCP_Exception_Database
 * @param string $query                 Sql statement to be executed
 * @param array|int|string $parameters  OPTIONAL parameters - See iMSCP_Database::execute()
 * @return iMSCP_Database_ResultSet     An iMSCP_Database_ResultSet object
 */
function execute_query($query, $parameters = null)
{
	static $db = null;

	if (null === $db) {
		/** @var $db iMSCP_Database */
		$db = iMSCP_Registry::get('db');
	}

	try {
		if (null !== $parameters) {
			$parameters = func_get_args();
			array_shift($parameters);
			$stmt = call_user_func_array(array($db, 'execute'), $parameters);
		} else {
			$stmt = $db->execute($query);
		}

		if ($stmt == false) {
			throw new iMSCP_Exception_Database($db->getLastErrorMessage(), $query);
		}

	} catch (PDOException $e) {
		throw new iMSCP_Exception_Database($e->getMessage(), $query, $e->getCode(), $e);
	}

	return $stmt;
}

/**
 * Convenience method to prepare and execute a query.
 *
 * @throws iMSCP_Exception_Database      When query fail
 * @param string $query                  Sql statement
 * @param string|int|array $bind         Data to bind to the placeholders
 * @return iMSCP_Database_ResultSet|null A iMSCP_Database_ResultSet object that represents a result set
 */
function exec_query($query, $bind = null)
{
	static $db = null;

	if (null === $db) {
		$db = iMSCP_Database::getInstance();
	}

	try {
		$stmt = $db->execute($db->prepare($query), $bind);
	} catch (PDOException $e) {
		throw new iMSCP_Exception_Database($e->getMessage(), $query, $e->getCode(), $e);
	}

	return $stmt;
}

/**
 * Quote SQL identifier.
 *
 * Note: An Identifier is essentially a name of a database, table, or table column.
 *
 * @param  string $identifier Identifier to quote
 * @return string quoted identifier
 */
function quoteIdentifier($identifier)
{
	static $db = null;

	if (null === $db) {
		/** @var $db iMSCP_Database */
		$db = iMSCP_Registry::get('db');
	}

	return $db->quoteIdentifier($identifier);
}

/**
 * Quote value
 *
 * @param mixed $value Value to quote
 * @param int $parameterType Parameter type
 * @return mixed quoted value
 */
function quoteValue($value, $parameterType = PDO::PARAM_STR)
{
	static $db = null;

	if (null === $db) {
		/** @var $db iMSCP_Database */
		$db = iMSCP_Registry::get('db');
	}

	return $db->quote($value, $parameterType);
}

/***********************************************************************************************************************
 * Unclassified functions
 */

/**
 * Returns a count of items present in a database table with optional search criterias.
 *
 * @param  string $table Table name on which to operate
 * @param  string $where OPTIONAL SQL WHERE clause
 * @param  string $bind OPTIONAL value to bind to the placeholder
 * @return int Items count
 */
function records_count($table, $where = '', $bind = '')
{
	if ($where != '') {
		if ($bind != '') {
			$stmt = exec_query("SELECT COUNT(*) AS `cnt` FROM `$table` WHERE $where = ?", $bind);
		} else {
			$stmt = execute_query("SELECT COUNT(*) AS `cnt` FROM $table WHERE $where");
		}
	} else {
		$stmt = execute_query("SELECT COUNT(*) AS `cnt` FROM `$table`");
	}

	return (int)$stmt->fields['cnt'];
}

/**
 * Unset global variables
 *
 * @return void
 */
function unsetMessages()
{
	$glToUnset = array(
		'user_updated', 'dmn_tpl', 'chtpl', 'step_one', 'step_two_data', 'ch_hpprops', 'user_add3_added',
		'user_has_domain', 'local_data', 'reseller_added', 'user_added', 'aladd', 'edit_ID', 'aldel', 'hpid',
		'user_deleted', 'hdomain', 'aledit', 'acreated_by', 'dhavesub', 'ddel', 'dhavealias', 'dhavealias', 'dadel',
		'local_data',
	);

	foreach ($glToUnset as $toUnset) {
		if (array_key_exists($toUnset, $GLOBALS)) {
			unset($GLOBALS[$toUnset]);
		}
	}

	$sessToUnset = array(
		'reseller_added', 'dmn_name', 'dmn_tpl', 'chtpl', 'step_one', 'step_two_data', 'ch_hpprops', 'user_add3_added',
		'user_has_domain', 'user_added', 'aladd', 'edit_ID', 'aldel', 'hpid', 'user_deleted', 'hdomain', 'aledit',
		'acreated_by', 'dhavesub', 'ddel', 'dhavealias', 'dadel', 'local_data',
	);

	foreach ($sessToUnset as $toUnset) {
		if (array_key_exists($toUnset, $_SESSION)) {
			unset($_SESSION[$toUnset]);
		}
	}
}

if (!function_exists('http_build_url')) {
	define('HTTP_URL_REPLACE', 1); // Replace every part of the first URL when there's one of the second URL
	define('HTTP_URL_JOIN_PATH', 2); // Join relative paths
	define('HTTP_URL_JOIN_QUERY', 4); // Join query strings
	define('HTTP_URL_STRIP_USER', 8); // Strip any user authentication information
	define('HTTP_URL_STRIP_PASS', 16); // Strip any password authentication information
	define('HTTP_URL_STRIP_AUTH', 32); // Strip any authentication information
	define('HTTP_URL_STRIP_PORT', 64); // Strip explicit port numbers
	define('HTTP_URL_STRIP_PATH', 128); // Strip complete path
	define('HTTP_URL_STRIP_QUERY', 256); // Strip query string
	define('HTTP_URL_STRIP_FRAGMENT', 512); // Strip any fragments (#identifier)
	define('HTTP_URL_STRIP_ALL', 1024); // Strip anything but scheme and host

	/**
	 * Build an URL.
	 *
	 * The parts of the second URL will be merged into the first according to the flags argument.
	 *
	 * @param mixed $url (Part(s) of) an URL in form of a string or associative array like parse_url() returns
	 * @param mixed $parts Same as the first argument
	 * @param int $flags A bitmask of binary or'ed HTTP_URL constants (Optional)HTTP_URL_REPLACE is the default
	 * @param bool|array $new_url If set, it will be filled with the parts of the composed url like parse_url() would return
	 * @return string URL
	 */
	function http_build_url($url, $parts = array(), $flags = HTTP_URL_REPLACE, &$new_url = false)
	{
		$keys = array('user', 'pass', 'port', 'path', 'query', 'fragment');

		// HTTP_URL_STRIP_ALL becomes all the HTTP_URL_STRIP_Xs
		if ($flags & HTTP_URL_STRIP_ALL) {
			$flags |= HTTP_URL_STRIP_USER;
			$flags |= HTTP_URL_STRIP_PASS;
			$flags |= HTTP_URL_STRIP_PORT;
			$flags |= HTTP_URL_STRIP_PATH;
			$flags |= HTTP_URL_STRIP_QUERY;
			$flags |= HTTP_URL_STRIP_FRAGMENT;
		} // HTTP_URL_STRIP_AUTH becomes HTTP_URL_STRIP_USER and HTTP_URL_STRIP_PASS
		else if ($flags & HTTP_URL_STRIP_AUTH) {
			$flags |= HTTP_URL_STRIP_USER;
			$flags |= HTTP_URL_STRIP_PASS;
		}

		// Parse the original URL
		$parse_url = parse_url($url);

		// Scheme and Host are always replaced
		if (isset($parts['scheme'])) {
			$parse_url['scheme'] = $parts['scheme'];
		}

		if (isset($parts['host'])) {
			$parse_url['host'] = $parts['host'];
		}

		// (If applicable) Replace the original URL with it's new parts
		if ($flags & HTTP_URL_REPLACE) {
			foreach ($keys as $key) {
				if (isset($parts[$key])) {
					$parse_url[$key] = $parts[$key];
				}
			}
		} else {
			// Join the original URL path with the new path
			if (isset($parts['path']) && ($flags & HTTP_URL_JOIN_PATH)) {
				if (isset($parse_url['path'])) {
					$parse_url['path'] = rtrim(str_replace(basename($parse_url['path']), '', $parse_url['path']), '/') .
						'/' . ltrim($parts['path'], '/');
				} else {
					$parse_url['path'] = $parts['path'];
				}
			}

			// Join the original query string with the new query string
			if (isset($parts['query']) && ($flags & HTTP_URL_JOIN_QUERY)) {
				if (isset($parse_url['query'])) {
					$parse_url['query'] .= '&' . $parts['query'];
				} else {
					$parse_url['query'] = $parts['query'];
				}
			}
		}

		// Strips all the applicable sections of the URL
		// Note: Scheme and Host are never stripped
		foreach ($keys as $key) {
			if ($flags & (int)constant('HTTP_URL_STRIP_' . strtoupper($key))) {
				unset($parse_url[$key]);
			}
		}

		$new_url = $parse_url;

		return
			((isset($parse_url['scheme'])) ? $parse_url['scheme'] . '://' : '')
			. ((isset($parse_url['user']))
				? $parse_url['user'] . ((isset($parse_url['pass']))
					? ':' . $parse_url['pass'] : '') . '@' : '')
			. ((isset($parse_url['host'])) ? $parse_url['host'] : '')
			. ((isset($parse_url['port'])) ? ':' . $parse_url['port'] : '')
			. ((isset($parse_url['path'])) ? $parse_url['path'] : '')
			. ((isset($parse_url['query'])) ? '?' . $parse_url['query'] : '')
			. ((isset($parse_url['fragment'])) ? '#' . $parse_url['fragment'] : '');
	}
}

/**
 * Returns translation for jQuery DataTables plugin.
 *
 * @param bool $json Does the data must be encoded to JSON?
 * @return string|array
 */
function getDataTablesPluginTranslations($json = true)
{
	$tr = array(
		'sLengthMenu'=> tr(
			'Show %s records per page',
			'
				<select>
				<option value="10">10</option>
				<option value="15">15</option>
				<option value="20">20</option>
				<option value="50">50</option>
				<option value="-1">'. tr('All') . '</option>
				</select>
			'
		),
		//'sLengthMenu' => tr('Show %s records per page', '_MENU_'),
		'zeroRecords' => tr('Nothing found - sorry'),
		'info' => tr('Showing %s to %s of %s records', '_START_', '_END_', '_TOTAL_'),
		'infoEmpty' => tr('Showing 0 to 0 of 0 records'),
		'infoFiltered' => tr('(filtered from %s total records)', '_MAX_'),
		'search' => tr('Search'),
		'paginate' => array('previous' => tr('Previous'), 'next' => tr('Next')),
		'processing' => tr('Loading data...')
	);

	return ($json) ? json_encode($tr) : $tr;
}

/**
 * Show 400 error page
 *
 * @return void
 */
function showBadRequestErrorPage()
{
	/** @var $cfg iMSCP_Config_Handler_File */
	$cfg = iMSCP_Registry::get('config');

	$filePath = $cfg->GUI_ROOT_DIR . '/public/errordocs/400.html';

	header("Status: 400 Bad Request");

	$response = '';

	if(isset($_SERVER['HTTP_ACCEPT'])) {
		if(
			(
				strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false ||
				strpos($_SERVER['HTTP_ACCEPT'], 'application/xhtml') !== false
			) && !is_xhr()
		) {
			$response = file_get_contents($filePath);
		} elseif(strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
			header("Content-type: application/json");
			$response =  json_encode(array('code' => 400, 'message' => 'Bad Request'));
		} elseif(strpos($_SERVER['HTTP_ACCEPT'], 'application/xmls') !== false) {
			header("Content-type: text/xml;charset=utf-8");
			$response = '<?xml version="1.0" encoding="utf-8"?>';
			$response = $response.'<response><code>400</code>';
			$response = $response.'<message>Bad Request</message></response>';
		} elseif(!is_xhr()) {
			include $filePath;
		}
	} elseif(!is_xhr()) {
		$response = file_get_contents($filePath);
	}

	if($response != '') {
		echo $response;
	}

	exit;
}

/**
 * Show 404 error page
 *
 * @return void
 */
function showNotFoundErrorPage()
{
	/** @var $cfg iMSCP_Config_Handler_File */
	$cfg = iMSCP_Registry::get('config');

	$filePath = $cfg->GUI_ROOT_DIR . '/public/errordocs/404.html';

	header("Status: 404 Not Found");

	$response = '';

	if(isset($_SERVER['HTTP_ACCEPT'])) {
		if(
			(
				strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false ||
				strpos($_SERVER['HTTP_ACCEPT'], 'application/xhtml') !== false
			) && !is_xhr()
		) {
			$response = file_get_contents($filePath);
		} elseif(strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
			header("Content-type: application/json");
			$response =  json_encode(array('code' => 404, 'message' => 'Not Found'));
		} elseif(strpos($_SERVER['HTTP_ACCEPT'], 'application/xmls') !== false) {
			header("Content-type: text/xml;charset=utf-8");
			$response = '<?xml version="1.0" encoding="utf-8"?>';
			$response = $response.'<response><code>404</code>';
			$response = $response.'<message>Not Found</message></response>';
		} elseif(!is_xhr()) {
			include $filePath;
		}
	} elseif(!is_xhr()) {
		$response = file_get_contents($filePath);
	}

	if($response != '') {
		echo $response;
	}

	exit;
}

/**
 * @param  $crnt
 * @param  $max
 * @param  $bars_max
 * @return array
 */
function calc_bars($crnt, $max, $bars_max)
{
	if ($max != 0) {
		$percent_usage = (100 * $crnt) / $max;
	} else {
		$percent_usage = 0;
	}

	$bars = ($percent_usage * $bars_max) / 100;

	if ($bars > $bars_max) {
		$bars = $bars_max;
	}

	return array(
		sprintf("%.2f", $percent_usage),
		sprintf("%d", $bars)
	);
}

/**
 * Turns byte counts to human readable format.
 *
 * If you feel like a hard-drive manufacturer, you can start counting bytes by power
 * of 1000 (instead of the generous 1024). Just set power to 1000.
 *
 * But if you are a floppy disk manufacturer and want to start counting in units of
 * 1024 (for your "1.44 MB" disks ?) let the default value for power.
 *
 * The units for power 1000 are: ('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB')
 *
 * Those for power 1024 are: ('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB')
 *
 * with the horrible names: bytes, kibibytes, mebibytes, etc.
 *
 * @see http://physics.nist.gov/cuu/Units/binary.html
 * @author Laurent Declercq <l.declercq@nuxwin.com>
 * @throws iMSCP_Exception if power or unit value is unknown
 * @param int|float $bytes Bytes value to convert
 * @param string $unit OPTIONAL Unit to calculate to
 * @param int $decimals OPTIONAL Number of decimal to be show
 * @param int $power OPTIONAL Power to use for conversion (1024 or 1000)
 * @return string
 */
function bytesHuman($bytes, $unit = null, $decimals = 2, $power = 1024)
{
	if ($power == 1000) {
		$units = array(
			'B' => 0, 'kB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4, 'PB' => 5, 'EB' => 6, 'ZB' => 7, 'YB' => 8
		);
	} elseif ($power == 1024) {
		$units = array(
			'B' => 0, 'kiB' => 1, 'MiB' => 2, 'GiB' => 3, 'TiB' => 4, 'PiB' => 5, 'EiB' => 6, 'ZiB' => 7, 'YiB' => 8
		);
	} else {
		throw new iMSCP_Exception('Unknown power value');
	}

	$value = 0;

	if ($bytes > 0) {
		if (!array_key_exists($unit, $units)) {
			if(null === $unit) {
				$pow = floor(log($bytes) / log($power));
				$unit = array_search($pow, $units);
			} else {
				throw new iMSCP_Exception('Unknown unit value');
			}
		}

		$value = ($bytes / pow($power, floor($units[$unit])));
	} else {
		$unit = 'B';
	}

	// If decimals is not numeric or decimals is less than 0
	// then set default value
	if (!is_numeric($decimals) || $decimals < 0) {
		$decimals = 2;
	}

	// units Translation
	switch ($unit) {
		case 'B':
			$unit = tr('B');
			break;
		case 'kB':
			$unit = tr('kB');
			break;
		case 'kiB':
			$unit = tr('kiB');
			break;
		case 'MB':
			$unit = tr('MB');
			break;
		case 'MiB':
			$unit = tr('MiB');
			break;
		case 'GB':
			$unit = tr('GB');
			break;
		case 'GiB':
			$unit = tr('GiB');
			break;
		case 'TB':
			$unit = tr('TB');
			break;
		case 'TiB':
			$unit = tr('TiB');
			break;
		case 'PB':
			$unit = tr('PB');
			break;
		case 'PiB':
			$unit = tr('PiB');
			break;
		case 'EB':
			$unit = tr('EB');
			break;
		case 'EiB':
			$unit = tr('EiB');
			break;
		case 'ZB':
			$unit = tr('ZB');
			break;
		case 'ZiB':
			$unit = tr('ZiB');
			break;
		case 'YB':
			$unit = tr('YB');
			break;
		case 'YiB':
			$unit = tr('YiB');
			break;
	}

	return sprintf('%.' . $decimals . 'f ' . $unit, $value);
}

/**
 * Humanize a mebibyte value.
 *
 * @author Laurent Declercs <l.declercq@nuxwin.com>
 * @param int $value mebibyte value
 * @param string $unit OPTIONAL Unit to calculate to ('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB')
 * @return string
 */
function mebibyteHuman($value, $unit = null)
{
	return bytesHuman($value * 1048576, $unit);
}

/**
 * Translates '-1', 'no', 'yes', '0' or mebibyte value string into human readable string.
 *
 * @param int $value variable to be translated
 * @param bool $autosize calculate value in different unit (default false)
 * @param string $to OPTIONAL Unit to calclulate to ('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB')
 * @return String
 */
function translate_limit_value($value, $autosize = false, $to = null)
{
	$trEnabled = '<span style="color:green">' . tr('Enabled') . '</span>';
	$trDisabled = '<span style="color:red">' . tr('Disabled') . '</span>';

	switch ($value) {
		case '-1':
			return tr('Disabled');
		case  '0':
			return tr('Unlimited');
		case '_yes_':
		case 'yes':
			return $trEnabled;
		case '_no_':
		case 'no':
			return $trDisabled;
		case 'full':
			return '<span style="color:green">' . tr('Domain and SQL databases') . '</span>';
		case 'dmn':
			return '<span style="color:green">' . tr('Web files only') . '</span>';
		case 'sql':
			return '<span style="color:green">' . tr('SQL databases only') . '</span>';
		default:
			return (!$autosize) ? $value : mebibyteHuman($value, $to);
	}
}

/**
 * Return timestamp for the first day of $month of $year
 *
 * @param int $month OPTIONAL a month
 * @param int $year OPTIONAL A year (two or 4 digits, whatever)
 * @return int
 */
function getFirstDayOfMonth($month = null, $year = null)
{
	$month = $month ? : date('m');
	$year = $year ? : date('y');

	return mktime(0, 0, 0, $month, 1, $year);

}

/**
 * Return timestamp for last day of month of $year
 *
 * @param int $month OPTIONAL a month
 * @param int $year OPTIONAL A year (two or 4 digits, whatever)
 * @return int
 */
function getLastDayOfMonth($month = null, $year = null)
{
	$month = $month ? : date('m');
	$year = $year ? : date('y');

	return mktime(23, 59, 59, $month + 1, 0, $year);
}

/**
 * Get list of available webmail
 *
 * @return array
 */
function getWebmailList()
{
	$config = iMSCP_Registry::get('config');

	if( isset($config['WEBMAIL_PACKAGES']) && strtolower($config['WEBMAIL_PACKAGES']) != 'no' ) {
		return explode(',', $config['WEBMAIL_PACKAGES']);
	}

	return array();
}

/**
 * Returns the user Ip address
 *
 * @return string User's Ip address
 */
function getIpAddr()
{
	$ipAddr = (!empty($_SERVER['HTTP_CLIENT_IP'])) ? $_SERVER['HTTP_CLIENT_IP'] : false;

	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$ipAddrs = explode(', ', $_SERVER['HTTP_X_FORWARDED_FOR']);

		if ($ipAddr) {
			array_unshift($ipAddrs, $ipAddr);
			$ipAddr = false;
		}

		$countIpAddrs = count($ipAddrs);

		// Loop over ip stack as long an ip out of private range is not found
		for ($i = 0; $i < $countIpAddrs; $i++) {
			if (filter_var($ipAddrs[$i], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
				$ipAddr = $ipAddrs[$i];
				break;
			}
		}
	}

	return ($ipAddr ? $ipAddr : $_SERVER['REMOTE_ADDR']);
}
