<?php
define("FANWE", 0);
if (PHP_VERSION >= '5.0.0') {
	$begin_run_time = @microtime(true);
} else {
	$begin_run_time = @microtime();
}
@set_magic_quotes_runtime(0);
define('MAGIC_QUOTES_GPC', get_magic_quotes_gpc() ? True : False);
if (!defined('IS_CGI'))
	define('IS_CGI', substr(PHP_SAPI, 0, 3) == 'cgi' ? 1 : 0);
if (!defined('_PHP_FILE_')) {
	if (IS_CGI) {
		$_temp = explode('.php', $_SERVER["PHP_SELF"]);
		define('_PHP_FILE_', rtrim(str_replace($_SERVER["HTTP_HOST"], '', $_temp[0] . '.php'), '/'));
	} else {
		define('_PHP_FILE_', rtrim($_SERVER["SCRIPT_NAME"], '/'));
	}
}
if (function_exists('date_default_timezone_set')) {
	if (app_conf('DEFAULT_TIMEZONE')) {
		date_default_timezone_set(app_conf('DEFAULT_TIMEZONE'));
	} else {
		date_default_timezone_set('PRC');
	}
}
require APP_ROOT_PATH . 'system/common.php';
require APP_ROOT_PATH . 'system/lailai/define.php';
if (file_exists(APP_ROOT_PATH . "public/install.lock")) {
	update_sys_config();
}
$sys_config = require APP_ROOT_PATH . 'system/config.php';
$distribution_cfg = array(
	"CACHE_CLIENT" => "",
	"CACHE_PORT" => "",
	"CACHE_USERNAME" => "",
	"CACHE_PASSWORD" => "",
	"CACHE_DB" => "",
	"CACHE_TABLE" => "",
	"SESSION_CLIENT" => "",
	"SESSION_PORT" => "",
	"SESSION_USERNAME" => "",
	"SESSION_PASSWORD" => "",
	"SESSION_DB" => "",
	"SESSION_TABLE" => "",
	"SESSION_FILE_PATH" => "public/session",
	"DB_CACHE_APP" => array(
		"index"
	),
	"DB_CACHE_TABLES" => array(
		"adv",
		"api_login",
		"article",
		"article_cate",
		"bank",
		"conf",
		"deal",
		"deal_cate",
		"faq",
		"help",
		"index_image",
		"link",
		"link_group",
		"nav",
	),
	"DB_DISTRIBUTION" => array(),
	"OSS_DOMAIN" => "",
	"OSS_FILE_DOMAIN" => "",
	"OSS_BUCKET_NAME" => "",
	"OSS_ACCESS_ID" => "",
	"OSS_ACCESS_KEY" => "",
);
$distribution_cfg["CACHE_TYPE"] = "File";
$distribution_cfg["CACHE_LOG"] = false;
$distribution_cfg["SESSION_TYPE"] = "File";
$distribution_cfg['ALLOW_DB_DISTRIBUTE'] = false;
$distribution_cfg["CSS_JS_OSS"] = false;
$distribution_cfg["OSS_TYPE"] = "";
$distribution_cfg["ORDER_DISTRIBUTE_COUNT"] = "5";
$distribution_cfg['DOMAIN_ROOT'] = '';
$distribution_cfg['COOKIE_PATH'] = '/';
function app_conf($name)
{
	if (isset($GLOBALS['sys_config'][$name])) {
		return stripslashes($GLOBALS['sys_config'][$name]);
	} else {
		return false;
	}
}

if (!isset($_SERVER['REQUEST_URI'])) {
	if (isset($_SERVER['argv'])) {
		$uri = $_SERVER['PHP_SELF'] . '?' . $_SERVER['argv'][0];
	} else {
		$uri = $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING'];
	}
	$_SERVER['REQUEST_URI'] = $uri;
}
filter_request($_GET);
filter_request($_POST);
if (IS_DEBUG)
	error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
