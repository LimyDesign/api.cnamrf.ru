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
		echo getName($cmd[1]);
		break;
	default:
		echo defaultResult();
}

function getName($number) {
	global $conf;

	$number = preg_replace('/[+()-\s]/', '', $number);
	if (substr($number, 0, 1) == '8' && strlen($number) == 11) {
		$number = preg_replace('/^8/', '7', $number);
	} elseif (strlen($number) == 10) {
		$number = '7' . $number;
	}

	$uAPIKey = $_REQUEST['apikey'];
	$uClient = $_REQUEST['client'];
	$uCIP = sprintf("%u", ip2long($_SERVER['REMOTE_ADDR']));

	if ($uAPIKey && $uClient && $uCIP && is_numeric($number))
	{
		if ($conf['db']['type'] == 'postgres')
		{
			$db = pg_connect('dbname='.$conf['db']['database']);
			$query = "select users.id, users.qty, tariff.price from users left join tariff on users.tariffid = tariff.id where apikey = '{$uAPIKey}'";
			$result = pg_query($query);
			$uid = pg_fetch_result($result, 0, 'id');
			$qty = pg_fetch_result($result, 0, 'qty');
			$price = pg_fetch_result($result, 0, 'price');
			pg_free_result($result);
			if ($uid) {
				if ($qty) {
					$query = "select name, translit from phonebook where phone = {$number} and verify = true";
					$result = pg_query($query);
					$name = pg_fetch_result($result, 0, 'name');
					$translit = pg_fetch_result($result, 0, 'translit');
					if ($name && $translit) {
						$query = "update users set qty = qty - 1 where id = {$uid}";
						pg_query($query);
						$query = "insert into log (uid, phone, client, ip) values ({$uid}, {$number}, '{$uClient}', {$uCIP})";
						pg_query($query);
						$json_return = array('error' => 0, 'name' => $name, 'translit' => $translit);
					} else {
						$phones_masks = json_decode(file_get_contents(__DIR__.'/../www/js/phones-ru.json'), true);
						array_multisort($phones_masks, SORT_DESC);
						foreach ($phones_masks as $masks) {
							foreach ($masks as $key => $value) {
								$pattern = "/\((\d{3})\)|\((\d{4})\)|\((\d{5})\)/";
								preg_match($pattern, $value['mask'], $mask);
								echo $mask . "\n";
								if ($mask == substr($number, 1, 5)) {
									if ($value['city']) {
										if (count($value['city']) == 1) {
											$city = $value['city'];
											break 2;
										} else {
											$city = $value['city'][0];
											break 2;
										}
									} else {
										$city = $value['region'];
										break 2;
									}
								}
							}
						}
						header("Content-Type: text/plain");
						var_dump($city);
						die();
						// for ($i = 0; $i < count($phones_masks); $i++) {
						// 	$pattern = "/\((\d{3})\)|\((\d{4})\)|\((\d{5})\)/";
						// 	preg_match($pattern, $phones_masks[$i]['mask'], $mask[$i]);
						// 	unset($mask[$i][0]);
						// }
						// rsort($mask, SORT_NUMERIC);
						// $url = 'http://catalog.api.2gis.ru/search?';
						// $uri = http_build_query(array(
						// 	'key' => $conf['2gis']['key'],
						// 	'version' => '1.3',
						// 	'what' => $number,
						// 	'where' => $city));
						// $dublgis = json_decode(file_get_contents($url.$uri));
					}
				}
			} else {
				$json_return = array('error' => 3, 'message' => 'Not found any users for your API access key.');
			}
			pg_close($db);
		}
		return json_encode($json_return);
	} else {
		return json_encode(array('error' => 2, 'message' => 'Not found API access key or not specified client or not specified phone number.'));
	}
}

function defaultResult() {
	return json_encode(array('error' => 1, 'message' => 'Failed requests to the API interface.'));
}
?>