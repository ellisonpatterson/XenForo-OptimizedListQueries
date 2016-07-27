<?php

class SV_OptimizedListQueries_Async
{
	protected $obj;
	protected $args;
	protected $db;

	public function __construct($obj, $args)
	{
		$this->obj = unserialize(base64_decode($obj));
		$this->args = unserialize(base64_decode($args));
		$this->db = XenForo_Application::get('db');
	}

	public static function run($obj, $args, $method)
	{
		$initialize = '
			$startTime = microtime(true);
			$fileDir = "' . XenForo_Application::getInstance()->getRootDir() . '";

			require($fileDir . "/library/XenForo/Autoloader.php");
			XenForo_Autoloader::getInstance()->setupAutoloader($fileDir . "/library");

			XenForo_Application::initialize($fileDir . "/library", $fileDir);
			XenForo_Application::set("page_start_time", $startTime);

			$dependencies = new XenForo_Dependencies_Public();
			$dependencies->preLoadData();
		';

		// if (XenForo_Visitor::getUserId() == 1) {
			// echo 'php -r \'' . $initialize . ' $async = new SV_OptimizedListQueries_Async("' . base64_encode(serialize($obj)) . '","' . base64_encode(serialize($args)) . '"); $async->' . $method . '();\' > /dev/null 2>/dev/null &';
		// }

		exec('php -r \'' . $initialize . ' $async = new SV_OptimizedListQueries_Async("' . base64_encode(serialize($obj)) . '","' . base64_encode(serialize($args)) . '"); $async->' . $method . '();\' > /dev/null 2>/dev/null &');
	}

	public static function resolveClassName($className, stdClass &$object)
	{
		if (!class_exists($className))
		{
			throw new InvalidArgumentException(sprintf('Inexistant class %s.', $className));
		}

		$new = new $className();

		foreach($object as $property => &$value)
		{
			$new->$property = &$value;
			unset($object->$property);
		}

		unset($value);
		$object = (unset) $object;

		return $new;
	}

	public function logThreadView()
	{
		$this->db->query('
			INSERT ' . (XenForo_Application::get('options')->enableInsertDelayed ? 'DELAYED' : '') . ' INTO xf_thread_view
				(thread_id)
			VALUES
				(?)
		', $this->args);
	}

	public function markThreadRead()
	{
		list($thread, $forum, $readDate, $viewingUser) = $this->args;

		$userId = $viewingUser['user_id'];
		if (!$userId)
		{
			return false;
		}

		if (!array_key_exists('thread_read_date', $thread))
		{
			$thread['thread_read_date'] = $this->obj->getUserThreadReadDate($userId, $thread['thread_id']);
		}

		if ($readDate <= $this->obj->getMaxThreadReadDate($thread, $forum))
		{
			return false;
		}

		$this->db->query('
			INSERT INTO xf_thread_read
				(user_id, thread_id, thread_read_date)
			VALUES
				(?, ?, ?)
			ON DUPLICATE KEY UPDATE thread_read_date = VALUES(thread_read_date)
		', array($userId, $thread['thread_id'], $readDate));

		if ($readDate < $thread['last_post_date'])
		{
			// we haven't finished reading this thread - forum won't be read
			return false;
		}

		$this->obj->getModelFromCache('XenForo_Model_Forum')->markForumReadIfNeeded($forum, $viewingUser);

		return true;
	}

	public function updateSessionActivity()
	{
		list($userId, $ip, $controllerName, $action, $viewState, $inputParams, $viewDate, $robotKey) = $this->args;

		$userId = intval($userId);
		$ipNum = XenForo_Helper_Ip::getBinaryIp(null, $ip, '');
		$uniqueKey = ($userId ? $userId : $ipNum);

		if ($userId)
		{
			$robotKey = '';
		}

		if (!$viewDate)
		{
			$viewDate = XenForo_Application::$time;
		}


		$logParams = array();
		foreach ($inputParams AS $paramKey => $paramValue)
		{
			if (!strlen($paramKey) || $paramKey[0] == '_' || !is_scalar($paramValue))
			{
				continue;
			}

			$logParams[] = "$paramKey=" . urlencode($paramValue);
		}
		$paramList = implode('&', $logParams);
		$paramList = substr($paramList, 0, 100);

		$controllerName = substr($controllerName, 0, 50);
		$action = substr($action, 0, 50);

		try
		{
			$this->db->query('
				INSERT INTO xf_session_activity
					(user_id, unique_key, ip, controller_name, controller_action, view_state, params, view_date, robot_key)
				VALUES
					(?, ?, ?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE
					ip = VALUES(ip),
					controller_name = VALUES(controller_name),
					controller_action = VALUES(controller_action),
					view_state = VALUES(view_state),
					params = VALUES(params),
					view_date = VALUES(view_date),
					robot_key = VALUES(robot_key)
			', array($userId, $uniqueKey, $ipNum, $controllerName, $action, $viewState, $paramList, $viewDate, $robotKey));
		}
		catch (Zend_Db_Exception $e) {} // ignore db errors here, not that important
	}
}