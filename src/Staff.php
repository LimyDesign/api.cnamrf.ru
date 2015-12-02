<?php

interface StaffErrorsMsg
{
	const ERROR_CONNECT = 'Подключение не удалось: ',
		ERROR_FIRST_QUERY = 'Ошибка в 1-ом запросе: ',
		ERROR_UPDATE_USER = 'Ошибка в обновлении данных пользователя: ',
		ERROR_ADD_ACTIVATE_TARIFF = 'Ошибка добавления данных об активации тарифа',
		MSG_ACTIVATE_TARIFF = 'Активация тарифа ';

	public function __construct($username, $password, $host, $database, $type);
	public function renewal($check);
}

class Staff implements StaffErrorsMsg
{
	/**
	 * @var PDO $db
	 */
	protected $db;
	/**
	 * @var string $dsn
	 * @var string $username
	 * @var string $password
	 */
	private $dsn, $username, $password;

	/**
	 * Staff constructor.
	 * @param string $username
	 * @param string $password
	 * @param string $host
	 * @param string $database
	 * @param string $type
	 */
	public function __construct($username, $password, $host, $database, $type) 
	{
		if ($type == 'postgres') 
		{
			$type = 'pgsql';
			$this->dsn = $type.':host='.$host.';dbname='.$database;
		}

		$this->username = $username;
		$this->password = $password;
		$this->connect();
	}

	/**
	 * @return PDO
	 */
	private function connect()
	{
		try {
			$db = new \PDO($this->dsn, $this->username, $this->password);
			$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$db->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);
			$db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
		} catch (\PDOException $e) {
			die(self::ERROR_CONNECT.$e->getMessage());
		}

