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
	default:
		echo defaultResult();
}

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

function defaultResult() 
{
	return json_encode(array('error' => '1', 'message' => 'Failed requests to the API interface.'));
}

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
		$query = "select name, translit from phone_cache where number = {$number} and queries >= 3 and modtime + interval '1 week' < now()";
		die($query);
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
			return array('error' => '0', 'name' => $name, 'translit' => $translit, 'from' => 'cnamrf');
		} else {
			$phones_masks = json_decode(file_get_contents(__DIR__.'/../www/js/phones-ru.json'), true);
			array_multisort($phones_masks, SORT_DESC);
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
					}
				}
			}
			$query = "select id from cities where name = '{$city}'";
			$result = pg_query($query);
			if (pg_fetch_result($result, 0, 0)) {
				$query = "select number from phones_notexists where number = {$number}";
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
						return array('error' => '0', 'name' => $name, 'translit' => $translit, 'from' => '2gis');
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
				return array('error' => '7', 'message' => 'This city is not currently supported. If you can prove ownership of the number, add it to your personal phone book. But if you believe that in your town there 2GIS company, then email the customer support: support@cnamrf.ru.');
			}
		}
	}
}

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