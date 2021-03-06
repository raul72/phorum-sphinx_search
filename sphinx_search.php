<?php

/*
 * Module for using sphinx as fulltext search-engine
 * sphinx can be downloaded and installed from
 * http://sphinxsearch.com/
 *
 * mod written by Thomas Seifert (thomas@phorum.org)
 * fixes/enhancements by Maurice Makaay (maurice@phorum.org)
 * update for Phorum 5.2 by Martijn van Maasakkers (martijn@vanmaasakkers.net)
 * more fixes/improvements by Elan Ruusamäe <glen@delfi.ee>
 *
 * See Changelog for version history
 *
 */

if (!defined('PHORUM')) return;

require_once 'defaults.php';

function sphinx_search_action($arrSearch)
{
	global $PHORUM;

	// No pecl class, try php version
	if (!class_exists('SphinxClient')) {
		// loads from php include_path
		require_once 'sphinxapi.php';
	}

	// these are the index-names set in sphinx.conf - one for searching messages, the other for searching by authors only
	// both contain an additional index for the deltas - changes done after the last full reindex
	$index_name_msg = 'phorum5_msg_d phorum5_msg';
	$index_name_author = 'phorum5_author phorum5_author_d';

	// excerpts_index is just one index as that function only accepts one, it used for determining charsets / mapping tables, nothing more
	$excerpts_index = 'phorum5_msg';

	$index = $index_name_msg;

	if ($arrSearch['match_type'] == 'ALL') {
			$match_mode = SPH_MATCH_ALL;
	} elseif($arrSearch['match_type'] == 'ANY') {
			$match_mode = SPH_MATCH_ANY;
	} elseif($arrSearch['match_type'] == 'PHRASE') {
			$match_mode = SPH_MATCH_PHRASE;
	} elseif($arrSearch['match_type'] == 'AUTHOR') {
			$match_mode = SPH_MATCH_PHRASE;
			$index = $index_name_author;
	} else {
			// Return search control to Phorum in case the search type isn't handled by the module.
			return $arrSearch;
	}

	if (empty($arrSearch['search']) && !empty($arrSearch['author'])) {
		$arrSearch['search'] = $arrSearch['author'];
		$index = $index_name_author;
	}

	$sphinx = new SphinxClient();
	$sphinx->SetServer($PHORUM['mod_sphinx_search']['hostname'], $PHORUM['mod_sphinx_search']['port']);
	$sphinx->SetMatchMode($match_mode);

	// set the limits for paging
	$sphinx->SetLimits($arrSearch['offset'],$arrSearch['length']);

	// set the timeframe to search
	if ($arrSearch['match_dates'] > 0) {
		$min_ts = time() - 86400 * $arrSearch['match_dates'];
		$max_ts = time();
		$sphinx->SetFilterRange('datestamp', $min_ts, $max_ts);

	}

	// Check what forums the active Phorum user can read.
	$allowed_forums = phorum_api_user_check_access(
		PHORUM_USER_ALLOW_READ, PHORUM_ACCESS_LIST
	);

	// If the user is not allowed to search any forum or the current
	// active forum, then return the emtpy search results array.
	if (empty($allowed_forums) || ($PHORUM['forum_id'] > 0 && !in_array($PHORUM['forum_id'], $allowed_forums))) {
		$arrSearch['results'] = array();
		$arrSearch['totals'] = 0;
		$arrSearch['continue'] = 0;
		$arrSearch['raw_body'] = 1;
		return $arrSearch;
	}

	// Prepare forum_id restriction.
	$search_forums = array();
	foreach (explode(',', $arrSearch['match_forum']) as $forum_id) {
		if ($forum_id == 'ALL') {
			$search_forums = $allowed_forums;
			break;
		}
		if (isset($allowed_forums[$forum_id])) {
			$search_forums[] = $forum_id;
		}
	}
	$sphinx->SetFilter('forum_id', $search_forums);

	// set the sort-mode
	$sphinx->SetSortMode(SPH_SORT_ATTR_DESC, 'datestamp');

	// do the actual query
	$results = $sphinx->Query($arrSearch['search'], $index);

	$res = $sphinx->GetLastWarning();
	if ($res) {
		error_log("sphinx_search.php: WARNING: $res");
	}
	$res = $sphinx->GetLastError();
	if ($res) {
		error_log("sphinx_search.php: ERROR: $res");
	}

	// if no messages were found, then return empty handed.
	if (!isset($results['matches'])) {
		$arrSearch['results']=array();
		$arrSearch['totals']=0;
		$arrSearch['continue']=0;
		$arrSearch['raw_body']=1;
		return $arrSearch;
	}

	$search_msg_ids = $results['matches'];

	// get the messages we found
	$found_messages = phorum_db_get_message(array_keys($search_msg_ids), 'message_id', true);

	// sort them in reverse order of the message_id to automagically sort them by date desc this way
	krsort($found_messages);
	reset($found_messages);

	// prepare the array for building highlighted excerpts
	$docs = array();
	foreach($found_messages as $id => $data) {
		// remove hidden text in the output - only added by the hidden_msg module
		$data['body']=preg_replace("/(\[hide=([\#a-z0-9]+?)\](.+?)\[\/hide\])/is", '', $data['body']);

		$docs[] = htmlspecialchars(phorum_strip_body($data['body']));
	}

	$words = '';
	if (!empty($results['words'])) {
		$words = implode(' ', array_keys($results['words']));
	}

	$opts = array('chunk_separator'=>' [...] ');

	// build highlighted excerpts
	$highlighted = $sphinx->BuildExcerpts($docs,$excerpts_index,$words,$opts);

	$res = $sphinx->GetLastWarning();
	if ($res) {
		error_log("sphinx_search.php: WARNING: $res");
	}
	$res = $sphinx->GetLastError();
	if ($res) {
		error_log("sphinx_search.php: ERROR: $res");
	}

	$cnt=0;
	foreach ($found_messages as $id => $content) {
		$found_messages[$id]['short_body'] = $highlighted[$cnt];
		$cnt++;
	}

	$arrSearch['results'] = $found_messages;
	// we need the total results
	$arrSearch['totals'] = $results['total_found'];

	if ($arrSearch['totals'] > 1000) {
		$arrSearch['totals'] = 1000;
	}

	// don't run the default search
	$arrSearch['continue'] = 0;
	// tell it to leave the body alone
	$arrSearch['raw_body'] = 1;

	return $arrSearch;
}
