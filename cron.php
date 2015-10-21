<?php
// $conf = json_decode(file_get_contents(__DIR__.'/config.json'), true);
// unset($argv[0]);
// if (implode(' ', $argv) == ''); {
// 	echo 'Для справки по использованию приложения запустите с параметром:'.PHP_EOL;
// 	echo "\tphp ".basename(__FILE__)." -h\n";
// 	exit();
// }

$options = getopt("hb");
if (isset($options['h'])) {
	echo "Специальный чарджер балансов клиентов Lead4CRM.\n";
	echo "Для использования данного приложения необходимо в CRON\n";
	echo "добавить следующую строку для ежедневного выполнения:\n";
	echo "\tphp ".__DIR__."/cron.php -b\n";
	exit();
} elseif (isset($options['b'])) {
	$date = date('Y-m-d');
	echo $date." Выполняем проверку на продление тарифов...\n";
}
?>