<?php
######################################################################
# 
# REST API интерфейс системы CNAM РФ
# Версия 1.0
# 
# Разработка: Арсен Беспалов (@arsenbespalov)
# Оптимизация: Александр Семенов (@semen337)
# 
# Сайт: http://cnamrf.ru
# 
######################################################################

$conf = json_decode(file_get_contents(__DIR__.'/config.json'), true);

$requestURI = explode('/',$_SERVER['REQUEST_URI']);
$scriptName = explode('/',$_SERVER['SCRIPT_NAME']);
for ($i=0;$i<sizeof($scriptName);$i++)
{
	if ($requestURI[$i] == $scriptName[$i])
		unset($requestURI[$i]);
}
$cmd = array_values($requestURI);

header("Content-Type: application/json");

switch ($cmd[0]) {
	case 'getName':
		if ($_REQUEST['number']) $cmd[1] = $_REQUEST['number'];
		echo getName($cmd[1]);
		break;
	
	case 'get2GisCities':
		echo get2GisCities();
		break;

	case 'get2GisRubrics':
		echo get2GisRubrics($_REQUEST['city']);
		break;

	case 'getCompanyList':
		if (is_numeric($cmd[1])) {
			$pageNum = $cmd[1] ? $cmd[1] : 1;
		}
		echo getCompanyList(
			$_REQUEST['apikey'], 
			$_REQUEST['text'], 
			$_REQUEST['city'],
			$_REQUEST['domain'],
			$pageNum);
		break;

	case 'getCompanyProfile':
		echo getCompanyProfile(
			$_REQUEST['apikey'],
			$_REQUEST['domain'],
			$_REQUEST['id'],
			$_REQUEST['hash']);
		break;

	default:
		echo defaultResult();
}

/**
 * Функция возвращает данные по номеру телефона $number.
 * В случае удачи возращает JSON-строку в которой содержиться имя по-русски (name),
 * и имя в транслите (tarslit), а также нулевой код ошибки (error).
 *
 * В случае неудачи возвращается JSON-строка с кодом ошибки (error) и 
 * текстом ошибки (message).
 * 
 * @param  integer $number Номер телефона в формате E.164
 * @return string          Данные в формате JSON
 */
function getName($number) 
{
	global $conf;

	$number = preg_replace('/[+()-\s]/', '', $number);
	if (substr($number, 0, 1) == '8' && strlen($number) == 11) {
		$number = preg_replace('/^8/', '7', $number);
	} elseif (strlen($number) == 10) {
		$number = '7' . $number;
	}

	$uAPIKey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
	$uClient = $_REQUEST['client'];
	$uCIP = sprintf("%u", ip2long($_SERVER['REMOTE_ADDR']));

	if ($uAPIKey && $uClient && $uCIP && is_numeric($number))
	{
		if ($conf['db']['type'] == 'postgres')
		{
			$db_err_message = array('error' => '100', 'message' => 'Unable to connect to database. Please send message to support@cnamrf.ru about this error.');
			$db = pg_connect('dbname='.$conf['db']['database']) or die(json_encode($db_err_message));
			$query = "select users.id, users.qty, tariff.price from users left join tariff on users.tariffid = tariff.id where apikey = '{$uAPIKey}'";
			$result = pg_query($query);
			$uid = pg_fetch_result($result, 0, 'id');
			$qty = pg_fetch_result($result, 0, 'qty');
			$price = pg_fetch_result($result, 0, 'price');
			pg_free_result($result);
			if ($uid) {
				if ($qty) {
					$json_return = getData($number, $uid, $uClient, $uCIP, $conf);
				} else {
					$query = "select (sum(debet) - sum(credit)) as balans from log where uid = {$uid}";
					$result = pg_query($query);
					$balans = pg_fetch_result($result, 0, 'balans');
					if ($balans >= $price) {
						$json_return = getData($number, $uid, $uClient, $uCIP, $conf, $price);
					} else {
						$json_return = array('error' => '5', 'message' => 'Not enough funds. Go to http://cnamrf.ru, and refill your account in any convenient way.');
					}
				}
			} else {
				$json_return = array('error' => '3', 'message' => 'Not found any users for your API access key.');
			}
			pg_close($db);
		}
		return json_encode($json_return);
	} else {
		return json_encode(array('error' => '2', 'message' => 'Not found API access key or not specified client or not specified phone number.'));
	}
}

