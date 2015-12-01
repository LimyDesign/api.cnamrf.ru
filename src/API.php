<?php
/**
 * Created by PhpStorm.
 * @autor Arsen G Bespalov <bespalov@asen.pw>
 * @version 2.0.0
 * @copyright Copyright (c) 2015, Arsen G Bespalov
 * Date: 29/11/15
 * Time: 11:43
 */

class API
{
    protected $db;
    private $dns, $usename, $password, $conf;

    /**
     * API constructor.
     * @param string $conf Массив с настройками системы, содержащий различные переменные и хранящий логины и пароли
     */
    public function __construct($conf)
    {
        if ($conf['db']['type'] == 'postgres')
        {
            $type = 'pgsql';
            $this->dns = $type.':host='.$conf['db']['host'].';dbname='.$conf['db']['database'];
        }
        $this->usename = $conf['db']['username'];
        $this->password = $conf['db']['password'];
        $this->conf = $conf;
        $this->connect();
    }

    /**
     * Функция подключения к БД
     * @return PDO
     */
    private function connect()
    {
        try
        {
            $db = new \PDO($this->dns, $this->usename, $this->password);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_EMPTY_STRING);
            $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        }
        catch (\PDOException $e)
        {
            $this->exception($e);
        }
        $this->db = $db;
        return $this->db;
    }

    /**
     * Функция возвращает данные по номеру телефона $number.
     * В случае удачи возвращает JSON-строку в которой содержиться имя по-русски (name),
     * и имя в транслите (translit), а также нулевой код ошибки (error).
     *
     * В случае неудачи возвращается JSON-строка с кодом ошибки (error) и текстом ошибки (message).
     * Ошибка №7 имеет дополнительное поле (city) в котором указывается определившийся город
     * по запрошенному номеру телефона. Служит для отладки правильности составленных масок номеров для городов.
     *
     * @param string|integer $number Номер телефона, который необходимо найти в справочнике в формате E.164
     * @param string $apikey Пользовательский ключ доступа к API
     * @param string $client Завание клиента, через который происходит обращение к API
     * @param string $remote_addr IP-адрес пользователя
     * @return string Возвращает данные в формате JSON
     */
    public function getName($number, $apikey, $client, $remote_addr)
    {
        $db = $this->db;
        $number = preg_replace('/[+()-\s]/', '', $number);
        if (substr($number, 0, 1) == '8' && strlen($number) == 11)
        {
            $number = preg_replace('/^8/', '7', $number);
        }
        elseif (strlen($number) == 10)
        {
            $number = '7'.$number;
        }

        $uAPIKey = preg_replace('/[^a-z0-9]/', '', $apikey);
        $uClient = $client;
        $uCIP = sprintf("%u", ip2long($remote_addr));

        if ($uAPIKey && $uClient && $uCIP && is_numeric($number))
        {
            $query = "SELECT users.id, users.qty, tariff.price FROM users LEFT JOIN tariff ON users.tariffid = tariff.id WHERE apikey = :apikey";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':apikey', $uAPIKey, \PDO::PARAM_STR);
                $sth->execute();
                $row = $sth->fetch();
                $uid = $row['uid'];
                $qty = $row['qty'];
                $price = $row['price'];
                if ($uid)
                {
                    if ($qty)
                    {
                        $json_message = $this->getData($number, $uid, $uClient, $uCIP);
                    }
                    else
                    {
                        $balance = $this->getUserBalance($uid);
                        if ($balance >= $price)
                        {
                            $json_message = $this->getData($number, $uid, $uClient, $uCIP, $price);
                        }
                        else
                        {
                            $json_message = array(
                                'error' => '5',
                                'message' => 'Недостаточно средст. Пополните баланс на сайте https://www.cnamrf.ru и попробуйте еще раз.'
                            );
                        }
                    }
                }
                else
                {
                    $json_message = array(
                        'error' => '3',
                        'message' => 'Не найден ни один пользователь для указанного ключа доступа'
                    );
                }
            } catch (\PDOException $e) {
                $this->exception($e);
            }
        }
        else
        {
            $json_message = array(
                'error' => '2',
                'message' => 'Отсутствует обязательный параметр.'
            );
        }
        return json_encode($json_message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Функция получает список городов в которых присутствует
     * компания ДубльГИС и возвращает список в формате JSON.
     *
     * @param string $apikey Пользовательский ключ доступа
     * @return string Список городов в формате JSON
     */
    public function get2GisCities($apikey)
    {
        $db = $this->db;
        $apikey = preg_replace('/[^a-z0-9]/', '', $apikey);
        if ($apikey)
        {
            $query = "SELECT is_admin FROM users WHERE apikey = :apikey";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':apikey', $apikey, \PDO::PARAM_STR);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $is_admin = $row['is_admin'];
            if ($is_admin == 't')
            {
                $url = 'http://catalog.api.2gis.ru/2.0/region/list?';
                $uri = http_build_query(
                    array(
                        'key' => $this->conf['2gis']['key'],
                        'format' => 'json',
                        'page_size' => '150'
                    )
                );
                $dublgis = json_decode(file_get_contents($url.$uri));
                $total = $dublgis->result->total;
                if ($total)
                {
                    $city_names = array();
                    foreach ($dublgis->result->items as $city)
                    {
                        $city_names[] = $city->name;
                    }
                    $query = "SELECT insertCities(array[:cities]) AS totalinsert";
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':cities', $this->array2csv($city_names, ',', "'", true), \PDO::PARAM_LOB);
                        $sth->execute();
                        $row = $sth->fetch();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    $json_message = array(
                        'error' => '0',
                        'total' => $total,
                        'total_insert' => $row['totalinsert']
                    );
                }
            }
            else
            {
                $json_message = array(
                    'error' => '6',
                    'message' => 'Доступ запрещен.'
                );
            }
        }
        else
        {
            $json_message = array(
                'error' => '2',
                'message' => 'Не опереден обязательный параметр.'
            );
        }
        return json_encode($json_message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Функция возвращает не иерархивный список рубрик 2ГИС в формате JSON. Может возвращать либо корневые рубрики,
     * без дочерних рубрик, либо полный список рубрик вместе с дочерними, где у каждой дочерней будет дополнительный
     * параметр (parent) в котором указывается идентификатор родительской рубрики. Таким образом можно из полученных
     * данных составить любой иерархичный список, что и делается в проектах.
     *
     * @param string $apikey Пользовательский ключ доступа.
     * @param string $domain Домен пользователя с которого происходит запрос.
     * @param bool|false $full Включение/отключение вывода полного списка рубрик 2ГИС, по умолчанию выключено.
     * @return string Возвращает список рубрик в формате JSON.
     */
    public function getRubricList($apikey, $domain, $full = false)
    {
        $db = $this->db;
        $apikey = preg_replace('/[^a-z0-9]/', '', $apikey);
        if ($apikey && $domain)
        {
            if ($full)
                $query = "SELECT id, name, translit, parent FROM rubrics WHERE (SELECT id FROM users WHERE apikey = :apikey) IS NOT NULL";
            else
                $query = "SELECT id, name, translit FROM rubrics WHERE parent IS NULL AND (SELECT id FROM users WHERE apikey = :apikey) IS NOT NULL";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':apikey', $apikey, \PDO::PARAM_STR);
                $sth->execute();
                $rows = $sth->fetchAll();
                foreach ($rows as $i => $row) {
                    $rubrics[$i]['id'] = $row['id'];
                    $rubrics[$i]['name'] = $row['name'];
                    $rubrics[$i]['code'] = $row['translit'];
                    if ($full) $rubrics[$i]['parent'] = $row['parent'];
                }
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $json_message = array(
                'error' => '0',
                'rubrics' => $rubrics
            );
        }
        else
        {
            $json_message = array(
                'error' => '9',
                'message' => 'В запросе не указаны обязательные параметры.'
            );
        }
        return json_encode($json_message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Функция получает список компаний по поисковой строке ($text) в определенном городе ($city).
     *
     * @param string $apikey Пользовательсктй ключ доступа
     * @param string $text Поисковая строка
     * @param int $city Код города из списка поддерживаемых городов
     * @param string $domain Доменное имя с которого производиться запос
     * @param int $pageNum Номер страницы запрашиваемой пользователем
     * @return string Данные в формате JSON
     */
    public function getCompanyList($apikey, $text, $city, $domain, $pageNum)
    {
        $db = $this->db;
        $apikey = preg_replace('/[^a-z0-9]/', '', $apikey);
        $uClient = 'Lead4CRM';
        $uCIP = sprintf("%u", ip2long('127.0.0.1'));

        if ($apikey && $text && is_numeric($city))
        {
            $query = "SELECT name FROM cities WHERE id = :city";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':city', $city, \PDO::PARAM_INT);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $cityName = $row['name'];
            $query = "SELECT t1.id, t1.qty2 + trunc((SELECT sum(t3.debet) - sum(t3.credit) FROM log t3 WHERE t3.uid = t1.id) / t2.price) AS qty, tariff.price FROM users t1 LEFT JOIN tariff t2 ON t1.tariffid2 = t2.id WHERE apikey = :apikey";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':apikey', $apikey, \PDO::PARAM_STR);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $uid = $row['id'];
            $qty = $row['qty'];
            $price = $row['price'];
            if ($uid)
            {
                if ($qty)
                {
                    $query = 'INSERT INTO log (uid, client, ip, text, "domain") VALUES (:uid, :client, :ip, :text, :domain)';
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                        $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                        $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                        $sth->bindValue(':text', $text, \PDO::PARAM_STR);
                        $sth->bindValue(':domain', $domain, \PDO::PARAM_STR);
                        $sth->execute();
                        $count = $sth->rowCount();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    if ($count)
                        $json_message = $this->api2gisSearch($text, $cityName, $pageNum, $qty);
                }
                else
                {
                    $balance = $this->getUserBalance($uid);
                    if ($balance >= $price)
                    {
                        $query = 'INSERT INTO log (uid, client, ip, text, "domain") VALUES (:uid, :client, :ip, :text, :domain)';
                        try {
                            $sth = $db->prepare($query);
                            $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                            $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                            $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                            $sth->bindValue(':text', $text, \PDO::PARAM_STR);
                            $sth->bindValue(':domain', $domain, \PDO::PARAM_STR);
                            $sth->execute();
                            $count = $sth->rowCount();
                        } catch (\PDOException $e) {
                            $this->exception($e);
                        }
                        if ($count)
                            $json_message = $this->api2gisSearch($text, $cityName, $pageNum, $qty);
                    }
                    else
                    {
                        $json_message = array(
                            'error' => '5',
                            'message' => 'Не достаточно средств. Посетите https://www.lead4crm.ru и пополните баланс любым удобным способом.'
                        );
                    }
                }
            }
            else
            {
                $json_message = array(
                    'error' => '3',
                    'message' => 'Не найдн ни один пользователь по вашему ключу доступа.'
                );
            }
        }
        else
        {
            $json_message = array(
                'error' => '2',
                'message' => 'Отсутсвуют обязательные параметры.'
            );
        }
        return json_encode($json_message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Функция получает список компаний по конкретной рубрике ($rubric) в определенном городе ($city).
     *
     * @param string $apikey Пользовательский клю доступа
     * @param string $rubric Полное название рубрики 2ГИС
     * @param int $city Код города из списка поддерживаемых городов
     * @param string $domain Домен с которого производиться запрос
     * @param int $pageNum Номер страницы запрашиваемой пользователем
     * @return string Данные в формате JSON
     */
    public function getCompanyListByRubric($apikey, $rubric, $city, $domain, $pageNum)
    {
        $db = $this->db;
        $apikey = preg_replace('/[^a-z0-9]/', '', $apikey);
        $uClient = 'Lead4CRM';
        $uCIP = sprintf("%u", ip2long('127.0.0.1'));

        if ($apikey && $rubric && is_numeric($city))
        {
            $query = "SELECT name FROM cities WHERE id = :city";
            try {
                $sth = $db->prepare($query);
                $sth->bindvalue(':city', $city, \PDO::PARAM_INT);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $cityName = $row['name'];
            $query = "SELECT t1.id, t1.qty2 + trunc((SELECT sum(t3.debet) - sum(t3.credit) FROM log t3 WHERE t3.uid = t1.id) / t2.price) AS qty, t2.price FROM users t1 LEFT JOIN tariff t2 ON t1.tariffid2 = t2.id WHERE apikey = :apikey";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':apikey', $apikey, \PDO::PARAM_STR);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $uid = $row['id'];
            $qty = $row['qty'];
            $price = $row['price'];
            if ($uid)
            {
                if ($qty)
                {
                    $query = 'INSERT INTO log (uid, client, ip, text, "domain") VALUES (:uid, :client, :ip, :text, :domain)';
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                        $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                        $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                        $sth->bindValue(':text', $rubric, \PDO::PARAM_STR);
                        $sth->bindValue(':domain', $domain, \PDO::PARAM_STR);
                        $count = $sth->rowCount();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    if ($count)
                        $json_message = $this->api2gisSearch($rubric, $cityName, $pageNum, $qty, true);
                }
                else
                {
                    $balance = $this->getUserBalance($uid);
                    if ($balance >= $price)
                    {
                        $query = 'INSERT INTO log (uid, client, ip, text, "domain") VALUES (:uid, :client, :ip, :text, :domain)';
                        try {
                            $sth = $db->prepare($query);
                            $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                            $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                            $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                            $sth->bindValue(':text', $rubric, \PDO::PARAM_STR);
                            $sth->bindValue(':domain', $domain, \PDO::PARAM_STR);
                            $sth->execute();
                            $count = $sth->rowCount();
                        } catch (\PDOException $e) {
                            $this->exception($e);
                        }
                        if ($count)
                            $json_message = $this->api2gisSearch($rubric, $cityName, $pageNum, $qty, true);
                    }
                    else
                    {
                        $json_message = array(
                            'error' => '5',
                            'message' => 'Не достаточно средств. Посетите https://www.lead4crm.ru и пополните баланс любым удобным способом.'
                        );
                    }
                }
            }
            else
            {
                $json_message = array(
                    'error' => '3',
                    'message' => 'Не найден ни один пользователь по вашему ключу доступа.'
                );
            }
        }
        else
        {
            $json_message = array(
                'error' => '10',
                'message' => 'Не найден ключ доступа, рубрика или идентификатор города.'
            );
        }
        return json_encode($json_message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Функция возращает JSON-строку содержащую полную, развернутую информацию о компании для дальнейшего импорта
     * в справочника CRM системы (100% совместим с CRM Битрикс24, остальные системы адаптируются).
     *
     * @param string $apikey Пользовательский ключ доступа
     * @param string $domain Домен с которого происходит обращение
     * @param int $id Уникальный идентификатор компании в справочнике 2ГИС
     * @param string $hash Хэш компании в справочнике 2ГИС
     * @param int $auid Идентификатор ответственного сотрудника в Битрикс24
     * @param string $ip IPv4 адрес пользователя системой
     * @param bool|false $getFrom2GIS Опция включает принудительное получение данных из справочника 2ГИС, по умолчанию выключена.
     * @return string Данные в формате JSON
     */
    public function getCompanyProfile($apikey, $domain, $id, $hash, $auid, $ip, $getFrom2GIS = false)
    {
        $db = $this->db;
        $apikey = preg_replace('/[^a-z0-9]/', '', $apikey);
        $uClient = 'Lead4CRM';
        $uCIP = sprintf("%u", ip2long($ip));

        if ($apikey && $hash && is_numeric($id))
        {
            $query = "SELECT users.id, users.qty2, tariff.price FROM users LEFT JOIN tariff ON users.tariffid2 = tariff.id WHERE apikey = :apikey";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':apikey', $apikey, \PDO::PARAM_STR);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $uid = $row['id'];
            $qty = $row['qty2'];
            $price = $row['price'];
            if ($uid)
            {
                if ($qty)
                {
                    $query = "UPDATE users SET qty2 = qty2 - 1 WHERE id = :uid; SELECT json FROM cnam_cp WHERE id = :id;";
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                        $sth->bindValue(':id', $id, \PDO::PARAM_INT);
                        $sth->execute();
                        $row = $sth->fetch();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    $cp_json = $row['json'];
                    if (!$cp_json || $getFrom2GIS)
                        $dublgis = $this->api2gisProfile($id, $hash);
                    else
                        $dublgis = json_decode($cp_json);
                    $lon = $dublgis->lon;
                    $lat = $dublgis->lat;
                    $query = "SELECT json FROM geodata WHERE lon = :lon AND lat = :lat";
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':lon', $lon);
                        $sth->bindValue(':lat', $lat);
                        $sth->execute();
                        $row = $sth->fetch();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    $gd_json = $row['json'];
                    if (!$gd_json)
                        $geoData = $this->api2gisGeo($lon, $lat);
                    else
                        $geoData = json_decode($gd_json);
                    $query = 'INSERT INTO log (uid, client, ip, text, "domain") VALUES (:uid, :client, :ip, :text, :domain) RETURNING id';
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                        $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                        $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                        $sth->bindValue(':text', $dublgis->name, \PDO::PARAM_STR);
                        $sth->bindValue(':domain', $domain, \PDO::PARAM_STR);
                        $sth->execute();
                        $row = $sth->fetch();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    $logId = $row['id'];
                    $query = "INSERT INTO cnam_cache (logid, cp_id, cp_hash, lon, lat) VALUES (:logid, :id, :hash, :lon, :lat)";
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':logid', $logId, \PDO::PARAM_INT);
                        $sth->bindValue(':id', $id, \PDO::PARAM_INT);
                        $sth->bindValue(':hash', $hash, \PDO::PARAM_STR);
                        $sth->bindValue(':lon', $lon, \PDO::PARAM_STR);
                        $sth->bindValue(':lat', $lat, \PDO::PARAM_STR);
                        $sth->execute();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    $json_message = $this->getCompanyProfileArray($auid, $dublgis, $geoData);
                }
                else
                {
                    $balance = $this->getUserBalance($uid);
                    if ($balance >= $price)
                    {
                        $query = "SELECT json FROM cnam_cp WHERE id = :id";
                        try {
                            $sth = $db->prepare($query);
                            $sth->bindValue(':id', $id, \PDO::PARAM_INT);
                            $sth->execute();
                            $row = $sth->fetch();
                        } catch (\PDOException $e) {
                            $this->exception($e);
                        }
                        $cp_json = $row['json'];
                        if (!$cp_json || $getFrom2GIS)
                            $dublgis = $this->api2gisProfile($id, $hash);
                        else
                            $dublgis = json_decode($cp_json);
                        $lon = $dublgis->lon;
                        $lat = $dublgis->lat;
                        $query = "SELECT json FROM geodata WHERE lon = :lon AND lat = :lat";
                        try {
                            $sth = $db->prepare($query);
                            $sth->bindValue(':lon', $lon);
                            $sth->bindValue(':lat', $lat);
                            $sth->execute();
                            $row = $sth->fetch();
                        } catch (\PDOException $e) {
                            $this->exception($e);
                        }
                        $gd_json = $row['json'];
                        if (!$gd_json)
                            $geoData = $this->api2gisGeo($lon, $lat);
                        else
                            $geoData = json_decode($gd_json);
                        $query = 'INSERT INTO log (uid, credit, client, ip, text, "domain") VALUES (:uid, :price, :client, :ip, :text, :domain) RETURNING id';
                        try {
                            $sth = $db->prepare($query);
                            $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                            $sth->bindValue(':price', $price, \PDO::PARAM_STR);
                            $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                            $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                            $sth->bindValue(':text', $dublgis->name, \PDO::PARAM_STR);
                            $sth->bindValue(':domain', $domain, \PDO::PARAM_STR);
                            $sth->execute();
                            $row = $sth->fetch();
                        } catch (\PDOException $e) {
                            $this->exception($e);
                        }
                        $logId = $row['id'];
                        $query = "INSERT INTO cnam_cache (logid, cp_id, cp_hash, lon, lat) VALUES (:logid, :id, :hash, :lon, :lat)";
                        try {
                            $sth = $db->prepare($query);
                            $sth->bindValue(':logid', $logId, \PDO::PARAM_INT);
                            $sth->bindValue(':id', $id, \PDO::PARAM_INT);
                            $sth->bindValue(':hash', $hash, \PDO::PARAM_STR);
                            $sth->bindValue(':lon', $lon, \PDO::PARAM_STR);
                            $sth->bindValue(':lat', $lat, \PDO::PARAM_STR);
                            $sth->execute();
                        } catch (\PDOException $e) {
                            $this->exception($e);
                        }
                        $json_message = $this->getCompanyProfileArray($auid, $dublgis, $geoData, $price);
                    }
                    else
                    {
                        $json_message = array(
                            'error' => '5',
                            'message' => 'Не достаточно средств. Посетите https://www.lead4crm.ru и пополните баланс любым удобным способом.'
                        );
                    }
                }
            }
            else
            {
                $json_message = array(
                    'error' => '3',
                    'message' => 'Не найден ни один пользователь по вашему ключу доступа.'
                );
            }
        }
        else
        {
            $json_message = array(
                'error' => '2',
                'message' => 'Не найден ключ доступа или отсутствует хэш или отсутсвует идентификатор компании.'
            );
        }
        return json_encode($json_message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Функция возвращает сообщение-заглушку в случае пустого запроса к данному API.
     *
     * @return string Данные в формате JSON
     */
    public function defaultResult()
    {
        $json_message = array(
            'error' => '1',
            'message' => 'Не правильный запрос к API интерфейсу.'
        );
        return json_encode($json_message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Функция получения данных либо ил локальной базы данных, либо из справочника 2ГИС.
     * Это вспомогательная функция, для получения и передачи данных пользоватлею используются другие функции,
     * например $this->getName().
     *
     * @param int $number Номер телефона без в чистом числовом формате.
     * @param int $uid Идентификатор пользователя.
     * @param string $uClient Название клиента.
     * @param int $uCIP Конвертированный IP-адрес.
     * @param int $price Стоимость запроса, по умолчанию равна нулю.
     * @return array Возвращает подготовленный массив данных.
     */
    private function getData($number, $uid, $uClient, $uCIP, $price = 0)
    {
        $db = $this->db;
        $query = "SELECT name, translit FROM phonebook WHERE phone = :number AND verify = TRUE";
        try {
            $sth = $db->prepare($query);
            $sth->bindValue(':number', $number, \PDO::PARAM_INT);
            $row = $sth->fetch();
        } catch (\PDOException $e) {
            $this->exception($e);
        }
        $name = $row['name'];
        $translit = $row['translit'];
        if ($name && $translit)
        {
            if ($price)
            {
                $query = "INSERT INTO log (uid, phone, credit, client, ip) VALUES (:uid, :number, :price, :client, :ip)";
                try {
                    $sth = $db->prepare($query);
                    $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                    $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                    $sth->bindValue(':price', $price);
                    $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                    $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                    $sth->execute();
                } catch (\PDOException $e) {
                    $this->exception($e);
                }
            }
            else
            {
                $query = "UPDATE users SET qty = qty - 1 WHERE id = :uid; INSERT INTO log (uid, phone, credit, ip) VALUES (:uid, :number, :client, :ip)";
                try {
                    $sth = $db->prepare($query);
                    $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                    $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                    $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                    $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                    $sth->execute();
                } catch (\PDOException $e) {
                    $this->exception($e);
                }
                $json_message = array(
                    'error' => '0',
                    'name' => $name,
                    'translit' => $translit
                );
            }
        }
        else
        {
            $query = "SELECT name, translit FROM phone_cache WHERE number = :number AND queries >= 3 AND modtime + INTERVAL '1 week' > now()";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $name = $row['name'];
            $translit = $row['translit'];
            if ($name && $translit)
            {
                if ($price)
                {
                    $query = "UPDATE users SET qty = qty - 1 WHERE id = :uid";
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                        $sth->execute();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                }
                else
                {
                    $query = "UPDATE users SET qty = qty - 1 WHERE id = :uid; INSERT INTO log (uid, phone, client, ip) VALUES (:uid, :number, :client, :ip)";
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                        $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                        $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                        $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                        $sth->execute();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                }
                $json_message = array(
                    'error' => '0',
                    'name' => $name,
                    'translit' => $translit
                );
            }
            else
            {
                $phones_masks = json_decode(file_get_contents(__DIR__.'/phones-ru.json'), true);
                array_multisort($phones_masks, SORT_DESC);
                $city = '';
                foreach ($phones_masks as $masks)
                {
                    $mask = preg_replace('/[^0-9]/', '', $masks['mask']);
                    if ($mask == substr($number, 0, strlen($mask)))
                    {
                        if ($masks['city'])
                        {
                            if (count($masks['city']) == 1)
                            {
                                $city = $masks['city'];
                                break;
                            }
                            else
                            {
                                $city = $masks['city'][0];
                                break;
                            }
                        }
                        else
                        {
                            $city = $masks['region'];
                        }
                    }
                }
                $query = "SELECT id FROM cities WHERE name = :city";
                try {
                    $sth = $db->prepare($query);
                    $sth->bindValue(':city', $city, \PDO::PARAM_STR);
                    $sth->execute();
                    $row = $sth->fetch();
                } catch (\PDOException $e) {
                    $this->exception($e);
                }
                if ($row['id']) {
                    $query = "SELECT number FROM phones_notexists WHERE number = :number AND addtime + INTERVAL '1 month' > now()";
                    try {
                        $sth = $db->prepare($query);
                        $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                        $sth->execute();
                        $row = $sth->fetch();
                    } catch (\PDOException $e) {
                        $this->exception($e);
                    }
                    if ($row['number']) {
                        $json_message = array(
                            'error' => '8',
                            'message' => 'Этот номер телефона находиться в списке заблокированных номеров потому, что данных по этому номеру в справочнике не найдены. Если вы являетесь владельцем данного номера, вы можете добавить его на сайте https://www.cnamrf.ru/.'
                        );
                    }
                    else
                    {
                        $url = 'http://catalog.api.2gis.ru/search?';
                        $uri = http_build_query(
                            array(
                                'key' => $this->conf['2gis']['key'],
                                'version' => '1.3',
                                'what' => $number,
                                'where' => $city
                            )
                        );
                        $dublgis = json_decode(file_get_contents($url.$uri));
                        $name = $dublgis->result[0]->name;
                        $translit = $this->rus2translit($name);
                        if ($name)
                        {
                            if ($price)
                            {
                                $query = "INSERT INTO log (uid, phone, credit, client, ip) VALUES (:uid, :number, :price, :client, :ip)";
                                try {
                                    $sth = $db->prepare($query);
                                    $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                                    $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                                    $sth->bindValue(':price', $price, \PDO::PARAM_STR);
                                    $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                                    $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                                    $sth->execute();
                                } catch (\PDOException $e) {
                                    $this->exception($e);
                                }
                            }
                            else
                            {
                                $query  = "UPDATE users SET qty = qty - 1 WHERE id = :uid;";
                                $query .= "INSERT INTO log (uid, phone, client, ip) VALUES (:uid, :number, :client, :ip)";
                                try {
                                    $sth = $db->prepare($query);
                                    $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
                                    $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                                    $sth->bindValue(':client', $uClient, \PDO::PARAM_STR);
                                    $sth->bindValue(':ip', $uCIP, \PDO::PARAM_INT);
                                    $sth->execute();
                                } catch (\PDOException $e) {
                                    $this->exception($e);
                                }
                            }
                            $query  = "UPDATE phone_cache SET modtime = now(), queries = queries +  1 WHERE number = :number;";
                            $query .= "INSERT INTO phone_cache (number, name, translit) SELECT :number, :name, :translit WHERE NOT EXISTS (SELECT 1 FROM phone_cache WHERE number = :number)";
                            try {
                                $sth = $db->prepare($query);
                                $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                                $sth->bindValue(':name', $name, \PDO::PARAM_STR);
                                $sth->bindValue(':translit', $translit, \PDO::PARAM_STR);
                                $sth->execute();
                            } catch (\PDOException $e) {
                                $this->exception($e);
                            }
                            $json_message = array(
                                'error' => '0',
                                'name' => $name,
                                'translit' => $translit
                            );
                        }
                        else
                        {
                            $query  = "UPDATE phones_notexists SET addtime = now() WHERE number = :number;";
                            $query .= "INSERT INTO phones_notexists (number) SELECT :number WHERE NOT EXISTS (SELECT 1 FROM phones_notexists WHERE number = :number)";
                            try {
                                $sth = $db->prepare($query);
                                $sth->bindValue(':number', $number, \PDO::PARAM_INT);
                                $sth->execute();
                            } catch (\PDOException $e) {
                                $this->exception($e);
                            }
                            $json_message = array(
                                'error' => '4',
                                'message' => 'Запрошенный номер телефона в справочнике не найден и добавлен в список заблокированных на 1 месяц.'
                            );
                        }
                    }
                }
                else
                {
                    $json_message = array(
                        'error' => '7',
                        'message' => 'Данный город в настоящий момент не поддерживается. Если вы являетесь владельцем данного номера, вы можете его добавить на сайте https://www.cnamrf.ru/. Если вы уверены в том, что ваш город есть в справочнике 2ГИС, напишите об этом службе поддержки: support@cnamrf.ru',
                        'city' => $city
                    );
                }
            }
        }
        return $json_message;
    }

    /**
     * @param int $auid Уникальный идентификатор ответственного сотрудника Бирикс24
     * @param object $cp Объект содержищий ответ API сервера 2ГИС с карточной компании
     * @param object $gd Объект содержащий ответ API сервера 2ГИС с геоданными
     * @param null $summ
     * @return array Массив данных карточки компании (100% совместимый с Битрикс24)
     */
    private function getCompanyProfileArray($auid, $cp, $gd, $summ = null)
    {
        $json_message = array(
            'error' => '0',
            'summ' => $summ,
            'auid' => $auid,
            'id' => $cp->id,
            'log' => $cp->lon,
            'lat' => $cp->lat,
            'name' => $cp->name,
            'address' => $cp->address,
            'address_2' => $cp->additional_info->office,
            'city_name' => $cp->city_name,
            'region' => $gd->result[0]->attributes->district,
            'postal_code' => $gd->result[0]->attributes->index,
            'currency' => $cp->additional_info->currency,
            'industry' => $this->getGeneralIndustry($cp->rubrics)
        );
        for ($i = 0; $i < count($cp->contacts); $i++)
        {
            foreach ($cp->contacts[$i]->contacts as $contact)
            {
                if ($contact->type == 'phone')
                {
                    $phone = $contact->value;
                    $json_message['phone'][] = array(
                        'VALUE' => $phone,
                        'VALUE_TYPE' => 'WORK'
                    );
                }
                elseif ($contact->type == 'fax')
                {
                    $phone = $contact->value;
                    $json_message['phone'][] = array(
                        'VALUE' => $phone,
                        'VALUE_TYPE' => 'FAX'
                    );
                }
                elseif ($contact->type == 'email')
                {
                    $json_message['email'][] = array(
                        'VALUE' => $contact->value,
                        'VALUE_TYPE' => 'WORK'
                    );
                }
                elseif ($contact->type == 'website')
                {
                    $json_message['web'][] = array(
                        'VALUE' => 'http://'.$contact->alias,
                        'VALUE_TYPE' => 'WORK'
                    );
                }
                elseif ($contact->type == 'facebook')
                {
                    $json_message['web'][] = array(
                        'VALUE' => $contact->value,
                        'VALUE_TYPE' => 'FACEBOOK'
                    );
                }
                elseif ($contact->type == 'twitter')
                {
                    $json_message['web'][] = array(
                        'VALUE' => $contact->value,
                        'VALUE_TYPE' => 'TWITTER'
                    );
                }
                elseif ($contact->type == 'vkontakte')
                {
                    $json_message['web'][] = array(
                        'VALUE' => $contact->value,
                        'VALUE_TYPE' => 'OTHER'
                    );
                }
                elseif ($contact->type == 'skype')
                {
                    $json_message['im'][] = array(
                        'VALUE' => $contact->value,
                        'VALUE_TYPE' => 'SKYPE'
                    );
                }
                elseif ($contact->type == 'icq')
                {
                    $json_message['im'][] = array(
                        'VALUE' => $contact->value,
                        'VALUE_TYPE' => 'ICQ'
                    );
                }
                elseif ($contact->type == 'jabber')
                {
                    $json_message['im'][] = array(
                        'VALUE' => $contact->value,
                        'VALUE_TYPE' => 'JABBER'
                    );
                }
            }
        }
        if (count($cp->rubrics))
        {
            $json_message['comments'] = "<p><b>Виды деятельности:</b></p><ul>";
            foreach ($cp->rubrics as $rubric)
                $json_message['comments'] .= '<li>'.$rubric.'</li>';
            $json_message['comments'] .= '</ul>';
        }
        $url_name = rawurlencode($cp->name);
        $addittional_info = "<p><b>Дополнительная информация:</b></p><ul>"
            . "<li><a href='http://2gis.ru/city/{$cp->project_id}/center/{$cp->lon}%2C{$cp->lat}/zoom/17/routeTab/to/{$cp->lon}%2C{$cp->lat}%E2%95%8E{$url_name}?utm_source=profile&utm_medium=route_to&utm_campaing=partnerapi' target='_blank'>Проложить маршрут до {$cp->name}</a></li>"
            . "<li><a href='http://2gis.ru/city/{$cp->project_id}/center/{$cp->lon}%2C{$cp->lat}/zoom/17/routeTab/from/{$cp->lon}%2C{$cp->lat}%E2%95%8E{$url_name}?utm_source=profile&utm_medium=route_from&utm_campaing=partnerapi' target='_blank'>Проложить маршрут от {$cp->name}</a></li>"
            . "<li><a href='http://2gis.ru/city/{$cp->project_id}/firm/{$cp->id}/entrance/center/{$cp->lon}%2C{$cp->lat}/zoom/17?utm_source=profile&utm_medium=entrance&utm_campaing=partnerapi' target='_blank'>Показать вход</a></li>"
            . "<li><a href='http://2gis.ru/city/{$cp->project_id}/firm/{$cp->id}/photos/{$cp->id}/center/{$cp->lon}%2C{$cp->lat}/zoom/17?utm_source=profile&utm_medium=photo&utm_campaing=partnerapi' target='_blank'>Фотографии {$cp->name}</a></li>"
            . "<li><a href='http://2gis.ru/city/{$cp->project_id}/firm/{$cp->id}/flamp/{$cp->id}/callout/firms-{$cp->id}/center/{$cp->lon}%2C{$cp->lat}/zoom/17?utm_source=profile&utm_medium=review&utm_campaing=partnerapi' target='_blank'>Отзывы о {$cp->name}</a>";
        $addittional_info_service_price = "<li><a href='{cp->bookle_url}?utm_source=profile&utm_medium=booklet&utm_campaing=partnerapi' target='_blank'>Услуги и цены {$cp->name}</a></li>";
        if ($json_message['comments'])
            $json_message['comments'] .= $addittional_info;
        else
            $json_message['comments'] = $addittional_info;
        if ($cp->bookle_url)
            $json_message['comments'] .= $addittional_info_service_price;
        return $json_message;
    }

    /**
     * Функция вычисляет основной вид деятельности компании по наиболее подходящей, или же отправляет
     * стандартное значение. Основной вид деятельности определяется по нескольким критериям:
     * 1. По наибольшему кол-ву вхождений в отдельные родительские рубрики.
     * 2. По выставленному приоритету в самом справочнике 2ГИС.
     *
     * @param array $rubrics Массив рубрик
     * @return array Массив содержащий название основной рубрики и её код (обычно транслитерация названия рубрики).
     */
    private function getGeneralIndustry($rubrics)
    {
        $db = $this->db;
        if (count($rubrics))
        {
            $parrents = array();
            $query = "SELECT parent FROM rubrics WHERE name = :name";
            foreach ($rubrics as $rubric)
            {
                try {
                    $sth = $db->prepare($query);
                    $sth->bindValue(':name', $rubric, \PDO::PARAM_STR);
                    $sth->execute();
                    $row = $sth->fetch();
                } catch (\PDOException $e) {
                    $this->exception($e);
                }
                $parent_id1 = $row['parent'];
                $query = "SELECT parent FROM rubrics WHERE id = :id";
                try {
                    $sth = $db->prepare($query);
                    $sth->bindValue(':id', $parent_id1, \PDO::PARAM_INT);
                    $sth->execute();
                    $row = $sth->fetch();
                } catch (\PDOException $e) {
                    $this->exception($e);
                }
                $parent_id2 = $row['parent'];
                if ($parent_id2)
                    $parrents[] = $parent_id2;
                else
                    $parrents[] = $parent_id1;
            }
            $main_parent = $main_parent2 = array_count_values($parrents);
            arsort($main_parent2);
            foreach ($main_parent2 as $parent_id => $count) {
                if ($count > 1)
                    $pid = $parent_id;
                else
                    $pid = key($main_parent);
                break;
            }
            $query = "SELECT name, translit FROM rubrics WHERE id = :id";
            try {
                $sth = $db->prepare($query);
                $sth->bindValue(':id', $pid, \PDO::PARAM_INT);
                $sth->execute();
                $row = $sth->fetch();
            } catch (\PDOException $e) {
                $this->exception($e);
            }
            $name = $row['name'];
            $code = $row['translit'];
        }
        else
        {
            $name = 'Другое';
            $code = 'OTHER';
        }
        return array('code' => $code, 'name' => $name);
    }

    /**
     * Функция осуществляет обращение к API 2GIS с поисковым запросом ($what) для конкретного города ($where) и
     * возвращает готовый рультат в массиве для дальнейшей обработки и выдачи пользователю.
     *
     * @param string $what Строка поиска
     * @param string $where Название города
     * @param int $page Текущая страница
     * @param int $qty Оставшееся кол-во запросов у пользователя
     * @param bool|false $byRubrics Указывает на тип поиска, по умолчанию поиск производиться полнотекстовый, но если параметр указан TRUE, по поиск будет проходить по рубрикам 2ГИС и в этом случае необходимо указывать точное название рубрики, иначе результат будет нулевым.
     * @return array Возвращает подготовленный массив данных
     */
    private function api2gisSearch($what, $where, $page, $qty, $byRubrics = false)
    {

        $url = 'http://catalog.api.2gis.ru/' . ($byRubrics ? 'searchinrubric?' : 'search?');
        $uri = http_build_query(
            array(
                'key' => $this->conf['2gis']['key'],
                'version' => '1.3',
                'what' => $what,
                'where' => $where,
                'page' => $page,
                'pagesize' => 50
            )
        );
        $dublgis = json_decode(file_get_contents($url.$uri));
        $result = array();
        foreach ($dublgis->result as $key => $value)
        {
            $result[$key]['id'] = $value->id;
            $result[$key]['name'] = $value->name;
            $result[$key]['hash'] = $value->hash;
            $result[$key]['firm_group'] = $value->firm_group->count;
            $result[$key]['address'] = $value->address;
        }
        $json_message = array(
            'error' => '0',
            'total' => $dublgis->total,
            'pagesize' => 50,
            'page' => $page,
            'qty' => $qty,
            'result' => $result
        );
        return $json_message;
    }

    /**
     * Приватная функция для получения профиля компании и сохранения данного профиля в БД CNAM РФ для
     * дальнейших обращений в случае выгрузок архивных выборок. Данные для каждой компании сохраняются полностью
     * для возможного расширения функционала по работе с полученными данными API 2GIS.
     *
     * @param int $id Уникальный идентификатор профиля компании в справочнике 2ГИС
     * @param string $hash Хэш для определенного профиля компании в справочнике 2ГИС
     * @return mixed Возвращает декодированный JSON профиля компании из справочника 2ГИС
     */
    private function api2gisProfile($id, $hash)
    {
        $db = $this->db;
        $url = 'http://catalog.api.2gis.ru/profile?';
        $uri = http_build_query(
            array(
                'key' => $this->conf['2gis']['key'],
                'version' => '1.3',
                'id' => $id,
                'hash' => $hash
            )
        );
        $json = file_get_contents($url.$uri);
        $query = "INSERT INTO cnam_cp (id, hash, json) VALUES (:id, :hash, :json)";
        try {
            $sth = $db->prepare($query);
            $sth->bindValue(':id', $id, \PDO::PARAM_INT);
            $sth->bindValue(':hash', $hash, \PDO::PARAM_STR);
            $sth->bindValue(':json', $json, \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->exception($e);
        }
        return json_decode($json);
    }

    /**
     * Функция получает геоданные из справочника 2ГИС по указанным координатам и возвращает декодированный JSON.
     *
     * @param double $lon Долгота
     * @param double $lat Широта
     * @return mixed Возвращает декодированный JSON геоданных из справочника 2ГИС
     */
    private function api2gisGeo($lon, $lat)
    {
        $db = $this->db;
        $url = 'http://catalog.api.2gis.ru/geo/search?';
        $uri = http_build_query(
            array(
                'key' => $this->conf['2gis']['key'],
                'version' => '1.3',
                'q' => $lon . ',' . $lat
            )
        );
        $json = file_get_contents($url.$uri);
        $query = "INSERT INTO geodata (lon, lat, json) VALUES (:lon, :lat, :json)";
        try {
            $sth = $db->prepare($query);
            $sth->bindValue(':lon', $lon, \PDO::PARAM_STR);
            $sth->bindValue(':lat', $lat, \PDO::PARAM_STR);
            $sth->bindValue(':json', $json, \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->exception($e);
        }
        return json_decode($json);
    }

    /**
     * Функция возвращает остаточную сумму на балансе пользователя по уникальному идентификатору ($uid) пользователя.
     *
     * @param int $uid Уникальный идентификатор пользователя в системе.
     * @return int Остаточная сумма на счету пользователя.
     */
    private function getUserBalance($uid)
    {
        $db = $this->db;
        $query = "SELECT (sum(debet) - sum(credit)) AS balance FROM log WHERE uid = :uid";
        try {
            $sth = $db->prepare($query);
            $sth->bindValue(':uid', $uid, \PDO::PARAM_INT);
            $sth->execute();
            $row = $sth->fetch();
        } catch (\PDOException $e) {
            $this->exception($e);
        }
        return $row['balance'];
    }

    /**
     * Функция конвертации массива в строку формата CSV.
     *
     * @param array $fields Входной массив, который необходимо конвертировать.
     * @param string $delimiter Разделитель полей.
     * @param string $enclosure Символ экранирования.
     * @param bool|false $encloseAll Включение/отключение опции экранирования полей. По умолчанию выключено.
     * @param bool|false $nullToMySQLNull Включение/отключение вывода NULL для пустых значений. По умолчанию выключено.
     * @return string Результат конвертации массива в CSV строку.
     */
    private function array2csv(array &$fields, $delimiter = ';', $enclosure = '"', $encloseAll = false, $nullToMySQLNull = false)
    {
        $delimiter_esc = preg_quote($delimiter, '/');
        $enclosure_esc = preg_quote($enclosure, '/');

        $output = array();
        foreach ($fields as $field)
        {
            if ($field === null && $nullToMySQLNull)
            {
                $output[] = 'NULL';
                continue;
            }

            if ($encloseAll || preg_match("/(?:${delimiter_esc}|${enclosure_esc}|\s/", $field))
            {
                $output[] = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure;
            }
            else
            {
                $output[] = $field;
            }
        }
        return implode($delimiter, $output);
    }

    /**
     * Функция конвертирует кирилицу в транслит. Необходимо для поддержки возможности правильного отображения
     * имени компании на дисплеях телефонов, которые не могут поддерживать UTF-8.
     *
     * @param string $text Входная строка на русском, которая будет пеобразована
     * @return string Результат транслитерации строки
     */
    private function rus2translit($text)
    {
        $converter = array(
            'а' => 'a',     'б' => 'b',     'в' => 'v',
            'г' => 'g',     'д' => 'd',     'е' => 'e',
            'ё' => 'e',     'ж' => 'zh',    'з' => 'z',
            'и' => 'i',     'й' => 'y',     'к' => 'k',
            'л' => 'l',     'м' => 'm',     'н' => 'n',
            'о' => 'o',     'п' => 'p',     'р' => 'r',
            'с' => 'c',     'т' => 't',     'у' => 'u',
            'ф' => 'f',     'х' => 'h',     'ц' => 'c',
            'ч' => 'ch',    'ш' => 'sh',    'щ' => 'sch',
            'ь' => '',      'ы' => 'y',     'ъ' => '',
            'э' => 'e',     'ю' => 'yu',    'я' => 'ya',

            'А' => 'A',     'Б' => 'B',     'В' => 'V',
            'Г' => 'G',     'Д' => 'D',     'Е' => 'E',
            'Ё' => 'E',     'Ж' => 'Zh',    'З' => 'Z',
            'И' => 'I',     'Й' => 'Y',     'К' => 'K',
            'Л' => 'L',     'М' => 'M',     'Н' => 'N',
            'О' => 'O',     'П' => 'P',     'Р' => 'R',
            'С' => 'C',     'Т' => 'T',     'У' => 'U',
            'Ф' => 'F',     'Х' => 'H',     'Ц' => 'C',
            'Ч' => 'Ch',    'Ш' => 'Sh',    'Щ' => 'Sch',
            'Ь' => '',      'Ы' => 'Y',     'Ъ' => '',
            'Э' => 'E',     'Ю' => 'Yu',    'Я' => 'Ya',
        );
        return strstr($text, $converter);
    }

    /**
     * Функция прерывает выполнение скрипта и выводит соотщение об ошибке с указанием номера ошибки и
     * сообщением самой ошибки. В данному случает служит для отловли ошибок при работе с PDO.
     *
     * @param object $e Объект класса исключения
     */
    private function exception ($e)
    {
        $json_message = json_encode(
            array(
                'error' => $e->getCode(),
                'message' => $e->getMessage()
            )
        );
        die($json_message);
    }

}