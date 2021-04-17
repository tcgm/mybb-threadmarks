<?php

/**
 * @package Threadmarks
 * @version 1.0.0
 * @category MyBB 1.8.x Plugin
 * @author TCGM <tcgm@mybb.com>
 * @license MIT
 *
 */

if (!defined('IN_MYBB')) {
	die('Direct access prohibited.');
}

$plugins->add_hook('global_start', 'mybbthreadmarks_templates');
$plugins->add_hook('postbit', 'mybbthreadmarks_populate');
$plugins->add_hook('showthread_start', 'mybbthreadmarks_commit');

function mybbthreadmarks_info()
{
	global $lang;
	$lang->load('mybbthreadmarks');

	return array(
		'name' => 'MyBB Threadmarks',
		'description' => $lang->mybbthreadmarks_desc,
		'website' => 'https://github.com/tcgm/mybb-threadmarks',
		'author' => 'TCGM</a> of <a href="https://stormworkstech.com/">Stormworks Technologies</a>',
		'authorsite' => 'https://thecrazygamemaster.com',
		'version' => '1.0.0',
		'compatibility' => '18*',
		'codename' => 'mybbthreadmarks',
	);
}

function mybbthreadmarks_install()
{
	global $db, $lang;
	$lang->load('mybbthreadmarks');

	$stylesheet = @file_get_contents(MYBB_ROOT . 'inc/plugins/mybbthreadmarks/mybbthreadmarks.css');
	$attachedto = 'showthread.php';
	$name = 'mybbthreadmarks.css';
	$css = array(
		'name' => $name,
		'tid' => 1,
		'attachedto' => $db->escape_string($attachedto),
		'stylesheet' => $db->escape_string($stylesheet),
		'cachefile' => $name,
		'lastmodified' => TIME_NOW,
	);
	$db->update_query('themestylesheets', array(
		"attachedto" => $attachedto,
	), "name='{$name}'");
	$query = $db->simple_select('themestylesheets', 'sid', "tid='1' AND name='{$name}'");
	$sid = (int) $db->fetch_field($query, 'sid');
	if ($sid) {
		$db->update_query('themestylesheets', $css, "sid='{$sid}'");
	} else {
		$sid = $db->insert_query('themestylesheets', $css);
		$css['sid'] = (int) $sid;
	}
	require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";
	if (!cache_stylesheet(1, $css['cachefile'], $stylesheet)) {
		$db->update_query("themestylesheets", array('cachefile' => "css.php?stylesheet={$sid}"), "sid='{$sid}'", 1);
	}
	update_theme_stylesheet_list(1, false, true);

	// Add database column
	$db->write_query("ALTER TABLE " . TABLE_PREFIX . "posts ADD marked tinyint(1) NOT NULL DEFAULT '0'");

	// Insert Templates
	foreach (glob(MYBB_ROOT . 'inc/plugins/mybbthreadmarks/*.htm') as $template) {
		$db->insert_query('templates', array(
			'title' => $db->escape_string(strtolower(basename($template, '.htm'))),
			'template' => $db->escape_string(@file_get_contents($template)),
			'sid' => -2,
			'version' => 100,
			'dateline' => TIME_NOW,
		));
	}

	// Build Plugin Settings
	$db->insert_query("settinggroups", array(
		"name" => "mybbthreadmarks",
		"title" => "MyBB Threadmarks",
		"description" => $lang->mybbthreadmarks_desc,
		"disporder" => "9",
		"isdefault" => "0",
	));
	$gid = $db->insert_id();
	$disporder = 0;
	$mybbthreadmarks_settings = array();
	$mybbthreadmarks_opts = array(['forums', 'forumselect', '-1'], ['groups', 'groupselect', '3,4,6'], ['limit', 'numeric', '5'], ['author', 'yesno', '0'], ['force_redirect', 'yesno', '1']);

	foreach ($mybbthreadmarks_opts as $mybbthreadmarks_opt) {
		$mybbthreadmarks_opt[0] = 'mybbthreadmarks_' . $mybbthreadmarks_opt[0];
		$mybbthreadmarks_opt = array_combine(['name', 'optionscode', 'value'], $mybbthreadmarks_opt);
		$mybbthreadmarks_opt['title'] = $lang->{$mybbthreadmarks_opt['name'] . "_title"};
		$mybbthreadmarks_opt['description'] = $lang->{$mybbthreadmarks_opt['name'] . "_desc"};
		$mybbthreadmarks_opt['disporder'] = ++$disporder;
		$mybbthreadmarks_opt['gid'] = intval($gid);
		$mybbthreadmarks_settings[] = $mybbthreadmarks_opt;
	}
	$db->insert_query_multiple('settings', $mybbthreadmarks_settings);
	rebuild_settings();
}

function mybbthreadmarks_is_installed()
{
	global $db;
	//return $db->fetch_field($db->simple_select("templates", "COUNT(title) AS tpl", "title LIKE '%mybbthreadmarks%'"), "tpl");
	return $db->field_exists('marked', 'posts');
}