/**
 * Функция получает список городов в которых присутствует
 * компания 2ГИС и возвращает список в формате JSON.
 * 
 * @return string Список городов в формате JSON
 */
function get2GisCities()
{
	global $conf;
	$uAPIKey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
	if ($uAPIKey) {
		if ($conf['db']['type'] == 'postgres')
		{
			$db_err_message = array('error' => 100, 'message' => 'Unable to connect to database. Please send message to support@cnamrf.ru about this error.');
			$db = pg_connect('dbname='.$conf['db']['database']) or die(json_encode($db_err_message));
			$query = "select is_admin from users where apikey = '{$uAPIKey}'";
			$result = pg_query($query);
			$is_admin = pg_fetch_result($result, 0, 'is_admin');
			if ($is_admin == 't') {
				$url = 'http://catalog.api.2gis.ru/2.0/region/list?';
				$uri = http_build_query(array(
					'key' => $conf['2gis']['key'],
					'locale' => 'ru_RU',
					'locale_filter' => 'ru_RU',
					'country_code_filter' => 'ru',
					'format' => 'json',
					'page_size' => '150'
					));
				$dublgis = json_decode(file_get_contents($url.$uri));
				$total = $dublgis->result->total;
				if ($total) {
					$query = '';
					foreach ($dublgis->result->items as $city) {
						$query .= "'{$city->name}',";
					}
					$query = substr($query, 0, -1);
					$query = "select insertCities(array[{$query}])";
					$result = pg_query($query);
					$totalInsert = pg_fetch_result($result, 0, 0);
					pg_free_result($result);
					return json_encode(array('error' => '0', 'total' => "{$total}", 'total_insert' => $totalInsert));
				}
			} else {
				return json_encode(array('error' => '6', 'message' => 'Access deny.'));
			}
			pg_close($db);
		}
		return json_encode($json_return);
	} else {
		return json_encode(array('error' => '2', 'message' => 'Not found API access key or not specified client or not specified phone number.'));
	}
}

function get2GisRubrics($city_id)
{
	global $conf;
	$uAPIKey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
	if ($uAPIKey) {
		if ($conf['db']['type'] == 'postgres')
		{
			$db_err_message = array('error' => 100, 'message' => 'Unable to connect to database. Please send message to support@cnamrf.ru about this error.');
			$db = pg_connect('dbname='.$conf['db']['database']) or die(json_encode($db_err_message));
			$query = "select is_admin from users where apikey = '{$uAPIKey}'";
			$result = pg_query($query);
			$is_admin = pg_fetch_result($result, 0, 'is_admin');
			if ($is_admin == 't' && is_numeric($city_id)) {
				ob_implicit_flush(true);
				$query = "select name from cities where id = {$city_id}";
				$result = pg_query($query);
				$city_name = pg_fetch_result($result, 0, 'name');
				$url = 'http://catalog.api.2gis.ru/rubricator?';
				$uri = http_build_query(array(
					'key' => $conf['2gis']['key'],
					'version' => '1.3',
					'where' => $city_name,
					'show_children' => '1'));
				var_dump($url.$uri); die();
				// $dublgis = json_decode(file_get_contents($url.$uri));
				// foreach ($dublgis->result as $key => $value) {
				// 	$id_parent = $value->id;
				// 	$name_parent = pg_escape_string($value->name);
				// 	$alias_parent = pg_escape_string($value->alias);
				// 	if ($value->children) {
				// 		foreach ($value->children as $children) {
				// 			$id = $children->id;
				// 			$name = pg_escape_string($children->name);
				// 			$alias = pg_escape_string($children->alias);
				// 			$query = "update rubrics set name = '{$name}', alias = '{$alias}', parent_id = {$id_parent}, city_id = {$city_id} where id = {$id}; insert into rubrics (id, name, alias, parent_id, city_id) select {$id}, '{$name}', '{$alias}', {$id_parent}, {$city_id} where not exists (select 1 from rubrics where id = {$id});";
				// 			pg_query($query);
				// 		}
				// 	}
				// 	$query = "udate rubrics set name = '{$name_parent}', alias = '{$alias_parent}', city_id = {$city_id} where id = {$id_parent}; insert into rubrics (id, name, alias, city_id) select {$id_parent}, '{$name_parent}', '{$alias_parent}', {$city_id} where not exists (select 1 from rubrics where id = {$id_parent});";
				// 	pg_query($query);
				// }
			} else {
				return json_encode(array('error' => '6', 'message' => 'Access deny.'));
			}
			pg_close($db);
		}
		return json_encode($json_return);
	} else {
		return json_encode(array('error' => '2', 'message' => 'Not found API access key or not specified client or not specified phone number.'));
	}
}

