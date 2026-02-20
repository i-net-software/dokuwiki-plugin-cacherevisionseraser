<?php
/**
 * Cache/Revisions Eraser admin plugin
 * Version : 1.6.6
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     JustBurn <justburner@armail.pt>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
define('CACHEREVISIONSERASER_VER','1.6.6');
define('CACHEREVISIONSERASER_CONFIGREVISION',2);
define('CACHEREVISIONSERASER_DATE','2010-11-22');

/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_cacherevisionserase extends DokuWiki_Admin_Plugin {

	var $cachedir = null;
	var $revisdir = null;
	var $pagesdir = null;
	var $metadir = null;
	var $configs = null;
	var $filedels = 0;
	var $dirdels = 0;

	/**
	* Constructor
	*/
	function admin_plugin_cacherevisionserase(){
		$this->setupLocale();
		$this->loadConfig();
	}

	/**
	* Load plugin config from configs.php; use built-in defaults if file missing/unreadable (e.g. in Docker).
	* Config is stored in the plugin directory as configs.php (same folder as admin.php).
	*/
	function loadConfig() {
		$this->configs = array();
		$pluginDir = dirname(__FILE__);
		$paths = array($pluginDir . '/configs.php');
		if (defined('DOKU_INC')) {
			$paths[] = DOKU_INC . 'lib/plugins/cacherevisionserase/configs.php';
		}
		foreach ($paths as $path) {
			if (is_file($path) && is_readable($path)) {
				$ok = @include($path);
				if ($ok && isset($this->configs['confrevision']) && $this->configs['confrevision'] > 0) {
					return;
				}
			}
		}
		// File missing, unreadable, or include failed (e.g. container permissions) – use built-in defaults
		$this->configs = $this->getDefaultConfig();
	}

	/**
	* Built-in default config (same as configs.in.php) so plugin works when configs.php is not loadable.
	*/
	function getDefaultConfig() {
		return array(
			'confrevision' => CACHEREVISIONSERASER_CONFIGREVISION,
			'menusort' => 67,
			'allow_allcachedel' => true,
			'allow_allrevisdel' => true,
			'debuglist' => false,
			'cache_delext_i' => -1,
			'cache_delext_xhtml' => -1,
			'cache_delext_js' => -1,
			'cache_delext_css' => -1,
			'cache_delext_mediaP' => -1,
			'cache_delext_UNK' => -1,
			'cache_del_oldlocks' => -1,
			'cache_del_indexing' => -2,
			'cache_del_metafiles' => -1,
			'cache_del_revisfiles' => -1,
			'allow_outputinfo' => true,
			'level_outputinfo' => 2,
		);
	}

	/**
	* Return plug-in info
	*/
	function getInfo(){
		return array(
			'author' => 'JustBurn',
			'email'  => 'justburner@armail.pt',
			'date'   => CACHEREVISIONSERASER_DATE,
			'name'   => html_entity_decode($this->lang['title']),
			'desc'   => html_entity_decode($this->lang['desc']),
			'url'    => 'http://wiki.splitbrain.org/plugin:cacherevisionseraser',
		);
	}

	/**
	* Return prompt for admin menu
	*/
	function getMenuText($language) {
		return (isset($this->lang['menu']) ? $this->lang['menu'] : 'Cache/Revisions Eraser') . ' (v' . CACHEREVISIONSERASER_VER . ')';
	}

	/**
	* Return sort order for position in admin menu
	*/
	function getMenuSort() {
		if (!is_array($this->configs) || !isset($this->configs['menusort']) || $this->configs['menusort'] === null) {
			$this->loadConfig();
		}
		return (is_array($this->configs) && isset($this->configs['menusort'])) ? $this->configs['menusort'] : 67;
	}

	/**
	* Handle user request
	*/
	function handle() {
		global $conf;
		// Ensure config is loaded (plugin may be used before constructor ran, e.g. from menu)
		if (!is_array($this->configs) || !isset($this->configs['confrevision']) || !($this->configs['confrevision'] > 0)) {
			$this->loadConfig();
		}
		$this->cachedir = $conf['cachedir'];
		$this->revisdir = $conf['olddir'];
		$this->pagesdir = $conf['datadir'];
		if ($this->pagesdir == null) $this->pagesdir = isset($conf['savedir']) ? $conf['savedir'] : null; // Olders versions compability?
		$this->metadir = isset($conf['metadir']) ? $conf['metadir'] : null;
		if ($this->metadir == null) $this->metadir = isset($conf['meta']) ? $conf['meta'] : null;      // Olders versions compability?
		$this->locksdir = isset($conf['lockdir']) ? $conf['lockdir'] : null;
		if ($this->locksdir == null) $this->locksdir = $this->pagesdir;  // Olders versions compability?
		$this->lang_id = $conf['lang'];
		$this->locktime = isset($conf['locktime']) ? $conf['locktime'] : 0;
	}

	/**
	* Get request variable
	*/
	function get_req($reqvar, $defaultval) {
		if (isset($_REQUEST[$reqvar])) {
			return $_REQUEST[$reqvar];
		} else {
			return $defaultval;
		}
	}

	/**
	* Compare request variable
	*/
	function cmp_req($reqvar, $strtocmp, $onequal, $ondifferent) {
		if (isset($_REQUEST[$reqvar])) {
			return strcmp($_REQUEST[$reqvar], $strtocmp) ? $ondifferent : $onequal;
		} else {
			return $ondifferent;
		}
	}

	/**
	* Output appropriate html
	*/
	function html() {
		global $ID;
		global $lang;
		global $cacherevercfg;
		global $conf;

		// Ensure locale is loaded (plugin may be used before constructor ran)
		if (empty($this->lang) || !is_array($this->lang)) {
			$this->setupLocale();
		}
		// Ensure config is loaded
		if (!is_array($this->configs) || !isset($this->configs['confrevision']) || $this->configs['confrevision'] == 0) {
			$this->loadConfig();
		}

		$cmd = $this->get_req('cmd', 'main');

		// Plug-in title
		echo '<h1>'.(isset($this->lang['title']) ? $this->lang['title'] : 'Cache/Revisions Eraser').' '.(isset($this->lang['version']) ? $this->lang['version'] : 'Version').' '.CACHEREVISIONSERASER_VER.'</h1>' . "\n";

		// Make sure outputinfo level is valid
		$theoutputinfo = intval($this->get_req('level_outputinfo', 0));
		if (isset($this->configs['allow_outputinfo']) && $this->configs['allow_outputinfo']) {
			if (($theoutputinfo < 0) || ($theoutputinfo > 2)) $theoutputinfo = 0;
		} else {
			$theoutputinfo = intval(isset($this->configs['level_outputinfo']) ? $this->configs['level_outputinfo'] : 0);
		}

		// Debugging only
		if (isset($this->configs['debuglist']) && $this->configs['debuglist']) {
			echo'<table class="inline">' . "\n";
			echo'<tr><th class="centeralign"><strong>Debugging information</strong></th></tr>' . "\n";
			echo'<tr><th>' . "\n";
			echo'config revision: <em>'.$this->configs['confrevision'].' (require '.CACHEREVISIONSERASER_CONFIGREVISION.')</em><br />' . "\n";
			echo'admin menu position: <em>'.$this->configs['menusort'].'</em><br />' . "\n";
			echo'language (C/R E.): <em>'.$this->lang['language'].'</em><br />' . "\n";
			echo'cachedir: <em>'.$this->cachedir.'</em><br />' . "\n";
			echo'revisdir: <em>'.$this->revisdir.'</em><br />' . "\n";
			echo'pagesdir: <em>'.$this->pagesdir.'</em><br />' . "\n";
			echo'metadir: <em>'.$this->metadir.'</em><br />' . "\n";
			echo'locksdir: <em>'.$this->locksdir.'</em><br />' . "\n";
			echo'language id (Doku): <em>'.$this->lang_id.'</em><br />' . "\n";
			echo'</th></tr></table><br />' . "\n";
		}

		// Plug-in processing...
		$this->filedels = 0;
		$this->dirdels = 0;
		if ($this->analyzecrpt($cmd))
		if ((strcmp($cmd, 'erasecache') == 0) && ($this->configs['allow_allcachedel'])) {
			// Erase cache...
			echo'<table class="inline">' . "\n";
			echo'<tr><th class="leftalign">' . "\n";
			clearstatcache();
			$succop = true;
			$params = $this->cmp_req('delfl_UNK', 'yes', 0x01, 0) + $this->cmp_req('del_indexing', 'yes', 0x02, 0) + $this->cmp_req('delfl_i', 'yes', 0x04, 0) + $this->cmp_req('delfl_xhtml', 'yes', 0x08, 0) + $this->cmp_req('delfl_js', 'yes', 0x10, 0) + $this->cmp_req('delfl_css', 'yes', 0x20, 0) + $this->cmp_req('delfl_mediaP', 'yes', 0x40, 0);
			$prmask = ($this->configs['cache_delext_UNK']==0 ? 0 : 0x01) + ($this->configs['cache_del_indexing']==0 ? 0 : 0x02) + ($this->configs['cache_delext_i']==0 ? 0 : 0x04) + ($this->configs['cache_delext_xhtml']==0 ? 0 : 0x08) + ($this->configs['cache_delext_js']==0 ? 0 : 0x10) + ($this->configs['cache_delext_css']==0 ? 0 : 0x20) + ($this->configs['cache_delext_mediaP']==0 ? 0 : 0x40);
			if ($this->cmp_req('del_oldpagelocks', 'yes', true, false) && ($this->configs['cache_del_oldlocks'])) {
				if ($this->rmeverything_oldlockpages($this->locksdir, $this->locksdir, $theoutputinfo) == false) $succop = false;
			}
			if ($this->rmeverything_cache($this->cachedir, $this->cachedir, $params & $prmask, $theoutputinfo) == false) $succop = false;
			echo'<strong>'.$this->lang['numfilesdel'].' '.$this->filedels.'<br />'.$this->lang['numdirsdel'].' '.$this->dirdels.'</strong><br />' . "\n";
			echo'</th></tr>' . "\n";
			if ($succop)
				echo'<tr><th>'.$this->lang['successcache'].'</th></tr>' . "\n";
			else
				echo'<tr><th>'.$this->lang['failedcache'].'</th></tr>' . "\n";
			echo'</table>' . "\n";
			echo'<table class="inline">' . "\n";
			echo'<tr><th class="centeralign">' . "\n";
			echo'<form method="post" action="'.wl($ID).'"><div class="no">' . "\n";
			echo'<input type="hidden" name="do" value="admin" />' . "\n";
			echo'<input type="hidden" name="page" value="cacherevisionserase" />' . "\n";
			echo'<input type="hidden" name="cmd" value="main" />' . "\n";
			echo'<input type="submit" class="button" value="'.$this->lang['backbtn'].'" />' . "\n";
			echo'</div></form></th></tr></table>' . "\n";
		} else if ((strcmp($cmd, 'eraseallrevisions') == 0) && ($this->configs['allow_allrevisdel'])) {
			// Erase old revisions...
			echo'<table class="inline">' . "\n";
			echo'<tr><th class="leftalign">' . "\n";
			clearstatcache();
			$succop = true;
			if ($this->cmp_req('del_metafiles', 'yes', true, false) && ($this->configs['cache_del_metafiles'])) {
				if ($this->rmeverything_meta($this->metadir, $this->metadir, $theoutputinfo) == false) $succop = false;
			}
			if ($this->cmp_req('del_revisfiles', 'yes', true, false) && ($this->configs['cache_del_revisfiles'])) {
				if ($this->rmeverything_revis($this->revisdir, $this->revisdir, $theoutputinfo) == false) $succop == false;
			}
			echo'<strong>'.$this->lang['numfilesdel'].' '.$this->filedels.'<br />'.$this->lang['numdirsdel'].' '.$this->dirdels.'</strong><br />' . "\n";
			echo'</th></tr>' . "\n";
			if ($succop)
				echo'<tr><th>'.$this->lang['successrevisions'].'</th></tr>' . "\n";
			else
				echo'<tr><th>'.$this->lang['failedrevisions'].'</th></tr>' . "\n";
			echo'</table>' . "\n";
			echo'<table class="inline">' . "\n";
			echo'<tr><th class="centeralign">' . "\n";
			echo'<form method="post" action="'.wl($ID).'"><div class="no">' . "\n";
			echo'<input type="hidden" name="do" value="admin" />' . "\n";
			echo'<input type="hidden" name="page" value="cacherevisionserase" />' . "\n";
			echo'<input type="hidden" name="cmd" value="main" />' . "\n";
			echo'<input type="submit" class="button" value="'.$this->lang['backbtn'].'" />' . "\n";
			echo'</div></form></th></tr></table>' . "\n";
		} else {
			// Controls
			echo'<table class="inline">' . "\n";
			echo'<tr><th class="centeralign">' . "\n";
			if ($this->configs['allow_allcachedel']) {
				echo$this->lang['cachedesc'].'</th></tr><tr><th class="leftalign"><br/>' . "\n";
				echo'<form method="post" action="'.wl($ID).'" onsubmit="return confirm(\''.str_replace('\\\\n','\\n',addslashes($this->lang['askcache'])).'\')">' . "\n";
				echo'<input type="hidden" name="do" value="admin" />' . "\n";
				echo'<input type="hidden" name="page" value="cacherevisionserase" />' . "\n";
				echo'<input type="hidden" name="cmd" value="erasecache" />' . "\n";
				if ($this->configs['cache_delext_i'] < 0)
					echo'<input type="checkbox" name="delfl_i" value="yes" '.(($this->configs['cache_delext_i']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['extdesc_i'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="delfl_i" value="yes" '.($this->configs['cache_delext_i'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['extdesc_i'].'<br />' . "\n";
				if ($this->configs['cache_delext_xhtml'] < 0)
					echo'<input type="checkbox" name="delfl_xhtml" value="yes" '.(($this->configs['cache_delext_xhtml']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['extdesc_xhtml'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="delfl_xhtml" value="yes" '.($this->configs['cache_delext_xhtml'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['extdesc_xhtml'].'<br />' . "\n";
				if ($this->configs['cache_delext_js'] < 0)
					echo'<input type="checkbox" name="delfl_js" value="yes" '.(($this->configs['cache_delext_js']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['extdesc_js'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="delfl_js" value="yes" '.($this->configs['cache_delext_js'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['extdesc_js'].'<br />' . "\n";
				if ($this->configs['cache_delext_css'] < 0)
					echo'<input type="checkbox" name="delfl_css" value="yes" '.(($this->configs['cache_delext_css']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['extdesc_css'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="delfl_css" value="yes" '.($this->configs['cache_delext_css'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['extdesc_css'].'<br />' . "\n";
				if ($this->configs['cache_delext_mediaP'] < 0)
					echo'<input type="checkbox" name="delfl_mediaP" value="yes" '.(($this->configs['cache_delext_mediaP']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['extdesc_mediaP'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="delfl_mediaP" value="yes" '.($this->configs['cache_delext_mediaP'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['extdesc_mediaP'].'<br />' . "\n";
				if ($this->configs['cache_delext_UNK'] < 0)
					echo'<input type="checkbox" name="delfl_UNK" value="yes" '.(($this->configs['cache_delext_UNK']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['extdesc_UNK'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="delfl_UNK" value="yes" '.($this->configs['cache_delext_UNK'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['extdesc_UNK'].'<br />' . "\n";
				if ($this->configs['cache_del_oldlocks'] < 0)
					echo'<input type="checkbox" name="del_oldpagelocks" value="yes" '.(($this->configs['cache_del_oldlocks']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['deloldlockdesc'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="del_oldpagelocks" value="yes" '.($this->configs['cache_del_oldlocks'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['deloldlockdesc'].'<br />' . "\n";
				if ($this->configs['cache_del_indexing'] < 0)
					echo'<input type="checkbox" name="del_indexing" value="yes" '.(($this->configs['cache_del_indexing']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['delindexingdesc'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="del_indexing" value="yes" '.($this->configs['cache_del_indexing'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['delindexingdesc'].'<br />' . "\n";
				echo'<br />' . "\n";
				if ($this->configs['allow_outputinfo']) {
					echo $this->lang['outputinfo_text'].' <input type="radio" name="level_outputinfo" value="0" '.($this->configs['level_outputinfo']==0 ? 'checked="checked"' : '').' />'.$this->lang['outputinfo_lvl0'] . "\n";
					echo'<input type="radio" name="level_outputinfo" value="1" '.($this->configs['level_outputinfo']==1 ? 'checked="checked"' : '').' />'.$this->lang['outputinfo_lvl1'] . "\n";
					echo'<input type="radio" name="level_outputinfo" value="2" '.($this->configs['level_outputinfo']==2 ? 'checked="checked"' : '').' />'.$this->lang['outputinfo_lvl2'] . "\n";
				} else {
					if ($this->configs['level_outputinfo'] == 0) {
						echo'<input type="hidden" name="level_outputinfo" value="0" />'.$this->lang['outputinfo_text'].' '.$this->lang['outputinfo_lvl0'] . "\n";
					} else if ($this->configs['level_outputinfo'] == 1) {
						echo'<input type="hidden" name="level_outputinfo" value="1" />'.$this->lang['outputinfo_text'].' '.$this->lang['outputinfo_lvl1'] . "\n";
					} else if ($this->configs['level_outputinfo'] == 2) {
						echo'<input type="hidden" name="level_outputinfo" value="2" />'.$this->lang['outputinfo_text'].' '.$this->lang['outputinfo_lvl2'] . "\n";
					}
				}
				echo'<br /><br /><div class="centeralign"><input type="submit" class="button" value="'.$this->lang['erasecachebtn'].'" /></div>' . "\n";
				echo'</form>' . "\n";
			} else {
				echo $this->lang['cachedisabled'].'<br />' . "\n";
			}
			echo'</th></tr><tr><td style="border-style: none">&nbsp;<br /></td></tr>' . "\n";
			echo'<tr><th class="centeralign">' . "\n";
			if ($this->configs['allow_allrevisdel']) {
				echo$this->lang['revisionsdesc'].'</th></tr><tr><th class="leftalign"><br />' . "\n";
				echo'<form method="post" action="'.wl($ID).'" onsubmit="return confirm(\''.str_replace('\\\\n','\\n',addslashes($this->lang['askrevisions'])).'\')">' . "\n";
				echo'<input type="hidden" name="do" value="admin" />' . "\n";
				echo'<input type="hidden" name="page" value="cacherevisionserase" />' . "\n";
				echo'<input type="hidden" name="cmd" value="eraseallrevisions" />' . "\n";
				if ($this->configs['cache_del_metafiles'] < 0)
					echo'<input type="checkbox" name="del_metafiles" value="yes" '.(($this->configs['cache_del_metafiles']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['delmetadesc'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="del_metafiles" value="yes" '.($this->configs['cache_del_metafiles'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['delmetadesc'].'<br />' . "\n";
				if ($this->configs['cache_del_revisfiles'] < 0)
					echo'<input type="checkbox" name="del_revisfiles" value="yes" '.(($this->configs['cache_del_revisfiles']+2) ? 'checked="checked"' : '').' />&nbsp;'.$this->lang['delrevisdesc'].'<br />' . "\n";
				else
					echo'<input type="checkbox" name="del_revisfiles" value="yes" '.($this->configs['cache_del_revisfiles'] ? 'checked="checked"' : '').' disabled />&nbsp;'.$this->lang['delrevisdesc'].'<br />' . "\n";
				echo'<br />' . "\n";
				if ($this->configs['allow_outputinfo']) {
					echo $this->lang['outputinfo_text'].' <input type="radio" name="level_outputinfo" value="0" '.($this->configs['level_outputinfo']==0 ? 'checked="checked"' : '').' />'.$this->lang['outputinfo_lvl0'] . "\n";
					echo'<input type="radio" name="level_outputinfo" value="1" '.($this->configs['level_outputinfo']==1 ? 'checked="checked"' : '').' />'.$this->lang['outputinfo_lvl1'] . "\n";
					echo'<input type="radio" name="level_outputinfo" value="2" '.($this->configs['level_outputinfo']==2 ? 'checked="checked"' : '').' />'.$this->lang['outputinfo_lvl2'] . "\n";
				} else {
					if ($this->configs['level_outputinfo'] == 0) {
						echo'<input type="hidden" name="level_outputinfo" value="0" />'.$this->lang['outputinfo_text'].' '.$this->lang['outputinfo_lvl0'] . "\n";
					} else if ($this->configs['level_outputinfo'] == 1) {
						echo'<input type="hidden" name="level_outputinfo" value="1" />'.$this->lang['outputinfo_text'].' '.$this->lang['outputinfo_lvl1'] . "\n";
					} else if ($this->configs['level_outputinfo'] == 2) {
						echo'<input type="hidden" name="level_outputinfo" value="2" />'.$this->lang['outputinfo_text'].' '.$this->lang['outputinfo_lvl2'] . "\n";
					}
				}
				echo'<br /><br /><p class="centeralign"><input type="submit" class="button" value="'.$this->lang['eraserevisionsbtn'].'" /></p>' . "\n";
				echo'<div class="centeralign"><em>'.$this->lang['revisionswarn'].'</em></div>' . "\n";
				echo'</form>' . "\n";
			} else {
				echo$this->lang['revisdisabled'].'<br />' . "\n";
			}
			echo'</th></tr></table>' . "\n";
		}
		echo'<br /><a href="http://wiki.splitbrain.org/plugin:cacherevisionseraser" class="urlextern" target="_blank">'.(isset($this->lang['searchyounewversionurl']) ? $this->lang['searchyounewversionurl'] : 'Search for new version').'</a> [English only]<br />' . "\n";
	}

	/**
	* Delete all files into cache directory
	*/
	function rmeverything_cache($fileglob, $basedir, $params, $outputinfo)
	{
		$fileglob2 = substr($fileglob, strlen($basedir));
		if (strpos($fileglob, '*') !== false) {
			foreach (glob($fileglob) as $filename) {
				$this->rmeverything_cache($filename, $basedir, $params, $outputinfo);
			}
		} else if (is_file($fileglob)) {
			if (strcmp($fileglob2, '/_dummy') == 0) return true;
			$pathinfor = pathinfo($fileglob2);
			if (strcmp($basedir, dirname($fileglob)) == 0) {
				if (!($params & 0x02)) return true;
			} else {
				if (substr_count(strtolower($pathinfor['basename']), '.media.') > 0) {
					if (!($params & 0x40)) return true;
				} else if (strcmp(strtolower($pathinfor['extension']), 'i') == 0) {
					if (!($params & 0x04)) return true;
				} else if (strcmp(strtolower($pathinfor['extension']), 'xhtml') == 0) {
					if (!($params & 0x08)) return true;
				} else if (strcmp(strtolower($pathinfor['extension']), 'js') == 0) {
					if (!($params & 0x10)) return true;
				} else if (strcmp(strtolower($pathinfor['extension']), 'css') == 0) {
					if (!($params & 0x20)) return true;
				} else {
					if (!($params & 0x01)) return true;
				}
			}
			if (@unlink($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefile'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['cache_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->filedels++;
				return true;
			} else {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefileerr'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['cache_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				return false;
			}
		} else if (is_dir($fileglob)) {
			$ok = $this->rmeverything_cache($fileglob.'/*', $basedir, $params, $outputinfo);
			if (!$ok) return false;
			if (strcmp($fileglob, $basedir) == 0) return true;
			if (@rmdir($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletedir'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['cache_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->dirdels++;
				return true;
			} else {
				return true;
			}
		} else {
			// Woha, this shouldn't never happen...
			if ($outputinfo > 0) echo'<strong>'.$this->lang['pathclasserror'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['cache_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
			return false;
		}
		return true;
	}

	/**
	* Delete all old lost locks into "data/pages" or "data/locks" directory
	*/
	function rmeverything_oldlockpages($fileglob, $basedir, $outputinfo)
	{
		$fileglob2 = substr($fileglob, strlen($basedir));
		if (strpos($fileglob, '*') !== false) {
			foreach (glob($fileglob) as $filename) {
				$this->rmeverything_oldlockpages($filename, $basedir, $outputinfo);
			}
		} else if (is_file($fileglob)) {
			if (strcmp($fileglob2, '/_dummy') == 0) return true;
			$pathinfor = pathinfo($fileglob2);
			if (strcmp(strtolower($pathinfor['extension']), 'lock') != 0) return true;
			if (time()-@filemtime($fileglob) < $this->locktime) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['lockexpirein'].' '.($this->locktime-(time()-@filemtime($fileglob))).' '.$this->lang['seconds'].'</strong> -&gt; <em>"'.$fileglob2.'"</em>.<br />' . "\n";
				return true;
			}
			if (@unlink($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefile'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['lock_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->filedels++;
				return true;
			} else {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefileerr'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['lock_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				return false;
			}
		} else if (is_dir($fileglob)) {
			$ok = $this->rmeverything_oldlockpages($fileglob.'/*', $basedir, $outputinfo);
			if (!$ok) return false;
			if (strcmp($fileglob, $basedir) == 0) return true;
			if (@rmdir($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletedir'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['lock_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->dirdels++;
				return true;
			} else {
				return true;
			}
		} else {
			// Woha, this shouldn't never happen...
			if ($outputinfo > 0) echo'<strong>'.$this->lang['pathclasserror'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['lock_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
			return false;
		}
		return true;
	}

	/**
	* Delete all files into meta directory
	*/
	function rmeverything_meta($fileglob, $basedir, $outputinfo)
	{
		$fileglob2 = substr($fileglob, strlen($basedir));
		if (strpos($fileglob, '*') !== false) {
			foreach (glob($fileglob) as $filename) {
				$this->rmeverything_meta($filename, $basedir, $outputinfo);
			}
		} else if (is_file($fileglob)) {
			if (strcmp($fileglob2, '/_dummy') == 0) return true;
			$pathinfor = pathinfo($fileglob2);                                              // For compatibility with the following:
			if (strcmp(strtolower($pathinfor['extension']), 'comments') == 0) return true;  //  Discussion Plugin
			if (strcmp(strtolower($pathinfor['extension']), 'doodle') == 0) return true;    //  Doodle & Doodle2 Plugins
			if (@unlink($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefile'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['meta_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->filedels++;
				return true;
			} else {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefileerr'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['meta_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				return false;
			}
		} else if (is_dir($fileglob)) {
			$ok = $this->rmeverything_meta($fileglob.'/*', $basedir, $outputinfo);
			if (!$ok) return false;
			if (strcmp($fileglob, $basedir) == 0) return true;
			if (@rmdir($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletedir'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['meta_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->dirdels++;
				return true;
			} else {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletedirerr'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['meta_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				return false;
			}
		} else {
			// Woha, this shouldn't never happen...
			if ($outputinfo > 0) echo'<strong>'.$this->lang['pathclasserror'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['meta_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
			return false;
		}
		return true;
	}

	/**
	* Delete all files into old revisions directory
	*/
	function rmeverything_revis($fileglob, $basedir, $outputinfo)
	{
		$fileglob2 = substr($fileglob, strlen($basedir));
		if (strpos($fileglob, '*') !== false) {
			foreach (glob($fileglob) as $filename) {
				$this->rmeverything_revis($filename, $basedir, $outputinfo);
			}
		} else if (is_file($fileglob)) {
			if (strcmp($fileglob2, '/_dummy') == 0) return true;
			if (@unlink($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefile'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['revis_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->filedels++;
				return true;
			} else {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletefileerr'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['revis_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				return false;
			}
		} else if (is_dir($fileglob)) {
			$ok = $this->rmeverything_revis($fileglob.'/*', $basedir, $outputinfo);
			if (!$ok) return false;
			if (strcmp($fileglob, $basedir) == 0) return true;
			if (@rmdir($fileglob)) {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletedir'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['revis_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				$this->dirdels++;
				return true;
			} else {
				if ($outputinfo > 0) echo'<strong>'.$this->lang['deletedirerr'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['revis_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
				return false;
			}
		} else {
			// Woha, this shouldn't never happen...
			if ($outputinfo > 0) echo'<strong>'.$this->lang['pathclasserror'].'</strong>'.(($outputinfo==2) ? ' ('.$this->lang['revis_word'].') ' : ' ').'<em>"'.$fileglob2.'"</em>.<br />' . "\n";
			return false;
		}
		return true;
	}

	/**
	* Routine to analyze configurations and directories
	*/
	function analyzecrpt($cmd)
	{
		global $ID;

		// Ensure config is loaded (may not have been set before html() in some request flows)
		if (!is_array($this->configs) || !isset($this->configs['confrevision']) || $this->configs['confrevision'] == 0) {
			$this->loadConfig();
		}
		$analizysucessy = true;
		if (isset($this->configs['confrevision']) && $this->configs['confrevision'] == 0) {
			echo'<strong>'.(isset($this->lang['analyze_confmissingfailed']) ? $this->lang['analyze_confmissingfailed'] : 'Configuration missing or failed').' (ERR: 1)</strong><br />' . "\n";
			$analizysucessy = false;
		}
		if (($this->configs['confrevision'] != CACHEREVISIONSERASER_CONFIGREVISION) && ($analizysucessy)) {
			echo'<strong>'.$this->lang['analyze_confrevisionfailed'].' (ERR: 2)</strong><br />' . "\n";
			$analizysucessy = false;
		}
		if ($analizysucessy == false) {
			if (strcmp($cmd, 'createconf') == 0) {
				$this->writeconfigs();
				echo '<strong>'.(isset($this->lang['analyze_creatingdefconfs']) ? $this->lang['analyze_creatingdefconfs'] : 'Creating configurations file...') . "\n";
				if (file_exists(dirname(__FILE__).'/configs.php')) {
					echo (isset($this->lang['analyze_creatingdefconfs_o']) ? $this->lang['analyze_creatingdefconfs_o'] : 'success (Please reanalyze)') . "\n";
				} else {
					echo (isset($this->lang['analyze_creatingdefconfs_x']) ? $this->lang['analyze_creatingdefconfs_x'] : 'failed (C/R Erase plug-in directory doesn\'t allow writing)') . "\n";
				}
				echo'</strong><br /><br /><form method="post" action="'.wl($ID).'">' . "\n";
				echo'<input type="hidden" name="do" value="admin" />' . "\n";
				echo'<input type="hidden" name="page" value="cacherevisionserase" />' . "\n";
				echo'<input type="hidden" name="cmd" value="main" />' . "\n";
				echo'<input type="submit" class="button" value="'.(isset($this->lang['reanalyzebtn']) ? $this->lang['reanalyzebtn'] : 'Reanalyze').'" />' . "\n";
				echo'</form><br />' . "\n";
			} else {
				echo'<br /><form method="post" action="'.wl($ID).'"><div class="no">' . "\n";
				echo'<input type="hidden" name="do" value="admin" />' . "\n";
				echo'<input type="hidden" name="page" value="cacherevisionserase" />' . "\n";
				echo'<input type="hidden" name="cmd" value="createconf" />' . "\n";
				echo'<table width="100%" class="inline"><tr>' . "\n";
				echo'<th width="100">&nbsp;</th>' . "\n";
				echo'<th width="120"><strong>'.(isset($this->lang['wordb_option']) ? $this->lang['wordb_option'] : 'Option').'</strong></th>' . "\n";
				echo'<th><strong>'.(isset($this->lang['wordb_optiondesc']) ? $this->lang['wordb_optiondesc'] : 'Description').'</strong></th></tr><tr>' . "\n";
				echo'<td />' . "\n";
				echo'<td><input type="text" name="menusort" value="67" maxlength="2" size="2" /></td>' . "\n";
				echo'<td>'.(isset($this->lang['cfgdesc_menusort']) ? $this->lang['cfgdesc_menusort'] : 'Menu sort order').'</td>' . "\n";
				echo'</tr><tr><th />' . "\n";
				echo'<th><strong>'.(isset($this->lang['wordb_enable']) ? $this->lang['wordb_enable'] : 'Enable').'</strong></th>' . "\n";
				echo'<th><strong>'.(isset($this->lang['wordb_optiondesc']) ? $this->lang['wordb_optiondesc'] : 'Description').'</strong></th>' . "\n";
				echo'</tr><tr><td />' . "\n";
				echo'<td><input type="checkbox" name="allow_allcachedel_E" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>'.(isset($this->lang['delxcacheclass']) ? $this->lang['delxcacheclass'] : 'Delete cache class').'</td>' . "\n";
				echo'</tr><tr><td />' . "\n";
				echo'<td><input type="checkbox" name="allow_allrevisdel_E" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>'.(isset($this->lang['delxrevisclass']) ? $this->lang['delxrevisclass'] : 'Delete revisions class').'</td>' . "\n";
				echo'</tr><tr><td />' . "\n";
				echo'<td><input type="checkbox" name="allow_debug_E" value="yes" /></td>' . "\n";
				echo'<td>'.(isset($this->lang['delxdebugmode']) ? $this->lang['delxdebugmode'] : 'Debug mode').'</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<th><strong>'.(isset($this->lang['wordb_allowuserchag']) ? $this->lang['wordb_allowuserchag'] : 'Allow user change').'</strong></th>' . "\n";
				echo'<th><strong>'.(isset($this->lang['wordb_checkedasdef']) ? $this->lang['wordb_checkedasdef'] : 'Checked as default').'</strong></th>' . "\n";
				echo'<th><strong>'.(isset($this->lang['wordb_optiondesc']) ? $this->lang['wordb_optiondesc'] : 'Description').'</strong></th>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="delext_i_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="delext_i_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['extdesc_i']) ? $this->lang['extdesc_i'] : 'Extension .i') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="delext_xhtml_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="delext_xhtml_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['extdesc_xhtml']) ? $this->lang['extdesc_xhtml'] : 'Extension .xhtml') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="delext_js_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="delext_js_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['extdesc_js']) ? $this->lang['extdesc_js'] : 'Extension .js') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="delext_css_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="delext_css_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['extdesc_css']) ? $this->lang['extdesc_css'] : 'Extension .css') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="delext_mediaP_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="delext_mediaP_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['extdesc_mediaP']) ? $this->lang['extdesc_mediaP'] : 'Extension mediaP') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="delext_UNK_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="delext_UNK_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['extdesc_UNK']) ? $this->lang['extdesc_UNK'] : 'Extension UNK') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="del_oldlock_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="del_oldlock_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['deloldlockdesc']) ? $this->lang['deloldlockdesc'] : 'Delete old locks') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="del_indexing_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="del_indexing_C" value="yes" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['delindexingdesc']) ? $this->lang['delindexingdesc'] : 'Delete indexing') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="del_meta_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="del_meta_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['delmetadesc']) ? $this->lang['delmetadesc'] : 'Delete meta files') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="del_revis_A" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="checkbox" name="del_revis_C" value="yes" checked="checked" /></td>' . "\n";
				echo'<td>' . (isset($this->lang['delrevisdesc']) ? $this->lang['delrevisdesc'] : 'Delete revisions') . '</td>' . "\n";
				echo'</tr><tr>' . "\n";
				echo'<td><input type="checkbox" name="allow_outputinfo" value="yes" checked="checked" /></td>' . "\n";
				echo'<td><input type="radio" name="level_outputinfo" value="0" />'.(isset($this->lang['outputinfo_lvl0']) ? $this->lang['outputinfo_lvl0'] : 'Level 0').'<br />' . "\n";
				echo'<input type="radio" name="level_outputinfo" value="1" />'.(isset($this->lang['outputinfo_lvl1']) ? $this->lang['outputinfo_lvl1'] : 'Level 1').'<br />' . "\n";
				echo'<input type="radio" name="level_outputinfo" value="2" checked="checked" />'.(isset($this->lang['outputinfo_lvl2']) ? $this->lang['outputinfo_lvl2'] : 'Level 2') . "\n";
				echo'</td><td>'.(isset($this->lang['delxverbose']) ? $this->lang['delxverbose'] : 'Verbose').'</td>' . "\n";
				echo'</tr><tr><th /><th />' . "\n";
				echo'<th><input type="submit" class="button" value="'.(isset($this->lang['createconfbtn']) ? $this->lang['createconfbtn'] : 'Create Config').'" /></th>' . "\n";
				echo'</tr></table></div></form>' . "\n";
			}
		}
		if (!is_dir($this->cachedir)) {
			echo'<strong>'.$this->lang['analyze_cachedirfailed'].' (ERR: 3)</strong><br />' . "\n";
			$analizysucessy = false;
		}
		if (!is_dir($this->revisdir)) {
			echo'<strong>'.$this->lang['analyze_revisdirfailed'].' (ERR: 4)</strong><br />' . "\n";
			$analizysucessy = false;
		}
		if (!is_dir($this->pagesdir)) {
			echo'<strong>'.$this->lang['analyze_pagesdirfailed'].' (ERR: 5)</strong><br />' . "\n";
			$analizysucessy = false;
		}
		if (!is_dir($this->metadir)) {
			echo'<strong>'.$this->lang['analyze_metadirfailed'].' (ERR: 6)</strong><br />' . "\n";
			$analizysucessy = false;
		}
		if (!is_dir($this->locksdir)) {
			echo'<strong>'.$this->lang['analyze_locksdirfailed'].' (ERR: 7)</strong><br />' . "\n";
			$analizysucessy = false;
		}
		if ($analizysucessy == false) {
			echo'<br /><strong>'.(isset($this->lang['analyze_checkreadme']) ? $this->lang['analyze_checkreadme'] : 'Please check README').'</strong><br />' . "\n";
		}
		return $analizysucessy;
	}

	/**
	* Routine to create "configs.php"
	*/
	function writeconfigs()
	{
		global $lang;
		$cahdelext_i = -2 + $this->cmp_req('delext_i_A', 'yes', 0, 2) + $this->cmp_req('delext_i_C', 'yes', 1, 0);
		$cahdelext_xhtml = -2 + $this->cmp_req('delext_xhtml_A', 'yes', 0, 2) + $this->cmp_req('delext_xhtml_C', 'yes', 1, 0);
		$cahdelext_js = -2 + $this->cmp_req('delext_js_A', 'yes', 0, 2) + $this->cmp_req('delext_js_C', 'yes', 1, 0);
		$cahdelext_css = -2 + $this->cmp_req('delext_css_A', 'yes', 0, 2) + $this->cmp_req('delext_css_C', 'yes', 1, 0);
		$cahdelext_mediaP = -2 + $this->cmp_req('delext_mediaP_A', 'yes', 0, 2) + $this->cmp_req('delext_mediaP_C', 'yes', 1, 0);
		$cahdelext_UNK = -2 + $this->cmp_req('delext_UNK_A', 'yes', 0, 2) + $this->cmp_req('delext_UNK_C', 'yes', 1, 0);
		$cahdel_oldlocks = -2 + $this->cmp_req('del_oldlock_A', 'yes', 0, 2) + $this->cmp_req('del_oldlock_C', 'yes', 1, 0);
		$cahdel_indexing = -2 + $this->cmp_req('del_indexing_A', 'yes', 0, 2) + $this->cmp_req('del_indexing_C', 'yes', 1, 0);
		$cahdel_metafiles = -2 + $this->cmp_req('del_meta_A', 'yes', 0, 2) + $this->cmp_req('del_meta_C', 'yes', 1, 0);
		$cahdel_revisfiles = -2 + $this->cmp_req('del_revis_A', 'yes', 0, 2) + $this->cmp_req('del_revis_C', 'yes', 1, 0);
		$wcnf = fopen(dirname(__FILE__).'/configs.php', 'w');
		fwrite($wcnf, "<?php\n/**\n * Cache/Revisions Eraser configuration file\n *\n * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)\n * @author     JustBurn <justburner@armail.pt>\n *\n *\n");
		fwrite($wcnf, " * Generated automatically by the plug-in, Cache/Revisions Eraser v" . CACHEREVISIONSERASER_VER . "\n *\n */\n\n");
		fwrite($wcnf, '$this->configs[\'confrevision\'] = 2;' . "\n");
		if ((intval($this->get_req('menusort','67')) >= 0) && (intval($this->get_req('menusort','67')) <= 99))
			fwrite($wcnf, '$this->configs[\'menusort\'] = ' . intval($this->get_req('menusort','67')) . ";\n");
		else
			fwrite($wcnf, '$this->configs[\'menusort\'] = 67' . ";\n");
		fwrite($wcnf, '$this->configs[\'allow_allcachedel\'] = ' . $this->cmp_req('allow_allcachedel_E', 'yes', 'true', 'false') . ";\n");
		fwrite($wcnf, '$this->configs[\'allow_allrevisdel\'] = ' . $this->cmp_req('allow_allrevisdel_E', 'yes', 'true', 'false') . ";\n");
		fwrite($wcnf, '$this->configs[\'debuglist\'] = ' . $this->cmp_req('allow_debug_E', 'yes', 'true', 'false') . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_delext_i\'] = ' . $cahdelext_i . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_delext_xhtml\'] = ' . $cahdelext_xhtml . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_delext_js\'] = ' . $cahdelext_js . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_delext_css\'] = ' . $cahdelext_css . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_delext_mediaP\'] = ' . $cahdelext_mediaP . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_delext_UNK\'] = ' . $cahdelext_UNK . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_del_oldlocks\'] = ' . $cahdel_oldlocks . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_del_indexing\'] = ' . $cahdel_indexing . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_del_metafiles\'] = ' . $cahdel_metafiles . ";\n");
		fwrite($wcnf, '$this->configs[\'cache_del_revisfiles\'] = ' . $cahdel_revisfiles . ";\n");
		fwrite($wcnf, '$this->configs[\'allow_outputinfo\'] = ' . $this->cmp_req('allow_outputinfo', 'yes', 'true', 'false') . ";\n");
		if ((intval($this->get_req('level_outputinfo','0')) >= 0) && (intval($this->get_req('level_outputinfo','0')) <= 2))
			fwrite($wcnf, '$this->configs[\'level_outputinfo\'] = ' . intval($this->get_req('level_outputinfo','0')) . ";\n");
		else
			fwrite($wcnf, '$this->configs[\'level_outputinfo\'] = 0'.";\n");
		fwrite($wcnf, "\n\n?>");
		fclose($wcnf);
	}

}

?>
