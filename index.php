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

  case 'getRubricList':
    echo getRubricList(
      $_REQUEST['apikey'], 
      $_REQUEST['domain'],
      $_REQUEST['full']);
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

  case 'getCompanyListByRubric':
    if (is_numeric($cmd[1])) {
      $pageNum = $cmd[1] ? $cmd[1] : 1;
    }
    echo getCompanyListByRubric(
      $_REQUEST['apikey'],
      $_REQUEST['rubric'],
      $_REQUEST['city'],
      $_REQUEST['domain'],
      $pageNum);
    break;

  case 'getCompanyProfile':
    echo getCompanyProfile(
      $_REQUEST['apikey'],
      $_REQUEST['domain'],
      $_REQUEST['id'],
      $_REQUEST['hash'],
      $_REQUEST['auid']);
    break;

  case 'getCompanyProfile_dev':
    echo getCompanyProfile_dev(
      $_REQUEST['apikey'],
      $_REQUEST['domain'],
      $_REQUEST['id'],
      $_REQUEST['hash'],
      $_REQUEST['auid']);
    break;

  case 'sendEmail':
    header("Access-Control-Allow-Origin: *");
    echo sendEmail(
      $_REQUEST['to'],
      $_REQUEST['from_email'],
      $_REQUEST['from_name'],
      $_REQUEST['body']);
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
      $db = pg_connect('host='.$conf['db']['host'].' dbname='.$conf['db']['database'].' user='.$conf['db']['username'].' password='.$conf['db']['password']) or die(json_encode($db_err_message));
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
      $db = pg_connect('host='.$conf['db']['host'].' dbname='.$conf['db']['database'].' user='.$conf['db']['username'].' password='.$conf['db']['password']) or die(json_encode($db_err_message));
      $query = "select is_admin from users where apikey = '{$uAPIKey}'";
      $result = pg_query($query);
      $is_admin = pg_fetch_result($result, 0, 'is_admin');
      if ($is_admin == 't') {
        $url = 'http://catalog.api.2gis.ru/2.0/region/list?';
        $uri = http_build_query(array(
          'key' => $conf['2gis']['key'],
          // 'locale' => 'ru_RU',
          // 'locale_filter' => 'ru_RU',
          // 'country_code_filter' => 'ru',
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

function getRubricList($apikey, $domain, $full)
{
  global $conf;
  $uAPIKey = preg_replace('/[^a-z0-9]/', '', $apikey);
  if ($uAPIKey && $domain) 
  {
    if ($conf['db']['type'] == 'postgres')
    {
      $db_err_message = array('error' => 100, 'message' => 'Unable to connect to database. Please send message to support@cnamrf.ru about this error.');
      $db = pg_connect('host='.$conf['db']['host'].' dbname='.$conf['db']['database'].' user='.$conf['db']['username'].' password='.$conf['db']['password']) or die(json_encode($db_err_message));
      if ($full)
      {
        $query = "select id, name, translit, parent from rubrics where (select id from users where apikey = '{$uAPIKey}') is not null";
      }
      else
      {
        $query = "select id, name, translit from rubrics where parent is null and (select id from users where apikey = '{$uAPIKey}') is not null";
      }
      $result = pg_query($query);
      $i = 0;
      while ($row = pg_fetch_assoc($result)) {
        $rubrics[$i]['id'] = $row['id'];
        $rubrics[$i]['name'] = $row['name'];
        $rubrics[$i]['code'] = $row['translit'];
        if ($full) $rubrics[$i]['parent'] = $row['parent'];
        $i++;
      }
      pg_free_result($result);
      pg_close($db);
      $json_return = array('error' => '0', 'rubrics' => $rubrics);
    }
    return json_encode($json_return, JSON_UNESCAPED_UNICODE);
  } else {
    return json_encode(array('error' => '9', 'message' => 'Not found API access key or not specified client or not specified domain.'));
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
      $db = pg_connect('host='.$conf['db']['host'].' dbname='.$conf['db']['database'].' user='.$conf['db']['username'].' password='.$conf['db']['password']) or die(json_encode($db_err_message));
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
        $dublgis = json_decode(file_get_contents($url.$uri));
        $query = "-- Удаляем все данные из таблицы связанные именно с этим городом"."\n";
        $query.= "DELETE FROM rubrics WHERE city_id = {$city_id};"."\n";
        file_put_contents("/tmp/rubrics-{$city_id}.sql", $query, LOCK_EX);
        foreach ($dublgis->result as $result) {
          $id_parent = $result->id;
          $name_parent = pg_escape_string($result->name);
          $alias_parent = pg_escape_string($result->alias);
          $query = "\n"."-- Добавляем родительскую рубрику"."\n";
          $query.= "INSERT INTO rubrics (id, name, alias, city_id) VALUES ({$id_parent}, '{$name_parent}', '{$alias_parent}', {$city_id});"."\n";
          file_put_contents("/tmp/rubrics-{$city_id}.sql", $query, LOCK_EX | FILE_APPEND);
          if ($result->children) {
            $query = "-- Добавляем дочерние рубрики"."\n";
            file_put_contents("rubrics-{$city_id}.sql", $query, LOCK_EX | FILE_APPEND);
            foreach ($result->children as $children) {
              $id = $children->id;
              $name = pg_escape_string($children->name);
              $alias = pg_escape_string($children->alias);
              $query = "INSERT INTO rubrics (id, name, alias, parent_id, city_id) VALUES ({$id}, '{$name}', '{$alias}', {$id_parent}, {$city_id});"."\n";
              file_put_contents("/tmp/rubrics-{$city_id}.sql", $query, LOCK_EX | FILE_APPEND);
            }
          }
        }
        $json_return = array('error' => '0', 'sql_dump_created' => "rubrics-{$city_id}.sql");
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
 * Функция возвращает данные в формате JSON.
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
function getCompanyList($apikey, $text, $city, $domain, $pageNum) 
{
  global $conf;

  $apikey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
  $uClient = 'Lead4CRM';
  // $uCIP = sprintf("%u", ip2long(gethostbyname($domain)));
  $uCIP = sprintf("%u", ip2long('127.0.0.1'));
  
  if ($apikey && $text && is_numeric($city)) {
    if ($conf['db']['type'] == 'postgres')
    {
      $db_err_message = array('error' => 100, 'message' => 'Не могу подключиться к базе данных. Пожалуйста, напишите сообщение об этой ошибке по адресу: support@lead4crm.ru.');
      $db = pg_connect('dbname='.$conf['db']['database']) or 
        die(json_encode($db_err_message));
      $query = "select name from cities where id = {$city}";
      $result = pg_query($query);
      $cityName = pg_fetch_result($result, 0, 'name');
      $query = "select users.id, users.qty + trunc((select sum(debet) - sum(credit) from log where uid = (select id from users where apikey = '{$apikey}')) / tariff.price) as qty, tariff.price from users left join tariff on users.tariffid2 = tariff.id where apikey = '{$apikey}'";
      $result = pg_query($query);
      $uid = pg_fetch_result($result, 0, 'id');
      $qty = pg_fetch_result($result, 0, 'qty');
      $price = pg_fetch_result($result, 0, 'price');
      pg_free_result($result);
      if ($uid) {
        if ($qty) {
          // $query = "update users set qty = qty - 1 where id = {$uid}";
          // pg_query($query);
          $text = pg_escape_string($text);
          $query = "insert into log (uid, client, ip, text, domain) values ({$uid}, '{$uClient}', {$uCIP}, '{$text}', '{$domain}')";
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
            'qty' => $qty,
            'result' => $result);
        } 
        else 
        {
          $query = "select (sum(debet) - sum(credit)) as balans from log where uid = {$uid}";
          $result = pg_query($query);
          $balans = pg_fetch_result($result, 0, 'balans');
          if ($balans >= $price) 
          {
            // $query = "select qty + trunc((select sum(debet) - sum(credit) from log where uid = {$uid}) / (select price from tariff where id = (select tariffid2 from users where id = {$uid}))) as qty from users where id = {$uid}";
            // $result = pg_query($query);
            // $qty = pg_fetch_result($result, 0, 'qty');
            // $query = "insert into log (uid, credit, client, ip, text) values ({$uid}, '{$price}', '{$uClient}', $uCIP, '$text')";
            // pg_query($query);
            $text = pg_escape_string($text);
            $query = "insert into log (uid, client, ip, text, domain) values ({$uid}, '{$uClient}', {$uCIP}, '{$text}', '{$domain}')";
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
              'qty' => $qty,
              'result' => $result);
          } 
          else 
          {
            $json_return = array('error' => '5', 'message' => 'Не достаточно средств. Посетите https://www.lead4crm.ru и пополните баланс любым удобным способом.');
          }
        }
      } 
      else 
      {
        $json_return = array('error' => '3', 'message' => 'Не найден ни один пользователь по вашему ключу доступа.');
      }
      pg_close($db);
    }
    return json_encode($json_return);
  } else {
    return json_encode(array('error' => '2', 'message' => 'Не найден ключ доступа, текст поиска или идентификатор города.'));
  }
}

/**
 * Функция получает список компаний по указанной рубрике $rubric
 * в указанном городе $city для пользователя $apikey с
 * запросом от указанного доменного имени $domain.
 * Функция возвращает данные в формате JSON.
 *
 * В случае успеха возвращает JSON-строку с нулевым кодом ошибки (error).
 *
 * В случае неудачи возвращает JSON-строку с кодом ошибки (error)
 * и сообщением самой ошибки (message).
 * 
 * @param  string  $apikey  Пользовательский ключ доступа
 * @param  string  $rubric  Название рубрики
 * @param  integer $city    Индентификатор города
 * @param  string  $domain  Домен пользовательского Битрикс24
 * @param  integer $pageNum Номер страницы
 * @return string           Данные в формате JSON
 */
function getCompanyListByRubric($apikey, $rubric, $city, $domain, $pageNum)
{
  global $conf;
  $apikey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
  $uClient = 'Lead4CRM';
  $uCIP = sprintf("%u", ip2long('127.0.0.1'));

  if ($apikey && $rubric && is_numeric($city))
  {
    if ($conf['db']['type'] == 'postgres')
    {
      $db_err_message = array('error' => 100, 'message' => 'Не могу подключиться к базе данных. Пожалуйста, напишите сообщение об этой ошибке по адресу: support@lead4crm.ru.');
      $db = pg_connect('dbname='.$conf['db']['database']) or 
        die(json_encode($db_err_message));
      $query = "select name from cities where id = {$city}";
      $result = pg_query($query);
      $cityName = pg_fetch_result($result, 0, 'name');
      $query = "select users.id, users.qty + trunc((select sum(debet) - sum(credit) from log where uid = (select id from users where apikey = '{$apikey}')) / tariff.price) as qty, tariff.price from users left join tariff on users.tariffid2 = tariff.id where apikey = '{$apikey}'";
      $result = pg_query($query);
      $uid = pg_fetch_result($result, 0, 'id');
      $qty = pg_fetch_result($result, 0, 'qty');
      $price = pg_fetch_result($result, 0, 'price');
      pg_free_result($result);
      if ($uid) 
      {
        if ($qty) 
        {
          $rubric = pg_escape_string($rubric);
          $query = "insert into log (uid, client, ip, text, domain) values ({$uid}, '{$uClient}', {$uCIP}, '{$rubric}', '{$domain}')";
          pg_query($query);
          $url = 'http://catalog.api.2gis.ru/searchinrubric?';
          $uri = http_build_query(array(
            'key' => $conf['2gis']['key'],
            'version' => '1.3',
            'what' => $rubric,
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
            'qty' => $qty,
            'result' => $result);
        }
        else
        {
          $query = "select (sum(debet) - sum(credit)) as balans from log where uid = {$uid}";
          $result = pg_query($query);
          $balans = pg_fetch_result($result, 0, 'balans');
          if ($balans >= $price) 
          {
            $rubric = pg_escape_string($rubric);
            $query = "insert into log (uid, client, ip, text, domain) values ({$uid}, '{$uClient}', {$uCIP}, '{$rubric}', '{$domain}')";
            pg_query($query);
            $url = 'http://catalog.api.2gis.ru/search?';
            $uri = http_build_query(array(
              'key' => $conf['2gis']['key'],
              'version' => '1.3',
              'what' => $rubric,
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
              'qty' => $qty,
              'result' => $result);
          }
          else
          {
            $json_return = array('error' => '5', 'message' => 'Не достаточно средств. Посетите https://www.lead4crm.ru и пополните баланс любым удобным способом.');
          }
        }
      }
      else
      {
        $json_return = array('error' => '3', 'message' => 'Не найден ни один пользователь по вашему ключу доступа.');
      }
      pg_close($db);
    }
    return json_encode($json_return);
  }
  else
  {
    return json_encode(array('error' => '10', 'message' => 'Не найден ключ доступа, рубрика или идентификатор города.'));
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
function getCompanyProfile($api, $domain, $id, $hash, $auid) 
{
  global $conf;

  $apikey = preg_replace('/[^a-z0-9]/', '', $_REQUEST['apikey']);
  $uClient = 'Lead4CRM';
  // $uCIP = sprintf("%u", ip2long(gethostbyname($domain)));
  $uCIP = sprintf("%u", ip2long('127.0.0.1'));
  
  if ($apikey && $hash && is_numeric($id)) {
    if ($conf['db']['type'] == 'postgres')
    {
      $db_err_message = array('error' => 100, 'message' => 'Не могу подключиться к базе данных. Пожалуйста, напишите сообщение об этой ошибке по адресу: support@lead4crm.ru.');
      $db = pg_connect('dbname='.$conf['db']['database']) or 
        die(json_encode($db_err_message));
      $query = "select users.id, users.qty, tariff.price from users left join tariff on users.tariffid2 = tariff.id where apikey = '{$apikey}'";
      $result = pg_query($query);
      $uid = pg_fetch_result($result, 0, 'id');
      $qty = pg_fetch_result($result, 0, 'qty');
      $price = pg_fetch_result($result, 0, 'price');
      pg_free_result($result);
      if ($uid) 
      {
        $phones_masks = json_decode(file_get_contents(__DIR__.'/../js/phones-ru.json'));
        for ($i = 0; $i < count($phones_masks); $i++) {
          $pattern = "/\((\d{4})\)|\((\d{5})\)/";
          preg_match($pattern, $phones_masks[$i]->mask, $mask[$i]);
          unset($mask[$i][0]);
        }
        rsort($mask, SORT_NUMERIC);
        unset($phones_masks);

        if ($qty) 
        {
          $query = "update users set qty = qty - 1 where id = {$uid}";
          pg_query($query);
          $query = "select json from cnam_cp where id = {$id} and hash = '{$hash}'";
          $result = pg_query($query);
          $cp_json = pg_fetch_result($result, 0, 'json');
          if (!$cp_json) {
            $url = 'http://catalog.api.2gis.ru/profile?';
            $uri = http_build_query(array(
              'key' => $conf['2gis']['key'],
              'version' => '1.3',
              'id' => $id,
              'hash' => $hash));
            $cp_json = file_get_contents($url.$uri);
            $cp = pg_escape_string($cp_json);
            $query = "insert into cnam_cp (id, hash, json) values ({$id}, '{$hash}', '{$cp}')";
            pg_query($query);
          }
          $dublgis = json_decode($cp_json);
          $lon = $dublgis->lon;
          $lat = $dublgis->lat;
          $query = "select json from geodata where lon = '{$lon}' and lat = '{$lat}'";
          $result = pg_query($query);
          $gd_json = pg_fetch_result($result, 0, 'json');
          if (!$gd_json) {
            $url = 'http://catalog.api.2gis.ru/geo/search?';
            $uri = http_build_query(array(
              'key' => $conf['2gis']['key'],
              'version' => '1.3',
              'q' => $dublgis->lon.','.$dublgis->lat));
            $gd_json = file_get_contents($url.$uri);
            $gd = pg_escape_string($gd_json);
            $query = "insert into geodata (log, lat, json) values ('{$lon}', '{$lat}', '{$gd}')";
            pg_query($query);
          }
          $geoData = json_decode($gd_json);
          $companyName = pg_escape_string($dublgis->name);
          $query = "insert into log (uid, client, ip, text, domain) values ({$uid}, '{$uClient}', $uCIP, '{$companyName}', '{$domain}') returning id";
          $result = pg_query($query);
          $logId = pg_fetch_result($result, 0, 'id');
          $query = "insert into cnam_cache (logid, cp_id, cp_hash, lon, lat) values ({$logId}, '{$id}', '{$hash}', '{$lon}', '{$lat}')";
          pg_query($query);
          $json_return = getCompanyProfileArray($auid, $dublgis, $geoData);
        } 
        else 
        {
          $query = "select (sum(debet) - sum(credit)) as balans from log where uid = {$uid}";
          $result = pg_query($query);
          $balans = pg_fetch_result($result, 0, 'balans');
          if ($balans >= $price) 
          {
            $query = "select json from cnam_cp where id = {$id} and hash = '{$hash}'";
            $result = pg_query($query);
            $cp_json = pg_fetch_result($result, 0, 'json');
            if (!$cp_json) {
              $url = 'http://catalog.api.2gis.ru/profile?';
              $uri = http_build_query(array(
                'key' => $conf['2gis']['key'],
                'version' => '1.3',
                'id' => $id,
                'hash' => $hash));
              $cp_json = file_get_contents($url.$uri);
              $cp = pg_escape_string($cp_json);
              $query = "insert into cnam_cp (id, hash, json) values ({$id}, '{$hash}', '{$cp}')";
              pg_query($query);
            }
            $dublgis = json_decode($cp_json);
            $lon = $dublgis->lon;
            $lat = $dublgis->lat;
            $query = "select json from geodata where lon = '{$lon}' and lat = '{$lat}'";
            $result = pg_query($query);
            $gd_json = pg_fetch_result($result, 0, 'json');
            if (!$gd_json) {
              $url = 'http://catalog.api.2gis.ru/geo/search?';
              $uri = http_build_query(array(
                'key' => $conf['2gis']['key'],
                'version' => '1.3',
                'q' => $dublgis->lon.','.$dublgis->lat));
              $gd_json = file_get_contents($url.$uri);
              $gd = pg_escape_string($gd_json);
              $query = "insert into geodata (log, lat, json) values ('{$lon}', '{$lat}', '{$gd}')";
              pg_query($query);
            }
            $geoData = json_decode($gd_json);
            $companyName = pg_escape_string($dublgis->name);
            $query = "insert into log (uid, credit, client, ip, text, domain) values ({$uid}, '{$price}', '{$uClient}', $uCIP, '{$companyName}', '{$domain}') returning id";
            $result = pg_query($query);
            $logId = pg_fetch_result($result, 0, 'id');
            $query = "insert into cnam_cache (logid, cp_id, cp_hash, lon, lat) values ({$logId}, '{$id}', '{$hash}', '{$lon}', '{$lat}')";
            pg_query($query);
            $json_return = getCompanyProfileArray($auid, $dublgis, $geoData);
          } else {
            $json_return = array('error' => '5', 'message' => 'Не достаточно средств. Посетите https://www.lead4crm.ru и пополните баланс любым удобным способом.');
          }
        }
      } else {
        $json_return = array('error' => '3', 'message' => 'Не найден ни один пользователь по вашему ключу доступа.');
      }
      pg_close($db);
    }
    return json_encode($json_return);
  } else {
    return json_encode(array('error' => '2', 'message' => 'Не найден ключ доступа или отсутствует хэш или отсутсвует идентификатор компании.'));
  }
}

/**
 * Функция построения массива карточки компании.
 * @param  integer $auid    Уникальный идентификатор ответственного сотрудника Б24
 * @param  object  $dublgis Объект содержащий ответ API сервера 2ГИС с карточкой компании
 * @param  object  $geoData Объект содержащий ответ API сервера 2ГИС с гео-данными
 * @return array            Массив данных карточки компании для Б24
 */
function getCompanyProfileArray($auid, $dublgis, $geoData)
{
  $json_return = array(
    'error' => '0',
    'auid' => $auid,
    'id' => $dublgis->id,
    'log' => $dublgis->lon,
    'lat' => $dublgis->lat,
    'name' => $dublgis->name,
    'address' => $dublgis->address,
    'address_2' => $dublgis->additional_info->office,
    'city_name' => $dublgis->city_name,
    'region' => $geoData->result[0]->attributes->district,
    'postal_code' => $geoData->result[0]->attributes->index,
    'currency' => $dublgis->additional_info->currency,
    'industry' => getGeneralIndustry($dublgis->rubrics));
  for ($i = 0; $i < count($dublgis->contacts); $i++)
  {
    foreach ($dublgis->contacts[$i]->contacts as $contact) 
    {
      if ($contact->type == 'phone') {
        $phone = $contact->value;
        $pwop = substr($phone, 1);
        for ($x = 0; $x < count($mask); $x++)
        {
          if (substr($pwop, 1, 5) == $mask[$x][2])
          {
            $phone = '+7 (' . $mask[$x][2] . ') ' . substr($pwop, 6, 1) . '-' . substr($pwop, 7, 2) . '-' . substr($pwop, 9, 2);
            break;
          }
          elseif (substr($pwop, 1, 4) == $mask[$x][1])
          {
            $phone = '+7 (' . $mask[$x][1] . ') ' . substr($pwop, 5, 2) . '-' . substr($pwop, 7, 2) . '-' . substr($pwop, 9, 2);
            break;
          }
          else
          {
            $phone = '+7 (' . substr($pwop, 1, 3) . ') ' . substr($pwop, 4, 3) . '-' . substr($pwop, 7, 2) . '-' . substr($pwop, 9, 2);
          }
        }
        $json_return['phone'][] = array(
          "VALUE" => $phone, 
          "VALUE_TYPE" => "WORK");
      } elseif ($contact->type == 'fax') {
        $phone = $contact->value;
        $pwop = substr($phone, 1);
        for ($x = 0; $x < count($mask); $x++)
        {
          if (substr($pwop, 1, 5) == $mask[$x][2])
          {
            $phone = '+7 (' . $mask[$x][2] . ') ' . substr($pwop, 6, 1) . '-' . substr($pwop, 7, 2) . '-' . substr($pwop, 9, 2);
            break;
          }
          elseif (substr($pwop, 1, 4) == $mask[$x][1])
          {
            $phone = '+7 (' . $mask[$x][1] . ') ' . substr($pwop, 5, 2) . '-' . substr($pwop, 7, 2) . '-' . substr($pwop, 9, 2);
            break;
          }
          else
          {
            $phone = '+7 (' . substr($pwop, 1, 3) . ') ' . substr($pwop, 4, 3) . '-' . substr($pwop, 7, 2) . '-' . substr($pwop, 9, 2);
          }
        }
        $json_return['phone'][] = array(
          "VALUE" => $phone,
          "VALUE_TYPE" => "FAX");
      } elseif ($contact->type == 'email') {
        $json_return['email'][] = array(
          "VALUE" => $contact->value,
          "VALUE_TYPE" => "WORK");
      } elseif ($contact->type == 'website') {
        $json_return['web'][] = array(
          "VALUE" => 'http://'.$contact->alias,
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
  }
  if (count($dublgis->rubrics)) {
    $json_return['comments'] = "<p><b>Виды деятельности:</b></p><ul>";
    foreach ($dublgis->rubrics as $rubric) {
      $json_return['comments'] .= '<li>'.$rubric.'</li>';
    }
    $json_return['comments'] .= '</ul>';
  }
  $url_name = rawurlencode($dublgis->name);
  $additional_info = "<p><b>Дополнительная информация:</b></p><ul>"
      . "<li><a href='http://2gis.ru/city/{$dublgis->project_id}/center/{$dublgis->lon}%2C{$dublgis->lat}/zoom/17/routeTab/to/{$dublgis->lon}%2C{$dublgis->lat}%E2%95%8E{$url_name}?utm_source=profile&utm_medium=route_to&utm_campaign=partnerapi' target='_blank'>Проложить маршрут до {$dublgis->name}</a></li>"
      . "<li><a href='http://2gis.ru/city/{$dublgis->project_id}/center/{$dublgis->lon}%2C{$dublgis->lat}/zoom/17/routeTab/from/{$dublgis->lon}%2C{$dublgis->lat}%E2%95%8E{$url_name}?utm_source=profile&utm_medium=route_from&utm_campaign=partnerapi' target='_blank'>Проложить маршрут от {$dublgis->name}</a></li>"
      . "<li><a href='http://2gis.ru/city/{$dublgis->project_id}/firm/{$dublgis->id}/entrance/center/{$dublgis->lon}%2C{$dublgis->lat}/zoom/17?utm_source=profile&utm_medium=entrance&utm_campaign=partnerapi' target='_blank'>Показать вход</a></li>"
      . "<li><a href='http://2gis.ru/city/{$dublgis->project_id}/firm/{$dublgis->id}/photos/{$dublgis->id}/center/{$dublgis->lon}%2C{$dublgis->lat}/zoom/17?utm_source=profile&utm_medium=photo&utm_campaign=partnerapi' target='_blank'>Фотографии {$dublgis->name}</a></li>"
        . "<li><a href='http://2gis.ru/city/{$dublgis->project_id}/firm/{$dublgis->id}/flamp/{$dublgis->id}/callout/firms-{$dublgis->id}/center/{$dublgis->lon}%2C{$dublgis->lat}/zoom/17?utm_source=profile&utm_medium=review&utm_campaign=partnerapi' target='_blank'>Отзывы о {$dublgis->name}</a></li>";
  $additional_info_service_price = "<li><a href='{$dublgis->bookle_url}?utm_source=profile&utm_medium=booklet&utm_campaign=partnerapi' target='_blank'>Услуги и цены {$dublgis->name}</a></li>";
  if ($json_return['comments']) {
    $json_return['comments'] .= $additional_info;
    if ($dublgis->bookle_url) {
      $json_return['comments'] .= $additional_info_service_price;
    }
  } else {
    $json_return['comments'] = $additional_info;
    if ($dublgis->bookle_url) {
      $json_return['comments'] .= $additional_info_service_price;
    }
  }
  return $json_return;
}

function getGeneralIndustry($rubrics) 
{
  global $conf;
  if ($conf['db']['type'] == 'postgres')
  {
    if (count($rubrics)) {
      $db_err_message = array('error' => 100, 'message' => 'Unable to connect to database. Please send message to support@lead4crm.ru about this error.');
      $parents = array();
      foreach ($rubrics as $rubric) {
        $rubric = pg_escape_string($rubric);
        $query = "select parent from rubrics where name = '{$rubric}'";
        $result = pg_query($query);
        $parent_id1 = pg_fetch_result($result, 0, 0);
        $query = "select parent from rubrics where id = {$parent_id1}";
        $result = pg_query($query);
        $parent_id2 = pg_fetch_result($result, 0, 0);
        if ($parent_id2) 
        {
          $parents[] = $parent_id2;
        }
        else
        {
          $parents[] = $parent_id1;
        }
      }
      $main_parent = $main_parent2 = array_count_values($parents);
      arsort($main_parent2);
      foreach ($main_parent2 as $parent_id => $count) {
        if ($count > 1)
          $query = "select name, translit from rubrics where id = {$parent_id}";
        else {
          $parent_id = key($main_parent);
          $query = "select name, translit from rubrics where id = {$parent_id}";
        }
        break;
      }
      $result = pg_query($query);
      $name = pg_fetch_result($result, 0, 'name');
      $code = pg_fetch_result($result, 0, 'translit');
    } else {
      $name = 'Другое';
      $code = 'OTHER';
    }
  }
  return array('code' => $code, 'name' => $name);
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

function sendEmail($to, $from_email, $from_name, $body)
{
  if ($_SERVER['HTTP_ORIGIN'] == 'http://castle-if.github.io' or
    $_SERVER['HTTP_ORIGIN'] == 'http://castle-if.ru')
  {
    $body = "Есть новый узник: {$body}";
    $headers = "From: {$from_name} <{$from_email}>\r\n";
    mail($to, 'Архивариусу Замка If', $body, $headers);
    $response = 'Все отлично!';
  }
  else
  {
    $response = 'Очень странно, но вы пытаетесь отправить письмо через мой сервер. У нас так не делается. Это запрещено законом.';
  }
  return json_encode(array('response' => $response));
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