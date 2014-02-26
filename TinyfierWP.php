<?php

/**
 * Plugin Name: tinyfier-wp
 * Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
 * Description: Make your wordpress instalation fly. Once enabled, this plugin will combine, compress and optimize JS, CSS and HTML files to improve page load time.
 * Version: 0.1
 * Author: ideatic
 * Author URI: http://www.ideatic.net
 * License: GPL2
 */
/*
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License, version 2, as
  published by the Free Software Foundation.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */


class TinyfierWP {

    public $minify_css = TRUE;
    public $minify_js = TRUE;
    public $minify_html = FALSE;

    /**
     * Add async attribute to the last javascript block
     * @var boolean
     */
    public $async_js = TRUE;

    /**
     * Maintain CSS order (TRUE) or join all CSS even if that imply alter the order (FALSE, better performance, default)
     * @var boolean
     */
    public $safe_css_order = FALSE;

    public function exec() {
        //Disable when not necessary
        if (defined('WP_ADMIN') || is_feed() || defined('DOING_AJAX') || defined('DOING_CRON') || defined('APP_REQUEST') || defined('XMLRPC_REQUEST') || defined('SHORTINIT') && SHORTINIT) {
            return FALSE;
        }

        ob_start(array($this, 'ob_callback'));
    }

    public function ob_callback($buffer) {
        $replaces = array();

        $modes = array(
            'css' => $this->minify_css,
            'js' => $this->minify_js
        );

        foreach ($modes as $mode => $enabled) {
            if (!$enabled) {
                continue;
            }

            $join_queue = array();
            $assets = $this->_find_assets($buffer, $mode);
            end($assets);
            $lastk = key($assets);
            foreach ($assets as $k => $asset) {
                $replacement = $asset['original'];

                $join = $this->_suitable_for_join($asset, $mode);
                if ($join) {
                    $join_queue[] = $asset['external'];
                    $replacement = '';
                }


                $context_change = !$join;

                if ($context_change && $mode == 'css' && !$this->safe_css_order) {
                    $context_change = FALSE;
                }

                if (($context_change || $k == $lastk) && !empty($join_queue)) {
                    //Join all the previous assets
                    $url = $this->_tinyfier_url($mode, $join_queue);
                    if ($mode == 'css') {
                        $loader = '<link rel="stylesheet" type="text/css" href="' . $url . '" />';
                    } else {
                        if ($join && $k == $lastk && $this->async_js) {
                            $loader = '<script async src="' . $url . '"></script>';
                        } else {
                            $loader = '<script src="' . $url . '"></script>';
                        }
                    }
                    $replacement = $loader . $asset['original'];
                    $join_queue = array();
                }

                if ($replacement != $asset['original']) {
                    $replaces[$asset['original']] = $replacement;
                }
            }
        }

        $buffer = str_replace(array_keys($replaces), array_values($replaces), $buffer);

        //Minify HTML
        if ($this->minify_html) {
            require_once dirname(__FILE__) . '/tinyfier/html/html.php';
            $buffer = TinyfierHTML::process($buffer, array(
                        'external_services' => FALSE
            ));
        }

        return $buffer;
    }

    /**
     * Find JS or CSS assets in the input HTML
     * @param type $type
     */
    private function _find_assets($html, $type) {

        //Remove comments
        $html = preg_replace('~<!--.*?-->~s', '', $html);

        //Find tags
        switch ($type) {
            case 'js':
                $tags = array('script');
                $attr_external = 'src';
                break;

            case 'css':
                $tags = array('link');
                $attr_external = 'href';
                break;
        }

        $found = array();



        $matches = null;
        $pattern = '<(?<tag>' . implode('|', $tags) . ')(?<attrs>[^>]*?)(/>|>(?<content>.*?)</\k<tag>>)';
        $attr_pattern = '(\w+)\s*=\s*((["\']).*?\3|[^\'">\s]+?)';
        if (preg_match_all("~$pattern~is", $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs = array();

                //Parse attrs
                if (preg_match_all("~$attr_pattern~is", $match['attrs'], $attr_matches, PREG_SET_ORDER)) {
                    foreach ($attr_matches as $attr_match) {
                        $attrs[$attr_match[1]] = trim($attr_match[2], '"\'');
                    }
                }

                $found[] = array(
                    'original' => $match[0],
                    'tag' => $match['tag'],
                    'attrs' => $attrs,
                    'content' => $match['content'],
                    'external' => isset($attrs[$attr_external]) ? $attrs[$attr_external] : NULL
                );
            }
        }

        return $found;
    }

    private function _suitable_for_join($asset, $mode) {
        //Only join external assets
        if (!isset($asset['external']) || !empty($asset['content'])) {
            return FALSE;
        }
        $url = $asset['external'];

        //Check if URL is in the current domain
        if ($this->_is_absolute($url) && strpos($url, get_site_url()) === FALSE) {
            return FALSE;
        }

        //Check if it is a stylesheet
        if ($mode == 'css') {
            if (!isset($asset['attrs']['rel']) || stristr($asset['attrs']['rel'], 'stylesheet') === FALSE || (isset($asset['attrs']['media']) && stristr($asset['attrs']['media'], 'print') !== false)) {
                return FALSE;
            }
        }

        return TRUE;
    }

    private function _tinyfier_url($mode, $assets) {
        $normalized_assets = array();

        foreach ($assets as $asset) {
            //Remove query
            $asset = preg_replace('/\?.*$/', '', $asset);

            //Remove blog url
            $asset = ltrim(str_replace(get_site_url(), '', $asset), '/');

            $normalized_assets[] = $asset;
        }

        return content_url('assets.php/' . implode(',', $normalized_assets), __FILE__);
    }

    private function _is_absolute($url) {
        if (!empty($url)) {
            if (strpos($url, '://') !== FALSE && preg_match('#^\w+:\/\/#', $url)) {
                return TRUE;
            }

            if (strlen($url) > 2 && $url[0] == '/' && $url[1] == '/') {
                return TRUE;
            }
        }
        return FALSE;
    }
    
    /* Install/Uninstall routines */

    private static function _get_paths(&$cache_dir, &$loader_path, &$tinyfier) {
        $cache_dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'tinyfier'; //Store cache in wp-content/cache/tinyfier
        $loader_path = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'assets.php';
        $tinyfier = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'tinyfier' . DIRECTORY_SEPARATOR . 'tinyfier.php';
    }

    public static function install() {
        //Place assets loader
        self::_get_paths($cache_dir, $loader_path, $tinyfier);

        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755);
        }

        $php = '<?php
$src_folder = ' . var_export(get_home_path(), TRUE) . '; //Wordpress root path
$cache_dir = ' . var_export($cache_dir, TRUE) . '; //Cache path

require ' . var_export($tinyfier, TRUE) . ';';

        file_put_contents($loader_path, $php);
    }

    public static function uninstall() {
        //Remove assets loader
        self::_get_paths($cache_dir, $loader_path, $tinyfier);

        if (is_dir($cache_dir)) {
            self::_rrmdir($cache_dir);
        }
        if (file_exists($loader_path)) {
            unlink($loader_path);
        }
    }

    private static function _rrmdir($dir) {
        foreach (glob($dir . '/*') as $file) {
            if (is_dir($file)) {
                rrmdir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dir);
    }

}

$instance = new TinyfierWP();

$instance->exec();

register_activation_hook(__FILE__, array('TinyfierWP', 'install'));
register_deactivation_hook(__FILE__, array('TinyfierWP', 'uninstall'));
