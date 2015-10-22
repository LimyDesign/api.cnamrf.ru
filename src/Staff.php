<?php

namespace Staff;

interface StaffErrorsMsg
{
	const ERROR_CONNECT = 'Подключение не удалось: ',
		ERROR_FIRST_QUERY = 'Ошибка в 1-ом запросе: ',
		ERROR_UPDATE_USER = 'Ошибка в обновлении данных пользователя: ',
		ERROR_ADD_ACTIVATE_TARIFF = 'Ошибка добавления данных об активации тарифа',
		MSG_ACTIVATE_TARIFF = 'Активация тарифа ';

	public function connect();
	public function renewal($test = true);
}

class Staff implements StaffErrorsMsg
{
	static protected $db;
	private $dsn, $username, $password;

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

	private function connect()
	{
		try 
		{
			$db = new \PDO($this->dsn, $this->username, $this->password);
			$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
			$db->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);
		}
		catch (\PDOException $e)
		{
			die(self::ERROR_CONNECT.$e->getMessage());
		}

		$this->db  = $db;
		return $this->db;
	}

	public function renewal($test = true)
	{
		$db = $this->db;
		if ($test) $add = "+ INTERVAL '1 week'";
		else $add = "";
		$query = "SELECT t1.uid, t1.modtime, t2.qty, t2.qty2, t2.tariffid, t2.tariffid2, t3.name AS cnam_name, t3.sum AS cnam_sum, t3.queries AS cnam_qty, t3.autosum AS cnam_renewal, t4.name AS crm_name, t4.sum AS crm_sum, t4.queries AS crm_qty, t4.autosum AS crm_renewal, t2.telegram_chat_id, t2.telegram_renewal, t2.icq_uin, t2.icq_renewal, (SELECT sum(debet) - sum(credit) FROM log t5 WHERE t1.uid = t5.uid) AS balans FROM log t1 LEFT JOIN users t2 ON t1.uid = t2.id LEFT JOIN tariff t3 ON t2.tariffid = t3.id LEFT JOIN tariff t4 ON t2.tariffid2 = t4.id WHERE t1.client ~ '^Активация тарифа.*' AND t1.uid != 33 AND date_trunc('day', t1.modtime) {$add} = date_trunc('day', LOCALTIMESTAMP) - INTERVAL '1 month' ORDER BY t1.modtime DESC;";
		try
		{
			$sth = $db->prepare($query);
			$sth->execute();
			$sth->bindColumn('uid', $uid, \PDO::PARAM_INT);
			$sth->bindColumn('modtime', $date, \PDO::PARAM_STR, 255);
			$sth->bindColumn('qty', $qty, \PDO::PARAM_INT);
			$sth->bindColumn('qty2', $qty2, \PDO::PARAM_INT);
			$sth->bindColumn('tariffid', $tariffid, \PDO::PARAM_INT);
			$sth->bindColumn('tariffid2', $tariffid2, \PDO::PARAM_INT);
			$sth->bindColumn('balans', $balans, \PDO::PARAM_BOOL);
			$sth->bindColumn('cnam_name', $cnam['name'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('cnam_sum', $cnam['sum'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('cnam_qty', $cnam['qty'], \PDO::PARAM_INT);
			$sth->bindColumn('cnam_renewal', $cnam['renewal'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('crm_name', $crm['name'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('crm_sum', $crm['sum'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('crm_qty', $crm['qty'], \PDO::PARAM_INT);
			$sth->bindColumn('crm_renewal', $crm['renewal'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('telegram_chat_id', $telegram['chat_id'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('telegram_renewal', $telegram['renewal'], \PDO::PARAM_BOOL);
			$sth->bindColumn('icq_uin', $icq['uid'], \PDO::PARAM_STR, 255);
			$sth->bindColumn('icq_renewal', $icq['renewal'], \PDO::PARAM_BOOL);
			while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) 
			{
				if ($uid) 
				{
					if ($test)
					{
						$result[$uid] = array(
							'test' => true,
							'date' => date('d.m.Y', strtotime($date)),
							'cnam_qty' => $qty,
							'crm_qty' => $qty2,
							'balans' => $balans,
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
							'test' => false,
							'telegram' => $telegram,
							'icq' => $icq
						);

						if ($tariffid > 0 && $tariffid2 > 0) 
						{
							if ($balans >= $cnam['sum'] + $crm['sum']) 
							{
								$query_uu = "UPDATE users SET qty = :cnam_qty, qty2 = :crm_qty WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':cnam_qty', $cnam['qty'], \PDO::PARAM_INT);
									$sth->bindParam(':crm_qty', $crm['qty'], \PDO::PARAM_INT);
									$sth->bindParam(':uid', $uid);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}

								$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :cnam_sum, :cnam_info), (:uid, :crm_sum, :crm_info)";
								try
								{
									$sth = $db->prepare($query_il);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->bindParam(':cnam_sum', $cnam['sum'], \PDO::PARAM_INT);
									$sth->bindParam(':cnam_info', $cnam['info'], \PDO::PARAM_STR);
									$sth->bindParam(':crm_sum', $crm['sum'], \PDO::PARAM_INT);
									$sth->bindParam(':crm_info', $crm['info'], \PDO::PARAM_STR);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
								}

								$result[$uid]['renew_tariff_1'] = $cnam['name'];
								$result[$uid]['renew_sum_1'] = $cnam['sum'];
								$result[$uid]['renew_qty_1'] = $cnam['qty'];
								$result[$uid]['renew_tariff_2'] = $crm['name'];
								$result[$uid]['renew_sum_2'] = $crm['sum'];
								$result[$uid]['renew_qty_2'] = $crm['qty'];
							}
							elseif ($balans >= $crm['sum'])
							{
								$query_uu = "UPDATE users SET qty2 = :qty, tariffid = DEFAULT WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':qty', $crm['qty'], \PDO::PARAM_INT);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}

								$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :crm_sum, :crm_info)";
								try
								{
									$sth = $db->prepare($query_il);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->bindParam(':crm_sum', $crm['sum'], \PDO::PARAM_INT);
									$sth->bindParam(':crm_info', $crm['info'], \PDO::PARAM_STR);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
								}

								$result[$uid]['renew_tariff_2'] = $crm['name'];
								$result[$uid]['renew_sum_2'] = $crm['sum'];
								$result[$uid]['renew_qty_2'] = $crm['qty'];
							}
							elseif ($balans >= $cnam['sum'])
							{
								$query_uu = "UPDATE users SET qty = :qty, tariffid2 = DEFAULT WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':qty', $cnam['qty'], \PDO::PARAM_INT);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}

								$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :cnam_sum, :cnam_info)";
								try
								{
									$sth = $db->prepare($query_il);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->bindParam(':cnam_sum', $cnam['sum'], \PDO::PARAM_INT);
									$sth->bindParam(':cnam_info', $cnam['info'], \PDO::PARAM_STR);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
								}

								$result[$uid]['renew_tariff_1'] = $cnam['name'];
								$result[$uid]['renew_sum_1'] = $cnam['sum'];
								$result[$uid]['renew_qty_1'] = $cnam['qty'];
							}
							else
							{
								$query_uu = "UPDATE users SET qty = DEFAULT, tariffid = DEFAULT, tariffid2 = DEFAULT WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}
							}
						}
						elseif ($tariffid > 0 || $tariffid2 > 0)
						{
							if ($balans >= $crm['sum'] && $tariffid2 > 0)
							{
								$query_uu = "UPDATE users SET qty2 = :qty WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':qty', $crm['qty'], \PDO::PARAM_INT);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}

								$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :crm_sum, :crm_info)";
								try
								{
									$sth = $db->prepare($query_il);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->bindParam(':crm_sum', $crm['sum'], \PDO::PARAM_INT);
									$sth->bindParam(':crm_info', $crm['info'], \PDO::PARAM_STR);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
								}

								$result[$uid]['renew_tariff_2'] = $crm['name'];
								$result[$uid]['renew_sum_2'] = $crm['sum'];
								$result[$uid]['renew_qty_2'] = $crm['qty'];
							}
							elseif ($balans < $crm['sum'] && $tariffid2 > 0)
							{
								$query_uu = "UPDATE users SET qty2 = DEFAULT, tariffid2 = DEFAULT WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}
							}
							elseif ($balans >= $cnam['sum'] && $tariffid > 0)
							{
								$query_uu = "UPDATE users SET qty = :qty WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':qty', $cnam['qty'], \PDO::PARAM_INT);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}

								$query_il = "INSERT INTO log (uid, credit, client) VALUES (:uid, :cnam_sum, :cnam_info)";
								try
								{
									$sth = $db->prepare($query_il);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->bindParam(':cnam_sum', $cnam['sum'], \PDO::PARAM_INT);
									$sth->bindParam(':cnam_info', $cnam['info'], \PDO::PARAM_STR);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_ADD_ACTIVATE_TARIFF.$e->getMessage());
								}

								$result[$uid]['renew_tariff_1'] = $cnam['name'];
								$result[$uid]['renew_sum_1'] = $cnam['sum'];
								$result[$uid]['renew_qty_1'] = $cnam['qty'];
							}
							elseif ($balans < $cnam['sum'] && $tariffid > 0)
							{
								$query_uu = "UPDATE users SET qty = DEFAULT, tariffid = DEFAULT WHERE id = :uid";
								try
								{
									$sth = $db->prepare($query_uu);
									$sth->bindParam(':qty', $cnam['qty'], \PDO::PARAM_INT);
									$sth->bindParam(':uid', $uid, \PDO::PARAM_INT);
									$sth->execute();
								}
								catch (\PDOException $e)
								{
									die(self::ERROR_UPDATE_USER.$e->getMessage());
								}
							}
						}
					}
				}
			}
		}
		catch (\PDOException $e)
		{
			die(self::ERROR_FIRST_QUERY.$e->getMessage());
		}

		return $result;
	}
}
?>