		$this->db  = $db;
		return $this->db;
	}

	/**
	 * @param bool|true $check
	 * @return array
	 */
	public function renewal($check = true)
	{
		$db = $this->db;
		$result = array();
		if ($check) $add = "+ INTERVAL '1 week'";
		else $add = "";
		$query = "SELECT t1.uid, t1.modtime, t2.qty, t2.qty2, t2.tariffid, t2.tariffid2, t3.name AS cnam_name, t3.sum AS cnam_sum, t3.queries AS cnam_qty, t3.autosum AS cnam_renewal, t4.name AS crm_name, t4.sum AS crm_sum, t4.queries AS crm_qty, t4.autosum AS crm_renewal, t2.telegram_chat_id, t2.telegram_renewal, t2.icq_uin, t2.icq_renewal, (SELECT sum(debet) - sum(credit) FROM log t5 WHERE t1.uid = t5.uid) AS balance FROM log t1 LEFT JOIN users t2 ON t1.uid = t2.id LEFT JOIN tariff t3 ON t2.tariffid = t3.id LEFT JOIN tariff t4 ON t2.tariffid2 = t4.id WHERE t1.client ~ '^Активация тарифа.*' AND date_trunc('day', t1.modtime) {$add} = date_trunc('day', LOCALTIMESTAMP) - INTERVAL '1 month' ORDER BY t1.modtime DESC;";
		try {
			$sth = $db->prepare($query);
			$sth->execute();
		} catch (\PDOException $e) {
			die(self::ERROR_FIRST_QUERY.$e->getMessage());
		}
		while ($row = $sth->fetch())
		{
			$uid 					= $row['uid'];
			$date 					= $row['modtime'];
			$qty 					= $row['qty'];
			$qty2 					= $row['qty2'];
			$tariffid 				= $row['tariffid'];
			$tariffid2 				= $row['tariffid2'];
			$balance 				= $row['balance'];
			$cnam['name'] 			= $row['cnam_name'];
			$cnam['sum'] 			= $row['cnam_sum'];
			$cnam['qty'] 			= $row['cnam_qty'];
			$cnam['renewal'] 		= $row['cnam_renewal'];
			$crm['name'] 			= $row['crm_name'];
			$crm['sum'] 			= $row['crm_sum'];
			$crm['qty'] 			= $row['crm_qty'];
			$crm['renewal'] 		= $row['crm_renewal'];
			$telegram['chat_id']	= $row['telegram_chat_id'];
			$telegram['renewal']	= $row['telegram_renewal'];
			$icq['uid']				= $row['icq_uin'];
			$icq['renewal']			= $row['icq_renewal'];

			if ($uid)
			{
				if ($check)
				{
					$result[$uid] = array(
							'check' => $check,
							'date' => date('d.m.Y', strtotime($date)),
							'cnam_qty' => $qty,
							'crm_qty' => $qty2,
							'balance' => $balance,
							'cnam' => $cnam,
							'crm' => $crm,
							'telegram' => $telegram,
							'icq' => $icq
					);
				}
				else
				{
					$cnam['info'] = self::MSG_ACTIVATE_TARIFF.$cnam['name'];
					$crm['info'] = self::MSG_ACTIVATE_TARIFF.$crm['name'];
					$result[$uid] = array(
							'check' => $check,
							'telegram' => $telegram,
							'icq' => $icq
					);

					if ($tariffid > 0 && $tariffid2 > 0)
					{
						if ($balance >= $cnam['sum'] + $crm['sum'])
						{
							$query_uu = "UPDATE users SET qty = :cnam_qty, qty2 = :crm_qty WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':cnam_qty', $cnam['qty'], \PDO::PARAM_INT);
								$sth->bindValue(':crm_qty', $crm['qty'], \PDO::PARAM_INT);
								$sth->bindValue(':uid', $uid);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}
							$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :cnam_sum, :cnam_info), (:uid, :crm_sum, :crm_info)";
							try {
								$sth = $db->prepare($query_il);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->bindValue(':cnam_sum', $cnam['sum'], \PDO::PARAM_INT);
								$sth->bindValue(':cnam_info', $cnam['info'], \PDO::PARAM_STR);
								$sth->bindValue(':crm_sum', $crm['sum'], \PDO::PARAM_INT);
								$sth->bindValue(':crm_info', $crm['info'], \PDO::PARAM_STR);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
							}
							$result[$uid]['renew_tariff_1'] = $cnam['name'];
							$result[$uid]['renew_sum_1'] = $cnam['sum'];
							$result[$uid]['renew_qty_1'] = $cnam['qty'];
							$result[$uid]['renew_tariff_2'] = $crm['name'];
							$result[$uid]['renew_sum_2'] = $crm['sum'];
							$result[$uid]['renew_qty_2'] = $crm['qty'];
						}
						elseif ($balance >= $crm['sum'])
						{
							$query_uu = "UPDATE users SET qty2 = :qty, tariffid = DEFAULT WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':qty', $crm['qty'], \PDO::PARAM_INT);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}
							$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :crm_sum, :crm_info)";
							try {
								$sth = $db->prepare($query_il);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->bindValue(':crm_sum', $crm['sum'], \PDO::PARAM_INT);
								$sth->bindValue(':crm_info', $crm['info'], \PDO::PARAM_STR);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
							}
							$result[$uid]['renew_tariff_2'] = $crm['name'];
							$result[$uid]['renew_sum_2'] = $crm['sum'];
							$result[$uid]['renew_qty_2'] = $crm['qty'];
						}
						elseif ($balance >= $cnam['sum'])
						{
							$query_uu = "UPDATE users SET qty = :qty, tariffid2 = DEFAULT WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':qty', $cnam['qty'], \PDO::PARAM_INT);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}
							$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :cnam_sum, :cnam_info)";
							try {
								$sth = $db->prepare($query_il);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->bindValue(':cnam_sum', $cnam['sum'], \PDO::PARAM_INT);
								$sth->bindValue(':cnam_info', $cnam['info'], \PDO::PARAM_STR);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
							}
							$result[$uid]['renew_tariff_1'] = $cnam['name'];
							$result[$uid]['renew_sum_1'] = $cnam['sum'];
							$result[$uid]['renew_qty_1'] = $cnam['qty'];
						}
						else
						{
							$query_uu = "UPDATE users SET qty = DEFAULT, tariffid = DEFAULT, tariffid2 = DEFAULT WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}
						}
					}
					elseif ($tariffid > 0 || $tariffid2 > 0)
					{
						if ($balance >= $crm['sum'] && $tariffid2 > 0)
						{
							$query_uu = "UPDATE users SET qty2 = :qty WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':qty', $crm['qty'], \PDO::PARAM_INT);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}

							$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :crm_sum, :crm_info)";
							try {
								$sth = $db->prepare($query_il);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->bindValue(':crm_sum', $crm['sum'], \PDO::PARAM_INT);
								$sth->bindValue(':crm_info', $crm['info'], \PDO::PARAM_STR);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
							}

							$result[$uid]['renew_tariff_2'] = $crm['name'];
							$result[$uid]['renew_sum_2'] = $crm['sum'];
							$result[$uid]['renew_qty_2'] = $crm['qty'];
						}
						elseif ($balance < $crm['sum'] && $tariffid2 > 0)
						{
							$query_uu = "UPDATE users SET qty2 = DEFAULT, tariffid2 = DEFAULT WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}
						}
						elseif ($balance >= $cnam['sum'] && $tariffid > 0)
						{
							$query_uu = "UPDATE users SET qty = :qty WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':qty', $cnam['qty'], \PDO::PARAM_INT);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}

							$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :cnam_sum, :cnam_info)";
							try {
								$sth = $db->prepare($query_il);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->bindValue(':cnam_sum', $cnam['sum'], \PDO::PARAM_INT);
								$sth->bindValue(':cnam_info', $cnam['info'], \PDO::PARAM_STR);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
							}

							$result[$uid]['renew_tariff_1'] = $cnam['name'];
							$result[$uid]['renew_sum_1'] = $cnam['sum'];
							$result[$uid]['renew_qty_1'] = $cnam['qty'];
						}
						elseif ($balance < $cnam['sum'] && $tariffid > 0)
						{
							$query_uu = "UPDATE users SET qty = DEFAULT, tariffid = DEFAULT WHERE id = :uid";
							try {
								$sth = $db->prepare($query_uu);
								$sth->bindValue(':qty', $cnam['qty'], \PDO::PARAM_INT);
								$sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
								$sth->execute();
							} catch (\PDOException $e) {
								die(self::ERROR_UPDATE_USER.$e->getMessage());
							}
						}
					}
				}
			}
		}
		return $result;
	}
}