else
	error_reporting(0);
if (!class_exists("FanweSessionHandler")) {
	class FanweSessionHandler
	{
		private $savePath;
		private $mem;
		private $db;
		private $table;

		function open($savePath, $sessionName)
		{
			$this->savePath = APP_ROOT_PATH . $GLOBALS['distribution_cfg']['SESSION_FILE_PATH'];
			if ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "MemcacheSASL") {
				$this->mem = require_once APP_ROOT_PATH . "system/cache/MemcacheSASL/MemcacheSASL.php";
				$this->mem = new MemcacheSASL;
				$this->mem->addServer($GLOBALS['distribution_cfg']['SESSION_CLIENT'], $GLOBALS['distribution_cfg']['SESSION_PORT']);
				$this->mem->setSaslAuthData($GLOBALS['distribution_cfg']['SESSION_USERNAME'], $GLOBALS['distribution_cfg']['SESSION_PASSWORD']);
			} elseif ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "Db") {
				$pconnect = false;
				$session_client = $GLOBALS['distribution_cfg']['SESSION_CLIENT'] == "" ? app_conf('DB_HOST') : $GLOBALS['distribution_cfg']['SESSION_CLIENT'];
				$session_port = $GLOBALS['distribution_cfg']['SESSION_PORT'] == "" ? app_conf('DB_PORT') : $GLOBALS['distribution_cfg']['SESSION_PORT'];
				$session_username = $GLOBALS['distribution_cfg']['SESSION_USERNAME'] == "" ? app_conf('DB_USER') : $GLOBALS['distribution_cfg']['SESSION_USERNAME'];
				$session_password = $GLOBALS['distribution_cfg']['SESSION_PASSWORD'] == "" ? app_conf('DB_PWD') : $GLOBALS['distribution_cfg']['SESSION_PASSWORD'];
				$session_db = $GLOBALS['distribution_cfg']['SESSION_DB'] == "" ? app_conf('DB_NAME') : $GLOBALS['distribution_cfg']['SESSION_DB'];
				$this->db = new mysql_db($session_client . ":" . $session_port, $session_username, $session_password, $session_db, 'utf8', $pconnect);
				$this->table = $GLOBALS['distribution_cfg']['SESSION_TABLE'] == "" ? DB_PREFIX . "session" : $GLOBALS['distribution_cfg']['SESSION_TABLE'];
			} else {
				if (!is_dir($this->savePath)) {
					@mkdir($this->savePath, 0777);
				}
			}
			return true;
		}

		function close()
		{
			return true;
		}

		function read($id)
		{
			$sess_id = "sess_" . $id;
			if ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "MemcacheSASL") {
				return $this->mem->get("$this->savePath/$sess_id");
			} elseif ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "Db") {
				$session_data = $this->db->getRow("select session_data,session_time from " . $this->table . " where session_id = '" . $sess_id . "'", true);
				if ($session_data['session_time'] < NOW_TIME) {
					return false;
				} else {
					return $session_data['session_data'];
				}
			} else {
				$file = "$this->savePath/$sess_id";
				if (filemtime($file) + SESSION_TIME < time() && file_exists($file)) {
					@unlink($file);
				}
				$data = (string)@file_get_contents($file);
				return $data;
			}
		}

		function write($id, $data)
		{
			$sess_id = "sess_" . $id;
			if ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "MemcacheSASL") {
				return $this->mem->set("$this->savePath/$sess_id", $data, SESSION_TIME);
			} elseif ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "Db") {
				$session_data = $this->db->getRow("select session_data,session_time from " . $this->table . " where session_id = '" . $sess_id . "'", true);
				if ($session_data) {
					$session_data['session_data'] = $data;
					$session_data['session_time'] = NOW_TIME + SESSION_TIME;
					$this->db->autoExecute($this->table, $session_data, "UPDATE", "session_id = '" . $sess_id . "'");
				} else {
					$session_data['session_id'] = $sess_id;
					$session_data['session_data'] = $data;
					$session_data['session_time'] = NOW_TIME + SESSION_TIME;
					$this->db->autoExecute($this->table, $session_data);
				}
				return true;
			} else {
				return file_put_contents("$this->savePath/$sess_id", $data) === false ? false : true;
			}
		}

		function destroy($id)
		{
			$sess_id = "sess_" . $id;
			if ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "MemcacheSASL") {
				$this->mem->delete($sess_id);
			} elseif ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "Db") {
				$this->db->query("delete from " . $this->table . " where session_id = '" . $sess_id . "'");
			} else {
				$file = "$this->savePath/$sess_id";
				if (file_exists($file)) {
					@unlink($file);
				}
			}
			return true;
		}

		function gc($maxlifetime)
		{
			if ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "MemcacheSASL") {
			} elseif ($GLOBALS['distribution_cfg']['SESSION_TYPE'] == "Db") {
				$this->db->query("delete from " . $this->table . " where session_time < " . NOW_TIME);
			} else {
				foreach (glob("$this->savePath/sess_*") as $file) {
					if (filemtime($file) + SESSION_TIME < time() && file_exists($file)) {
						unlink($file);
					}
				}
			}
			return true;
		}
	}
}
if (!function_exists("es_session_start")) {
	function es_session_start($session_id)
	{
		session_set_cookie_params(0, $GLOBALS['distribution_cfg']['COOKIE_PATH'], $GLOBALS['distribution_cfg']['DOMAIN_ROOT'], false, true);
		if ($GLOBALS['distribution_cfg']['SESSION_FILE_PATH'] != "" || $GLOBALS['distribution_cfg']['SESSION_TYPE'] == "MemcacheSASL" || $GLOBALS['distribution_cfg']['SESSION_TYPE'] == "Db") {
			$handler = new FanweSessionHandler();
			session_set_save_handler(
				array($handler, 'open'),
				array($handler, 'close'),
				array($handler, 'read'),
				array($handler, 'write'),
				array($handler, 'destroy'),
				array($handler, 'gc')
			);
		}
		if ($session_id)
			session_id($session_id);
		@session_start();
	}
}
require APP_ROOT_PATH . 'system/db/db.php';
require APP_ROOT_PATH . 'system/utils/es_cookie.php';
require APP_ROOT_PATH . 'system/utils/es_session.php';
if (app_conf("URL_MODEL") == 1) {
	$current_url = APP_ROOT;
	if (isset($_REQUEST['rewrite_param']))
		$rewrite_param = $_REQUEST['rewrite_param'];
	else
		$rewrite_param = "";
	$rewrite_param = str_replace(array("\"", "'"), array("", ""), $rewrite_param);
	$rewrite_param = explode("/", $rewrite_param);
	$rewrite_param_array = array();
	foreach ($rewrite_param as $k => $param_item) {
		if ($param_item != '')
			$rewrite_param_array[] = $param_item;
	}
	foreach ($rewrite_param_array as $k => $v) {
		if (substr($v, 0, 1) == '-') {
			$v = substr($v, 1);
			$ext_param = explode("-", $v);
			foreach ($ext_param as $kk => $vv) {
				if ($kk % 2 == 0) {
					if (preg_match("/(\w+)\[(\w+)\]/", $vv, $matches)) {
						$_GET[$matches[1]][$matches[2]] = $ext_param[$kk + 1];
					} else
						$_GET[$ext_param[$kk]] = $ext_param[$kk + 1];
					if ($ext_param[$kk] != "p") {
						$current_url .= $ext_param[$kk];
						$current_url .= "-" . $ext_param[$kk + 1] . "-";
					}
				}
			}
		} elseif ($k == 0) {
			$ctl_act = explode("-", $v);
			if ($ctl_act[0] != 'id') {
				$_GET['ctl'] = !empty($ctl_act[0]) ? $ctl_act[0] : "";
				$_GET['act'] = !empty($ctl_act[1]) ? $ctl_act[1] : "";
				$current_url .= "/" . $ctl_act[0];
				if (!empty($ctl_act[1]))
					$current_url .= "-" . $ctl_act[1] . "/";
				else
					$current_url .= "/";
			} else {
				$ext_param = explode("-", $v);
				foreach ($ext_param as $kk => $vv) {
					if ($kk % 2 == 0) {
						if (preg_match("/(\w+)\[(\w+)\]/", $vv, $matches)) {
							$_GET[$matches[1]][$matches[2]] = $ext_param[$kk + 1];
						} else
							$_GET[$ext_param[$kk]] = $ext_param[$kk + 1];
						if ($ext_param[$kk] != "p") {
							if ($kk == 0) $current_url .= "/";
							$current_url .= $ext_param[$kk];
							$current_url .= "-" . $ext_param[$kk + 1] . "-";
						}
					}
				}
			}
		} elseif ($k == 1) {
			$ext_param = explode("-", $v);
			foreach ($ext_param as $kk => $vv) {
				if ($kk % 2 == 0) {
					if (preg_match("/(\w+)\[(\w+)\]/", $vv, $matches)) {
						$_GET[$matches[1]][$matches[2]] = $ext_param[$kk + 1];
					} else
						$_GET[$ext_param[$kk]] = $ext_param[$kk + 1];
					if ($ext_param[$kk] != "p") {
						$current_url .= $ext_param[$kk];
						$current_url .= "-" . $ext_param[$kk + 1] . "-";
					}
				}
			}
		}
	}
	$current_url = substr($current_url, -1) == "-" ? substr($current_url, 0, -1) : $current_url;
}
unset($_REQUEST['rewrite_param']);
unset($_GET['rewrite_param']);
require APP_ROOT_PATH . 'system/cache/Cache.php';
$cache = CacheService::getInstance();
require_once APP_ROOT_PATH . "system/cache/CacheFileService.php";
$fcache = new CacheFileService();
$fcache->set_dir(APP_ROOT_PATH . "public/runtime/data/");
define('DB_PREFIX', app_conf('DB_PREFIX'));
if (!file_exists(APP_ROOT_PATH . 'public/runtime/app/db_caches/'))
	mkdir(APP_ROOT_PATH . 'public/runtime/app/db_caches/', 0777);