/**
 * Функция получает список компаний по поисковой строке $text
 * в указанном городе $city для пользователя $apikey с
 * запросом от указанного доменного имени $domain.
 * Функция возкращает данные в формате JSON.
 *
 * В случае успеха возвращает JSON-строку с нулевым кодом ошибки (error).
 *
 * В случае неудачи возвращает JSON-строку с кодом ошибки (error)
 * и сообщением самой ошибки (message).
 * 
 * @param  string  $apikey  Ключ доступа пользователя
 * @param  string  $text    Поисковая строка
 * @param  integer $city    Код города из списка поддерживаемых городов
 * @param  string  $domain  Доменное имя с которого производиться запрос
 * @param  integer $pageNum Номер страницы запрашиваемой в справочнике 2ГИС
 * @return string           Данные в формате JSON
 */
function getCompanyList($apikey, $text, $city, $domain, $pageNum = 1) 
{
	global $conf;

	$apikey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
	$uClient = 'Lead4CRM';
	$uCIP = sprintf("%u", ip2long(gethostbyname($domain)));
	
	if ($apikey && $text && is_numeric($city)) {
		if ($conf['db']['type'] == 'postgres')
		{
			$db_err_message = array('error' => 100, 'message' => 'Unable to connect to database. Please send message to support@lead4crm.ru about this error.');
			$db = pg_connect('dbname='.$conf['db']['database']) or 
				die(json_encode($db_err_message));
			$query = "select name from cities where id = {$city}";
			$result = pg_query($query);
			$cityName = pg_fetch_result($result, 0, 'name');
			$query = "select users.id, users.qty, tariff.price from users left join tariff on users.tariffid2 = tariff.id where apikey = '{$apikey}'";
			$result = pg_query($query);
			$uid = pg_fetch_result($result, 0, 'id');
			$qty = pg_fetch_result($result, 0, 'qty');
			$price = pg_fetch_result($result, 0, 'price');
			pg_free_result($result);
			if ($uid) {
				if ($qty) {
					$query = "update users set qty = qty - 1 where id = {$uid}";
					pg_query($query);
					$text = pg_escape_string($text);
					$query = "insert into log (uid, client, ip, text) values ({$uid}, '{$uClient}', $uCIP, '$text')";
					pg_query($query);
					$url = 'http://catalog.api.2gis.ru/search?';
					$uri = http_build_query(array(
						'key' => $conf['2gis']['key'],
						'version' => '1.3',
						'what' => $text,
						'where' => $cityName,
						'page' => $pageNum,
						'pagesize' => 50));
					$dublgis = json_decode(file_get_contents($url.$uri));
					$result = array();
					foreach ($dublgis->result as $key => $value) {
						$result[$key]['id'] = $value->id;
						$result[$key]['name'] = $value->name;
						$result[$key]['hash'] = $value->hash;
						$result[$key]['firm_group'] = $value->firm_group->count;
						$result[$key]['address'] = $value->address;
					}
					$json_return = array(
						'error' => '0', 
						'total' => $dublgis->total,
						'pagesize' => '50',
						'page' => $pageNum,
						'qty' => $qty - 1,
						'result' => $result);
				} else {
					$query = "select (sum(debet) - sum(credit)) as balans from log where uid = {$uid}";
					$result = pg_query($query);
					$balans = pg_fetch_result($result, 0, 'balans');
					if ($balans >= $price) {
						$query = "insert into log (uid, credit, client, ip, text) values ({$uid}, '{$price}', '{$uClient}', $uCIP, '$text')";
						pg_query($query);
						$url = 'http://catalog.api.2gis.ru/search?';
						$uri = http_build_query(array(
							'key' => $conf['2gis']['key'],
							'version' => '1.3',
							'what' => $text,
							'where' => $cityName,
							'page' => $pageNum,
							'pagesize' => 50));
						$dublgis = json_decode(file_get_contents($url.$uri));
						$result = array();
						foreach ($dublgis->result as $key => $value) {
							$result[$key]['id'] = $value->id;
							$result[$key]['name'] = $value->name;
							$result[$key]['hash'] = $value->hash;
							$result[$key]['firm_group'] = $value->firm_group->count;
							$result[$key]['address'] = $value->address;
						}
						$json_return = array(
							'error' => '0', 
							'total' => $dublgis->total,
							'pagesize' => '50',
							'page' => $pageNum,
							'result' => $result);
					} else {
						$json_return = array('error' => '5', 'message' => 'Not enough funds. Go to http://www.lead4crm.ru, and refill your account in any convenient way.');
					}
				}
			} else {
				$json_return = array('error' => '3', 'message' => 'Not found any users for your API access key.');
			}
			pg_close($db);
		}
		return json_encode($json_return);
	} else {
		return json_encode(array('error' => '2', 'message' => 'Not found API access key or not search text or not city number.'));
	}
}

