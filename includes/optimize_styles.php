<?php

/**
 * @package         etruel\Flash Cache
 * @subpackage 	    Optimize Styles
 * @author          Sebastian Robles
 * @author          Esteban Truelsegaard
 * @copyright       Copyright (c) 2017
 */
// Exit if accessed directly
if (!defined('ABSPATH'))
	exit;

class flash_cache_optimize_styles {

	public static $css_tags			 = array();
	public static $include_inline	 = true;

	public static function hooks() {
		add_filter('flash_cache_response_html', array(__CLASS__, 'init_process'), 1, 2);
	}

	public static function init_process($content, $url_to_cache) {
		if (empty(flash_cache_process::$advanced_settings)) {
			flash_cache_process::$advanced_settings = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
		}
		if (empty(flash_cache_process::$advanced_settings['optimize_styles'])) {
			return $content;
		}

		$noptimize_css = apply_filters('flash_cache_css_noptimize', false, $content, $url_to_cache);
		if ($noptimize_css) {
			return $content;
		}

		// Get <style> and <link>.
		$matches		 = array();
		self::$css_tags	 = array('files' => array(), 'inline' => array());
		
		if (preg_match_all('#(<style[^>]*>.*</style>)|(<link[^>]*stylesheet[^>]*>)#Usmi', $content, $matches)) {
			//$content = print_r($matches, true);
			// $content = '';
			foreach ($matches[0] as $tag) {

				// Get the media.
				if (false !== strpos($tag, 'media=')) {
					preg_match('#media=(?:"|\')([^>]*)(?:"|\')#Ui', $tag, $medias);
					$medias	 = explode(',', $medias[1]);
					$media	 = array();
					foreach ($medias as $elem) {
						/* $media[] = current(explode(' ',trim($elem),2)); */
						if (empty($elem)) {
							$elem = 'all';
						}

						$media[] = $elem;
					}
				} else {
					// No media specified - applies to all.
					$media = array('all');
				}

				if (preg_match('#<link.*href=("|\')(.*)("|\')#Usmi', $tag, $source)) {
					// <link>.
					$url	 = current(explode('?', $source[2], 2));
					$path	 = flash_cache_get_path($url);

					if (false !== $path && preg_match('#\.css$#', $path)) {
						// Good link.
						self::$css_tags['files'][] = array($media, $path);
					} else {

						// Link is dynamic or external.
						$headers = get_headers($source[2], 1);

						// Verifica si el enlace devuelve un código de estado 200 (OK).
						if (strpos($headers[0], '200') !== false) {
							self::$css_tags['files'][] = array($media, $source[2]); // El enlace tiene contenido.
						} else {
							$tag = ''; // El enlace no tiene contenido o no es válido.
						}
					}
				} else {
					// Inline css in style tags can be wrapped in comment tags, so restore comments.
					//$tag = $this->restore_comments( $tag );
					preg_match('#<style.*>(.*)</style>#Usmi', $tag, $code);

					// And re-hide them to be able to to the removal based on tag.
					//$tag = $this->hide_comments( $tag );

					if (self::$include_inline) {
						$code						 = preg_replace('#^.*<!\[CDATA\[(?:\s*\*/)?(.*)(?://|/\*)\s*?\]\]>.*$#sm', '$1', $code[1]);
						self::$css_tags['inline'][]	 = array($media, $code);
					} else {
						$tag = '';
					}
				}

				// Remove the original style tag.
				$content = str_replace($tag, '', $content);
			}
			
		}
		//$content .= print_r($matches[0], true);

		$content = self::end_process($content);

		return $content;
	}

	public static function end_process($content) {

		$arrContextOptions = array(
			"ssl" => array(
				"verify_peer"		 => false,
				"verify_peer_name"	 => false,
			),
		);

		if (empty(flash_cache_process::$advanced_settings)) {
			flash_cache_process::$advanced_settings = wp_parse_args(get_option('flash_cache_advanced_settings', array()), flash_cache_settings::default_advanced_options());
		}
		if (is_null(flash_cache_process::$origin_url)) {
			flash_cache_process::$origin_url = get_site_url(null, '/');
		}
		
		$basename_css	 = '';
		$all_css_code	 = '';

		$cache_dir	 = flash_cache_get_home_path() . flash_cache_process::$advanced_settings['cache_dir'];
		$cache_path	 = $cache_dir . flash_cache_get_server_name() . '/styles/';

		$all_css_code = array_reduce(self::$css_tags['files'], function ($carry, $css_tag) use ($cache_dir, &$basename_css) {
			$media = $css_tag[0];
			$path = $css_tag[1];

			if (!empty($path)) {
				$code = apply_filters('flash_cache_save_fonts', $path, $cache_dir);
				// Process font URLs and links to styles
				if (!empty($code)) {
					$carry .= $code;
					$basename_css = md5($basename_css . $path);
				}
			}

			return $carry;
		}, $all_css_code);

		foreach (self::$css_tags['inline'] as $css_tag) {
			$media	 = $css_tag[0];
			$code	 = $css_tag[1];
			if (!empty($code)) {
				$all_css_code	 .= $code;
				$basename_css	 = md5($basename_css . $code);
			}
		}

		if (!file_exists($cache_path)) {
			@mkdir($cache_path, 0777, true);
		}

		if(empty($basename_css)) {
			$basename_css = 'default_styles';
		}

		$full_path_file_css	 = $cache_path . $basename_css . '.css';
		$url_file_css		 = str_replace(flash_cache_get_home_path(), get_home_url(null, '/'), $full_path_file_css);
		$all_css_code = apply_filters('flash_cache_css_code_before_join', $all_css_code, $full_path_file_css, flash_cache_process::$advanced_settings );
		
		file_put_contents($full_path_file_css, $all_css_code);

		//Call the function insert_html_before_element for change the actual html by the new with styles and scripts
		$content = self::insert_html_before_element($content, '<title>', '<link media="all" rel="stylesheet" href="' . $url_file_css . '" />');

		return $content;
	}

	public static function insert_html_before_element( $html, $element_selector, $new_html)
	{
		// Find the position of the element in the HTML
		$pos = strpos($html, $element_selector);

		if ($pos !== false) {
			// Insert the new HTML before the element
			$html = substr_replace($html, $new_html . PHP_EOL, $pos, 0);
		}

		// Return the modified HTML code
		return $html;
	}

}

flash_cache_optimize_styles::hooks();