$pconnect = false;
$db = new mysql_db(app_conf('DB_HOST') . ":" . app_conf('DB_PORT'), app_conf('DB_USER'), app_conf('DB_PWD'), app_conf('DB_NAME'), 'utf8', $pconnect);
require APP_ROOT_PATH . 'system/template/template.php';
if (!file_exists(APP_ROOT_PATH . 'public/runtime/app/tpl_caches/'))
	mkdir(APP_ROOT_PATH . 'public/runtime/app/tpl_caches/', 0777);
if (!file_exists(APP_ROOT_PATH . 'public/runtime/app/tpl_compiled/'))
	mkdir(APP_ROOT_PATH . 'public/runtime/app/tpl_compiled/', 0777);
$tmpl = new AppTemplate;
$_REQUEST = array_merge($_GET, $_POST);
filter_request($_REQUEST);
if (file_exists(APP_ROOT_PATH . 'system/wechat/platform_wechat.class.php')) {
	require APP_ROOT_PATH . 'system/wechat/platform_wechat.class.php';
}
require APP_ROOT_PATH . 'system/utils/message_send.php';
$msg = new message_send();
define("INVEST_TYPE", 0);
define("WEIXIN_TYPE", 0);
define("LICAI_TYPE", 0);
define("FINANCE_TYPE", 0);
define("SELFLESS_TYPE", 1);
define("HOUSE_TYPE", 0);
define("STOCK_TRANSFER_TYPE", 1);
?>