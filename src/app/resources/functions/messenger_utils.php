<?php

define('MESSENGER_CLI_SCRIPT', 'messenger');

if (!function_exists('build_select_list')) {
	function build_select_list($value, $array, $default = NULL){
		if (($default !== null) && !array_key_exists($value, $array)) {
			$value = $default;
		}
		$s = '';
		foreach($array as $k => $v){
			$s .= "<option value='" . escape($k) . "'" . (($k === $value) ? ' selected' : '');
			$s .= ">" . escape($v) . "</option>\n";
		}
		return $s;
	}
}

if (!function_exists('escape')) {
	function escape($text){
		return htmlspecialchars($text, ENT_QUOTES);
	}
}

if (!function_exists('build_url')) {
	function build_url($path, $params=null){
		$url = $path;
		if (!empty($params)) {
			$params = http_build_query($params);
			if (!empty($params)) {
				$url .= '?' . $params;
			}
		}
		return $url;
	}
}

if (!function_exists('convertDateFromTimezone')) {
	function convertDateFromTimezone($date, $timezone, $timezone_to, $format){
		$date = new DateTime($date, new DateTimeZone($timezone));
		$date->setTimezone( new DateTimeZone($timezone_to) );
		return $date->format($format);
	}
}

if (!function_exists('format_datetime')) {
	// convert datetime to
	function format_datetime($v, $from_timezone = 'UTC', $format = 'Y-m-d H:i:s'){
		return escape(
			convertDateFromTimezone($v, $from_timezone, $_SESSION['time_zone']['system'], $format)
		);
	}
}

if (!function_exists('if_ico_exists')) {
	// returns url for ico if it exists
	function if_ico_exists($name){
		$theme_image_path = '/themes/' . $_SESSION['domain']['template']['name'] . '/images/';
		$theme_image_full_path = $_SERVER['DOCUMENT_ROOT'] . $theme_image_path;
		if(file_exists($theme_image_full_path . $name)){
			return PROJECT_PATH . $theme_image_path . $name;
		}
		return NULL;
	}
}