function mybbthreadmarks_uninstall()
{
	global $db;

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MYBB_ROOT . 'cache/themes')) as $file) {
		if (stripos($file, 'mybbthreadmarks') !== false) {
			@unlink($file);
		}
	}

	$db->delete_query('themestylesheets', "name='mybbthreadmarks.css'");
	require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";
	update_theme_stylesheet_list(1, false, true);

	$db->write_query("ALTER TABLE " . TABLE_PREFIX . "posts DROP COLUMN marked");

	foreach (glob(MYBB_ROOT . 'inc/plugins/mybbthreadmarks/*.htm') as $template) {
		$db->delete_query('templates', 'title = "' . strtolower(basename($template, '.htm')) . '"');
	}

	$db->delete_query("settings", "name LIKE '%mybbthreadmarks%'");
	$db->delete_query("settinggroups", "name='mybbthreadmarks'");

	rebuild_settings();
}

function mybbthreadmarks_activate()
{
	require MYBB_ROOT . "inc/adminfunctions_templates.php";
	foreach (['postbit', 'postbit_classic'] as $tpl) {
		find_replace_templatesets($tpl, '#button_purgespammer\']}#', 'button_purgespammer\']}<!-- mybbthreadmarks -->{\$post[\'button_mybbthreadmarks\']}<!-- /mybbthreadmarks -->');
		find_replace_templatesets($tpl, '#posturl\']}#', 'posturl\']}<!-- mybbthreadmarks -->{\$post[\'nav_mybbthreadmarks\']}<!-- /mybbthreadmarks -->');
		find_replace_templatesets($tpl, '~(.*)<\/div>~su', '${1}</div><!-- mybbthreadmarks -->{\$post[\'mybbthreadmarks\']}<!-- /mybbthreadmarks -->');
	}
};

function mybbthreadmarks_deactivate()
{
	require MYBB_ROOT . "inc/adminfunctions_templates.php";
	foreach (['postbit', 'postbit_classic'] as $tpl) {
		find_replace_templatesets($tpl, '#\<!--\smybbthreadmarks\s--\>(.*?)\<!--\s\/mybbthreadmarks\s--\>#is', '', 0);
	}
};

function mybbthreadmarks_templates()
{
	global $templatelist;

	if(defined('THIS_SCRIPT') && THIS_SCRIPT == 'showthread.php')
	{
		if(!isset($templatelist))
		{
			$templatelist = '';
		}
		else
		{
			$templatelist .= ',';
		}

		$templatelist .= 'postbit_mybbthreadmarks_button, postbit_mybbthreadmarks_bit, postbit_mybbthreadmarks, postbit_mybbthreadmarks_nav';
	}
}

function mybbthreadmarks_access($tid)
{
	global $db, $mybb;
	if ($mybb->settings['mybbthreadmarks_author']) {
		if ($mybb->user['uid'] && $mybb->user['uid'] == $db->fetch_field($db->simple_select("threads", "uid", "tid='" . $tid . "'"), "uid")) {
			return true;
		}
	}
	return !empty(array_intersect(explode(',', $mybb->settings['mybbthreadmarks_groups']), explode(',', $mybb->user['usergroup'] . ',' . $mybb->user['additionalgroups'])));
}

function mybbthreadmarks_commit()
{
	global $mybb, $lang, $db;

	$mybb->input['action'] = $mybb->get_input('action');
	if ($mybb->input['action'] == "mark" || $mybb->input['action'] == "unmark") {
		$fid = $mybb->get_input('fid', MyBB::INPUT_INT);
		$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
		$pid = $mybb->get_input('pid', MyBB::INPUT_INT);

		if (
			!$fid || !$tid || !$pid
			|| ($mybb->input['action'] == "mark" && $mybb->settings['mybbthreadmarks_limit'] <= $db->fetch_field($db->simple_select("posts", "COUNT(marked) AS mark", "marked='1' AND tid='" . $tid . "'"), "mark"))
		) {
			error_no_permission();
		}

		$allowed_forums = explode(',', $mybb->settings['mybbthreadmarks_forums']);

		if ((in_array($fid, $allowed_forums) || in_array('-1', $allowed_forums)) && mybbthreadmarks_access($tid)) {
			$lang->load('mybbthreadmarks');
			$state = $mybb->input['action'] == 'mark' ? '1' : '0';
			$db->update_query("posts", ['marked' => $state], "pid='{$pid}' AND tid='{$tid}'");

			redirect("showthread.php?tid={$tid}&amp;pid={$pid}#pid{$pid}", $lang->sprintf($lang->mark_success_message, ($mybb->input['action'] == "mark" ? $lang->mybbthreadmarks_mark : $lang->mybbthreadmarks_unmark)), '', (bool)$mybb->settings['mybbthreadmarks_force_redirect']);
		} else {
			error_no_permission();
		}
	}
}

