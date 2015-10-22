<?php

require_once(__DIR__.'/src/Staff.php');

$conf = json_decode(file_get_contents(__DIR__.'/config.json'), true);

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
	$staff = new Staff($conf['db']['username'], $conf['db']['password'], $conf['db']['host'], $conf['db']['database'], $conf['db']['type']);
	echo log_data()." Выполняем проверку на уведомление пользователей...\n";
	echo resultRenewal($staff);
}

function resultRenewal($staff, $mode = false) {
	$result = $staff->renewal($mode);
	$msg = array();
	if ($mode) {
		if (!empty($result)) {
			$msg[] = log_data()." Обновление тарифов выполнено для следующих пользователей:\n";
			foreach ($result as $userid => $details) {
				foreach ($details as $data) {
					$sum = 0;
					if (isset($data['renew_sum_1'])) $sum += $data['renew_sum_1'];
					if (isset($data['renew_sum_2'])) $sum += $data['renew_sum_2'];
					$msg[] = log_data()."\t У пользователя #".$userid." было снято ".$sum." руб.\n";
				}
			}
		} else {
			$msg[] = log_data()." Нет пользователей для обновления тарифов.\n";
		}
	} else {
		if (!empty($result)) {
			$msg[] = log_data()." Отправляем пользователям уведомления:\n";
			foreach ($result as $userid => $details) {
				foreach ($details as $data) {
					$msg[] = log_data()."\t Пользователь #".$userid." получил уведомление.\n";
				}
			}
		} else {
			$msg[] = log_data()." Нет пользователей для уведомления.\n";
		}
		$msg[] = log_data()." Переключаемся на проверку реальных обновлений...\n";
		$msg[] = resultRenewal($staff, true);
	}
	
	return implode('', $msg);
}

function log_data() {
	return "[".date('Y-m-d H:i:s')."]";
}
?>