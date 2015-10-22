<?php
$conf = json_decode(file_get_contents(__DIR__.'/config.json'), true);
require_once __DIR__.'/src/Staff.php';

$options = getopt("hb");
if (empty($options)) {
	echo "Для справки по использованию приложения запустите с параметром:\n";
	echo "\tphp ".basename(__FILE__)." -h\n";
	exit();
} elseif (isset($options['h'])) {
	echo "Специальный чарджер балансов клиентов CNAM РФ и Lead4CRM.\n";
	echo "Для использования данного приложения необходимо в CRON\n";
	echo "добавить следующую строку для ежедневного выполнения:\n";
	echo "\tphp ".__DIR__."/cron.php -b\n";
	exit();
} elseif (isset($options['b'])) {
	echo log_data()." Выполняем проверку на продление тарифов...\n";
	$staff = new Stuff($conf['db']['username'], $conf['db']['password'], $conf['db']['host'], $conf['db']['database'], $conf['db']['type']);
	echo resultRenewal($staff);
}

function resultRenewal($staff, $mode = false) {
	$result = $staff->renewal($mode);
	$test = 0;
	$msg = array();
	if (!empty($result)) {
		$msg[] = log_data()." Обновление тарифов выполнено для следующих пользователей:\n";
		foreach ($result as $userid => $details) {
			foreach ($details as $data) {
				if ($data['test'] === false) {
					$sum = 0;
					if (isset($data['renew_sum_1'])) $sum += $data['renew_sum_1'];
					if (isset($data['renew_sum_2'])) $sum += $data['renew_sum_2'];
					$msg[] = log_data()."\t У пользователя #".$userid." было снято ".$sum." руб.\n";
				} else {
					$test++;
				}
			}
		}

		if ($test) {
			resultRenewal($staff, true);
		}
	} else {
		$msg[] = log_data()." Нет пользователей для обновления тарифов.\n";
	}
	return implode('', $msg);
}

function log_data() {
	return "[".date('Y-m-d H:i:s')."]";
}
?>