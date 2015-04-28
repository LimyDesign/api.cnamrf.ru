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

	$uAPIKey = $_REQUEST['apikey'];

	if ($uAPIKey)
	{
		if ($conf['db']['type'] == 'postgres')
		{
			$db = pg_connect('dbname='.$conf['db']['database']);

		}
	} else {
		return json_encode(array('error' => 2, 'message' => 'Not found API access key.'));
	}
}

function defaultResult() {
	return json_encode(array('error' => 1, 'message' => 'Failed requests to the API interface.'));
}
?>