function mybbthreadmarks_populate(&$post)
{
	global $mybb;
	$allowed_forums = explode(',', $mybb->settings['mybbthreadmarks_forums']);

	if (in_array($post['fid'], $allowed_forums) || in_array('-1', $allowed_forums)) {
		global $db, $templates, $lang, $thread, $ismod, $markedposts;
		$lang->load('mybbthreadmarks');

		static $mark_cache = null;

		//Preserve mark count to use for every post build
		if (!isset($thread['marked'])) {

			if($mark_cache === null)
			{
				$mark_cache = [];

				$tid = (int)$post['tid'];

				$where = ["tid='{$tid}'", "marked='1'"];

				$visible_states = [1];

				if(!isset($ismod))
				{
					$ismod = is_moderator($post['fid']);
				}

				if($ismod)
				{
					if(is_moderator($post['fid'], 'canviewdeleted'))
					{
						$visible_states[] = -1;
					}

					if(is_moderator($post['fid'], 'canviewunapprove'))
					{
						$visible_states[] = 0;
					}
				}

				$visible_states = implode(',', $visible_states);

				$where[] = "visible IN ({$visible_states})";

				$query = $db->simple_select(
					"posts",
					"subject, pid, uid, username, dateline",
					implode(' AND ', $where),
					array("order_by" => "pid")
				);

				while ($marked = $db->fetch_array($query)) {
					$mark_cache[] = $marked;
				}
			}

			$thread['marked'] = count($mark_cache);
		}

		if (mybbthreadmarks_access($post['tid'])) {
			//Check to see if the post is threadmarked.
            $marked['pid'] = $post['pid'];
            //If the post is threadmarked...
			if ($post['marked']) {
				$un =  'un';
                //Set the threadmark button to display "Unthreadmark".
				$lang->postbit_marktext = $lang->mybbthreadmarks_unmark;
				$marktitle = $lang->sprintf($lang->postbit_marktitle, $lang->mybbthreadmarks_unmark);
                //Push the threadmarks button template and the nav template to the post.
				eval("\$post['button_mybbthreadmarks'] = \"" . $templates->get("postbit_mybbthreadmarks_button") . "\";");
				$marktitle = $lang->sprintf($post['subject']);
                $currentid = $post['pid'];
                
                $count = 0;
                foreach ((array)$mark_cache as $marked) {

                    $checkid = $marked['pid'];
                    
                    ++$count;
                    if($checkid == $currentid) {
                        break;
                    }
                }
                
                $currentindex = $count;//array_search($currentpid, $mark_cache);
                $backid = $mark_cache[($currentindex-2)]['pid'];
                $foreid = $mark_cache[($currentindex)]['pid'];
                $backhidden = '';
                if(empty($backid) || $backid == $currentid) { $backhidden = 'hidden'; }
                $forehidden = '';
                if(empty($foreid) || $foreid == $currentid) { $forehidden = 'hidden'; }
                //echo $post['pid'];
                //echo $currentindex . "," . $backid . "(" . ((string)($currentindex-1)) . ")," . $foreid . "(" . ((string)($currentindex+1)) . ")|";
                //echo '<pre>'; print_r($mark_cache); echo '</pre>';
                //Push the threadmarks nav template to the post.
				eval("\$post['nav_mybbthreadmarks'] = \"" . $templates->get("postbit_mybbthreadmarks_nav") . "\";");
			}
            //If the post is NOT threadmarked...
            else if ($thread['marked'] < $mybb->settings['mybbthreadmarks_limit']) {
				$un =  '';
                //Set the threadmark button to display "Threadmark".
				$lang->postbit_marktext = $lang->mybbthreadmarks_mark;
				$marktitle = $lang->sprintf($lang->postbit_marktitle, $lang->mybbthreadmarks_mark);
                //Push the threadmarks button template to the post.
				eval("\$post['button_mybbthreadmarks'] = \"" . $templates->get("postbit_mybbthreadmarks_button") . "\";");
			}
		}

        //If the post is the first post in the thread...
		if ($post['pid'] == $thread['firstpost'] && $thread['marked']) {
			$limit = (int)$mybb->settings['mybbthreadmarks_limit'];
			$mybbthreadmarks_bits = "";

			$count = 0;
			foreach ((array)$mark_cache as $marked) {
				$mybbthreadmarks_poster = build_profile_link($marked['username'], $marked['uid']);
				$un =  'un';
				$lang->postbit_marktext = '✖';
				$mybbthreadmarks_stamp = my_date('relative', $marked['dateline']);
				$marktitle = $lang->sprintf($lang->postbit_marktitle, $lang->mybbthreadmarks_unmark);
				if (mybbthreadmarks_access($post['tid']))
                {                
                    //Push the threadmarks button template to the post.
                    eval("\$mybbthreadmarks_unmark = \"" . $templates->get("postbit_mybbthreadmarks_button") . "\";");
                }
                //Push the threadmark entry template to the threadmarks list.
				eval("\$mybbthreadmarks_bits .= \"" . $templates->get("postbit_mybbthreadmarks_bit") . "\";");

				++$count;
				if($count >= $limit)
				{
					break;
				}
			}

            //Push the threadmarks list template to the post.
			eval("\$post['mybbthreadmarks'] = \$markedposts = \"" . $templates->get("postbit_mybbthreadmarks") . "\";");
		}
	}
};