/**
 * Функция возвращает JSON-строку содержащую полную,
 * развернутую информацию о компании для инморта 
 * в справочник CRM системы
 * 
 * @param  string $api    Ключ доступа выдаваемый пользователю при регистрации
 * @param  string $domain Домен с которого происходит обращение
 * @param  string $id     Уникальный идентификатор филиала 2ГИС
 * @param  string $hash   Уникальный хэш филиала выдаваемый 2ГИС
 * @return string         Массив данных в JSON-формате
 */
function getCompanyProfile($api, $domain, $id, $hash) 
{
	global $conf;

	$apikey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
	$uClient = 'Lead4CRM';
	$uCIP = sprintf("%u", ip2long(gethostbyname($domain)));
	
	if ($apikey && $hash && is_numeric($id)) {
		if ($conf['db']['type'] == 'postgres')
		{
			$db_err_message = array('error' => 100, 'message' => 'Unable to connect to database. Please send message to support@lead4crm.ru about this error.');
			$db = pg_connect('dbname='.$conf['db']['database']) or 
				die(json_encode($db_err_message));
			$query = "select users.id, users.qty, tariff.price from users left join tariff on users.tariffid2 = tariff.id where apikey = '{$apikey}'";
			$result = pg_query($query);
			$uid = pg_fetch_result($result, 0, 'id');
			$qty = pg_fetch_result($result, 0, 'qty');
			$price = pg_fetch_result($result, 0, 'price');
			pg_free_result($result);
			if ($uid) {
				if ($qty) {
					$query = "update users set qty = qty - 1 where id = {$uid}";
					pg_query($query);
					$url = 'http://catalog.api.2gis.ru/profile?';
					$uri = http_build_query(array(
						'key' => $conf['2gis']['key'],
						'version' => '1.3',
						'id' => $id,
						'hash' => $hash));
					$dublgis = json_decode(file_get_contents($url.$uri));
					$companyName = pg_escape_string($dublgis->name);
					$query = "insert into log (uid, client, ip, text) values ({$uid}, '{$uClient}', $uCIP, '{$companyName}')";
					pg_query($query);
					$json_return = array(
						'error' => '0',
						'id' => $dublgis->id,
						'log' => $dublgis->lon,
						'lat' => $dublgis->lat,
						'name' => $dublgis->name,
						'city_name' => $dublgis->city_name,
						'address' => $dublgis->address,
						'currency' => $dublgis->additional_info->currency,
						'address_2' => $dublgis->additional_info->office);
					foreach ($dublgis->contacts[0]->contacts as $contact) {
						if ($contact->type == 'phone') {
							$json_return['phone'][] = array(
								"VALUE" => $contact->value, 
								"VALUE_TYPE" => "WORK");
						} elseif ($contact->type == 'fax') {
							$json_return['phone'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "FAX");
						} elseif ($contact->type == 'website') {
							$json_return['web'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "WORK");
						} elseif ($contact->type == 'facebook') {
							$json_return['web'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "FACEBOOK");
						} elseif ($contact->type == 'twitter') {
							$json_return['web'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "TWITTER");
						} elseif ($contact->type == 'vkontakte') {
							$json_return['web'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "OTHER");
						} elseif ($contact->type == 'vkontakte') {
							$json_return['web'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "OTHER");
						} elseif ($contact->type == 'skype') {
							$json_return['im'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "SKYPE");
						} elseif ($contact->type == 'icq') {
							$json_return['im'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "ICQ");
						} elseif ($contact->type == 'jabber') {
							$json_return['im'][] = array(
								"VALUE" => $contact->value,
								"VALUE_TYPE" => "JABBER");
						}
					}
					if ($dublgis->rubrics) {
					}
				} else {
					$query = "select (sum(debet) - sum(credit)) as balans from log where uid = {$uid}";
					$result = pg_query($query);
					$balans = pg_fetch_result($result, 0, 'balans');
					if ($balans >= $price) {
						$query = "insert into log (uid, credit, client, ip, text) values ({$uid}, '{$price}', '{$uClient}', $uCIP, '$text')";
						pg_query($query);
						$url = 'http://catalog.api.2gis.ru/search?';
						$uri = http_build_query(array(
							'key' => $conf['2gis']['key'],
							'version' => '1.3',
							'what' => $text,
							'where' => $cityName,
							'page' => $pageNum,
							'pagesize' => 50));
						$dublgis = json_decode(file_get_contents($url.$uri));
						$result = array();
						foreach ($dublgis->result as $key => $value) {
							$result[$key]['id'] = $value->id;
							$result[$key]['name'] = $value->name;
							$result[$key]['hash'] = $value->hash;
							$result[$key]['firm_group'] = $value->firm_group->count;
							$result[$key]['address'] = $value->address;
						}
						$json_return = array(
							'error' => '0', 
							'total' => $dublgis->total,
							'pagesize' => '50',
							'page' => $pageNum,
							'result' => $result);
					} else {
						$json_return = array('error' => '5', 'message' => 'Not enough funds. Go to http://www.lead4crm.ru, and refill your account in any convenient way.');
					}
				}
			} else {
				$json_return = array('error' => '3', 'message' => 'Not found any users for your API access key.');
			}
			pg_close($db);
		}
		return json_encode($json_return);
	} else {
		return json_encode(array('error' => '2', 'message' => 'Not found API access key or not search text or not city number.'));
	}
}

