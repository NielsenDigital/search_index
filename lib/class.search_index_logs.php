<?php

Class SearchIndexLogs {
	
	private static $_session_id = NULL;
	
	public function setSessionIdFromCookie($session_id) {
		self::$_session_id = $session_id;
	}
	
	public static function save($keywords=NULL, $sections=NULL, $page=1, $total_entries=0) {
		
		if(is_array($sections)) {
			natsort($sections);
			$sections = implode(',', $sections);
		}
		
		$id = sha1(sprintf(
			'%s-%s-%s',
			self::$_session_id,
			Symphony::Database()->cleanValue($keywords),
			Symphony::Database()->cleanValue($sections)
		));
		
		// has this search (keywords+sections) already been logged this session?
		$already_logged = Symphony::Database()->fetchVar('id', 0, sprintf(
			"SELECT
			 	id
			FROM
				`tbl_search_index_logs`
			WHERE 1=1
				AND id = '%s'
				AND page >= '%d'
			",
			$id,
			$page
		));
		
		if(!$already_logged) {
			Symphony::Database()->insert(
				array(
					'id' => $id,
					'date' => date('Y-m-d H:i:s', time()),
					'keywords' => Symphony::Database()->cleanValue($keywords),
					'sections' => Symphony::Database()->cleanValue($sections),
					'page' => $page,
					'results' => $total_entries,
					'session_id' => self::$_session_id,
					'user_agent' => Symphony::Database()->cleanValue(HTTP_USER_AGENT),
					'ip' => Symphony::Database()->cleanValue(REMOTE_ADDR),
				),
				'tbl_search_index_logs',
				TRUE
			);
		}
		
	}
	
	public static function getTotalSessions($filter) {
		$sql = sprintf(
			"SELECT
				COUNT(*) AS `total`
			FROM
				(%s) AS `temp`
			",
			self::__buildSessionsSQL($filter)
		);
		return (int)Symphony::Database()->fetchVar('total', 0, $sql);
	}
	
	private static function __buildSessionsSQL($filter) {
		$sql = sprintf(
			"SELECT
				DISTINCT(session_id),
				COUNT(id) AS `searches`,
				MAX(results) as `results`,
				MAX(page) as `depth`,
				MIN(date),
				user_agent,
				ip
			FROM
				`tbl_search_index_logs`
			WHERE 1=1
				%s
			GROUP BY
				session_id
			",
			self::__buildWhereFilter($filter)
		);
		//echo $sql;die;
		return $sql;
	}
	
	public static function getSessions($sort_column, $sort_direction, $pagination_page=NULL, $pagination_per_page=NULL, $filter) {
		$pagination_start = ($pagination_page - 1) * $pagination_per_page;
		
		if(!isset($pagination_page)) {
			$pagination_per_page = 999999999;
			$pagination_start = 0;
		}
		
		$sql = sprintf(
			"%s
			ORDER BY
				%s %s
			LIMIT
				%d, %d",
			self::__buildSessionsSQL($filter),
			$sort_column,
			$sort_direction,
			$pagination_start,
			$pagination_per_page
		);
		//echo $sql;die;
		return Symphony::Database()->fetch($sql);
	}
	
	public static function getSessionSearches($session_id) {
		$sql = sprintf(
			"SELECT
				date,
				keywords,
				sections,
				page,
				results
			FROM
				`tbl_search_index_logs`
			WHERE 1=1
				AND session_id='%s'
			ORDER BY
				date desc
			",
			$session_id
		);
		//echo $sql;die;
		return Symphony::Database()->fetch($sql);
	}
	
	/*public static function getStatsCount($statistic, $filter) {
		
		$filter = 'WHERE 1=1 ' . self::__buildWhereFilter($filter);
		
		switch($statistic) {
			case 'unique-users':
				return (int)Symphony::Database()->fetchVar('total', 0, sprintf(
					"SELECT COUNT(DISTINCT(session_id)) as `total` FROM `tbl_search_index_logs` %s", $filter
				));
			break;
			case 'unique-searches':
				return (int)Symphony::Database()->fetchVar('total', 0, sprintf(
					"SELECT COUNT(*) as `total` FROM (SELECT id FROM `tbl_search_index_logs` %s GROUP BY keywords) as `temp`", $filter
				));
			break;
			case 'unique-terms':
				return (int)Symphony::Database()->fetchVar('total', 0, sprintf(
					"SELECT COUNT(DISTINCT(keywords)) as `total` FROM `tbl_search_index_logs` %s", $filter
				));
			break;
			case 'average-results':
				return (int)Symphony::Database()->fetchVar('total', 0, sprintf(
					"SELECT AVG(`temp`.`average`) as `total` FROM (SELECT results as `average` FROM `tbl_search_index_logs` %s GROUP BY keywords) as `temp`", $filter
				));
			break;
			
		}
	}*/
	
	public function getSearchCount($filter) {
		$sql = sprintf(
			"SELECT
				COUNT(id) AS `count`
			FROM
				`tbl_search_index_logs`
			WHERE 1=1
				-- AND keywords <> ''
				%s
			",
			self::__buildWhereFilter($filter)
		);
		//echo $sql;die;	
		return Symphony::Database()->fetchVar('count', 0, $sql);
	}
	
	public static function getCumulativeSearchCount($sort_column, $sort_direction, $pagination_page, $pagination_per_page, $filter) {
		$pagination_start = ($pagination_page - 1) * $pagination_per_page;
		$logs = self::getQueries($sort_column, $sort_direction, 1, $pagination_start, $filter);
		
		foreach($logs as $log) $total += $log['count'];
		return $total;
	}
	
	public static function getTotalQueries($filter) {
		$sql = sprintf(
			"SELECT
				COUNT(*) AS `total`
			FROM
				(%s) AS `temp`
			",
			self::__buildQueryLogsSQL($filter)
		);
		//echo $sql;die;
		return (int)Symphony::Database()->fetchVar('total', 0, $sql);
	}
	
	public static function getQueries($sort_column, $sort_direction, $pagination_page, $pagination_per_page, $filter) {
		$pagination_start = ($pagination_page - 1) * $pagination_per_page;
		
		if(!isset($pagination_page)) {
			$pagination_per_page = 999999999;
			$pagination_start = 0;
		}
		
		$sql = sprintf(
			"%s
			ORDER BY
				%s %s
			LIMIT
				%d, %d",
			self::__buildQueryLogsSQL($filter),
			$sort_column,
			$sort_direction,
			$pagination_start,
			$pagination_per_page
		);
		//echo $sql;die;
		return Symphony::Database()->fetch($sql);
	}
	
	private function __buildQueryLogsSQL($filter) {		
		$sql = sprintf(
			"SELECT
				DISTINCT(keywords) AS `keywords`,
				COUNT(keywords) AS `count`,
				AVG(results) AS `average_results`,
				AVG(page) AS `average_depth`
			FROM
				`tbl_search_index_logs`
			WHERE 1=1
				-- AND keywords <> ''
				%s
			GROUP BY
				keywords
			",
			self::__buildWhereFilter($filter)
		);
		//echo $sql;die;
		return $sql;
	}
	
	private function __buildWhereFilter($filter) {
		return sprintf(
			"%s %s %s %s",
			($filter->keywords ? "AND keywords LIKE '%" . $filter->keywords . "%'" : ''),
			($filter->session_id ? "AND session_id='" . $filter->session_id . "'" : ''),
			($filter->date_from ? "AND date >= '" . $filter->date_from . " 00:00:00'" : ''),
			($filter->date_to ? "AND date <= '" . $filter->date_to . " 23:59:59'" : '')
		);
	}
	
}