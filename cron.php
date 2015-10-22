<?php
// $conf = json_decode(file_get_contents(__DIR__.'/config.json'), true);
require_once __DIR__.'/src/Staff.php';

$options = getopt("hb");
if (empty($options)) {
	echo "Для справки по использованию приложения запустите с параметром:\n";
	echo "\tphp ".basename(__FILE__)." -h\n";
	exit();
} elseif (isset($options['h'])) {
	echo "Специальный чарджер балансов клиентов Lead4CRM.\n";
	echo "Для использования данного приложения необходимо в CRON\n";
	echo "добавить следующую строку для ежедневного выполнения:\n";
	echo "\tphp ".__DIR__."/cron.php -b\n";
	exit();
} elseif (isset($options['b'])) {
	echo log_data()." Выполняем проверку на продление тарифов...\n";
	$tariff = new Stuff($conf['db']['username'], $conf['db']['password'], $conf['db']['host'], $conf['db']['database'], $conf['db']['type']);
	$result = $tariff->renewal();
	if (!empty($result)) {
		echo log_data()." Обновление тарифов выполнено для следующих пользователей:\n";
		foreach ($result as $userid => $details) {
			foreach ($details as $data) {
				if ($data['test'] === false) {
					echo log_data()."\t У пользователя #".$userid." было снято ".$data['summ']." руб.\n";
				} else {
					
				}
			}
		}
	} else {
		echo log_data()." Нет пользователей для обновления тарифов.\n";
	}
}

function log_data() {
	return "[".date('Y-m-d H:i:s')."]";
}
?>