/**
 * Функция возвращает сообщение заглушку, в случае
 * пустого запроса к данному API.
 * 
 * @return string Данные в формате JSON
 */
function defaultResult() 
{
	return json_encode(array('error' => '1', 'message' => 'Failed requests to the API interface.'));
}

/**
 * Функция получения данные либо из локальной базы данных,
 * либо из справочника 2ГИС. Это вспомогательная функция,
 * для получения и передачи данных пользователю используеются
 * другие функции, например getName($number).
 * 
 * @param  integer  $number  Номер телефона в стандарте E.164
 * @param  integer  $uid     Уникальный идентификатор пользователя
 * @param  string   $uClient Название клиента, получающий данные
 * @param  integer  $uCIP    IPv4 в формате допустимого адреса
 * @param  array    $conf    Данные конфигурационного файла
 * @param  integer  $price   Стоимость каждого запроса пользователя
 * @return array             Массив данных
 */
function getData($number, $uid, $uClient, $uCIP, $conf, $price = 0)
{
	$query = "select name, translit from phonebook where phone = {$number} and verify = true";
	$result = pg_query($query);
	$name = pg_fetch_result($result, 0, 'name');
	$translit = pg_fetch_result($result, 0, 'translit');
	if ($name && $translit) {
		if ($price) {
			$query = "insert into log (uid, phone, credit, client, ip) values ({$uid}, {$number}, {$price}, '{$uClient}', {$uCIP})";
			pg_query($query);
		} else {
			$query = "update users set qty = qty - 1 where id = {$uid}";
			pg_query($query);
			$query = "insert into log (uid, phone, client, ip) values ({$uid}, {$number}, '{$uClient}', {$uCIP})";
			pg_query($query);
		}
		return array('error' => '0', 'name' => $name, 'translit' => $translit);
	} else {
		$query = "select name, translit from phone_cache where number = {$number} and queries >= 3 and modtime + interval '1 week' > now()";
		$result = pg_query($query);
		$name = pg_fetch_result($result, 0, 'name');
		$translit = pg_fetch_result($result, 0, 'translit');
		if ($name && $translit) {
			if ($price) {
				$query = "insert into log (uid, phone, credit, client, ip) values ({$uid}, {$number}, {$price}, '{$uClient}', {$uCIP})";
				pg_query($query);
			} else {
				$query = "update users set qty = qty - 1 where id = {$uid}";
				pg_query($query);
				$query = "insert into log (uid, phone, client, ip) values ({$uid}, {$number}, '{$uClient}', {$uCIP})";
				pg_query($query);
			}
			return array('error' => '0', 'name' => $name, 'translit' => $translit);
		} else {
			$phones_masks = json_decode(file_get_contents(__DIR__.'/../www/js/phones-ru.json'), true);
			array_multisort($phones_masks, SORT_DESC);
			$city = '';
			foreach ($phones_masks as $masks) {
				$mask = preg_replace('/[^0-9]/', '', $masks['mask']);
				if ($mask == substr($number, 0, strlen($mask))) {
					if ($masks['city']) {
						if (count($masks['city']) == 1) {
							$city = $masks['city'];
							break;
						} else {
							$city = $masks['city'][0];
							break;
						}
					} else {
						$city = $masks['region'];
					}
				}
			}
			$query = "select id from cities where name = '{$city}'";
			$result = pg_query($query);
			if (pg_fetch_result($result, 0, 0)) {
				$query = "select number from phones_notexists where number = {$number} and addtime + interval '1 month' > now()";
				$result = pg_query($query);
				if (!pg_fetch_result($result, 0, 0)) {
					$url = 'http://catalog.api.2gis.ru/search?';
					$uri = http_build_query(array(
						'key' => $conf['2gis']['key'],
						'version' => '1.3',
						'what' => $number,
						'where' => $city));
					$dublgis = json_decode(file_get_contents($url.$uri));
					$name = $dublgis->result[0]->name;
					$translit = rus2translit($name);
					if ($name) {
						if ($price) {
							$query = "insert into log (uid, phone, credit, client, ip) values ({$uid}, {$number}, {$price}, '{$uClient}', {$uCIP})";
							pg_query($query);
						} else {
							$query = "update users set qty = qty - 1 where id = {$uid}";
							pg_query($query);
							$query = "insert into log (uid, phone, client, ip) values ({$uid}, {$number}, '{$uClient}', {$uCIP})";
							pg_query($query);
						}
						$query = "update phone_cache set modtime = now(), queries = queries + 1 where number = {$number};";
						$query.= "insert into phone_cache (number, name, translit) select {$number}, '{$name}', '{$translit}' where not exists (select 1 from phone_cache where number = {$number});";
						pg_query($query);
						return array('error' => '0', 'name' => $name, 'translit' => $translit);
					} else {
						$query = "update phones_notexists set addtime = now() where number = {$number};";
						$query.= "insert into phones_notexists (number) select {$number} where not exists (select 1 from phones_notexists where number = {$number});";
						pg_query($query);
						return array('error' => '4', 'message' => 'The phone number in the directory is not found and added to disabled list for a month.');
					}
				} else {
					return array('error' => '8', 'message' => 'This is phone number is currently in disabled list because this phone number in the directory is not found. If you can prove ownership of the phone number, add it to your personal phone book.');
				}
			} else {
				return array('error' => '7', 'message' => 'This city is not currently supported. If you can prove ownership of the number, add it to your personal phone book. But if you believe that in your town there 2GIS company, then email the customer support: support@cnamrf.ru.', 'city' => $city);
			}
		}
	}
}

