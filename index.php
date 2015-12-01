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

include "src/API.php";

$conf = json_decode(file_get_contents(__DIR__.'/config.json'), true);

$requestURI = explode('/',$_SERVER['REQUEST_URI']);
$scriptName = explode('/',$_SERVER['SCRIPT_NAME']);
for ($i=0;$i<sizeof($scriptName);$i++)
{
  if ($requestURI[$i] == $scriptName[$i])
    unset($requestURI[$i]);
}
$cmd = array_values($requestURI);

$API = new API($conf);

header("Content-Type: application/json");

switch ($cmd[0]) {
  case 'getName':
    if ($_REQUEST['number']) $cmd[1] = $_REQUEST['number'];
    echo $API->getName($cmd[1], $_REQUEST['apikey'], $_REQUEST['client'], $_SERVER['REMOTE_ADDR']);
    break;
  
  case 'get2GisCities':
    echo $API->get2GisCities($_REQUEST['apikey']);
    break;

  case 'getRubricList':
    echo $API->getRubricList($_REQUEST['apikey'], $_REQUEST['domain'], $_REQUEST['full']);
    break;

  case 'getCompanyList':
    if (is_numeric($cmd[1]))
      $page = $cmd[1] ? $cmd[1] : 1;
    echo $API->getCompanyList($_REQUEST['apikey'], $_REQUEST['text'], $_REQUEST['city'], $_REQUEST['domain'], $page);
    break;

  case 'getCompanyListByRubric':
    if (is_numeric($cmd[1]))
      $page = $cmd[1] ? $cmd[1] : 1;
    echo $API->getCompanyListByRubric($_REQUEST['apikey'], $_REQUEST['rubric'], $_REQUEST['city'], $_REQUEST['domain'], $page);
    break;

  case 'getCompanyProfile':
    echo $API->getCompanyProfile($_REQUEST['apikey'], $_REQUEST['domain'], $_REQUEST['id'], $_REQUEST['hash'], $_REQUEST['auid'], $_REQUEST['ip'], $_REQUEST['2gis']);
    break;

  default:
    echo $API->defaultResult();
}
?>