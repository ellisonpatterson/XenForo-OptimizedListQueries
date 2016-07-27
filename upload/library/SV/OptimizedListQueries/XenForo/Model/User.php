<?php

class SV_OptimizedListQueries_XenForo_Model_User extends XFCP_SV_OptimizedListQueries_XenForo_Model_User
{
	public function updateSessionActivity($userId, $ip, $controllerName, $action, $viewState, array $inputParams, $viewDate = null, $robotKey = '')
	{
		SV_OptimizedListQueries_Async::run(new XenForo_Model_User(), array($userId, $ip, $controllerName, $action, $viewState, $inputParams, $viewDate, $robotKey), __FUNCTION__);
	}
}