/**
 * Функция конфертирует кирилицу в транслит.
 * 
 * @param  string $string Входная строка, которая будет преобразована
 * @return string         Результат транслитерации строки
 */
function rus2translit($string) 
{
    $converter = array(
        'а' => 'a',   'б' => 'b',   'в' => 'v',
        'г' => 'g',   'д' => 'd',   'е' => 'e',
        'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
        'и' => 'i',   'й' => 'y',   'к' => 'k',
        'л' => 'l',   'м' => 'm',   'н' => 'n',
        'о' => 'o',   'п' => 'p',   'р' => 'r',
        'с' => 's',   'т' => 't',   'у' => 'u',
        'ф' => 'f',   'х' => 'h',   'ц' => 'c',
        'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
        'ь' => '',    'ы' => 'y',   'ъ' => '',
        'э' => 'e',   'ю' => 'yu',  'я' => 'ya',
        
        'А' => 'A',   'Б' => 'B',   'В' => 'V',
        'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
        'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
        'И' => 'I',   'Й' => 'Y',   'К' => 'K',
        'Л' => 'L',   'М' => 'M',   'Н' => 'N',
        'О' => 'O',   'П' => 'P',   'Р' => 'R',
        'С' => 'S',   'Т' => 'T',   'У' => 'U',
        'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
        'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
        'Ь' => '',    'Ы' => 'Y',   'Ъ' => '',
        'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
    );
    return strtr($string, $converter);
}

?>