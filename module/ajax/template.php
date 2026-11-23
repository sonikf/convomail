<?php
/* Copyright (C) 2026 DPG Supply
 * Copyright (C) 2026 Nick Fragoulis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ajax/action.php
 * \ingroup convomail
 * \brief   Mailbox actions on one or several messages. POST only, CSRF token required. Answers JSON.
 *
 * POST: token, accountid, folder, uids[] (or uid), action = read|unread|flag|unflag|move|archive|delete|junk|notjunk|log,
 *       dest (move), projectid / socid / contactids (log)
 * Nothing is ever deleted for good: "delete" moves to the Trash folder.
 * "junk" moves to the Junk folder and "notjunk" back to the Inbox, with the $Junk / $NotJunk keywords.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('/convomail/class/convomailview.class.php');
dol_include_once('/convomail/class/convomailcombined.class.php');
dol_include_once('/convomail/lib/convomail.lib.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	convomail_json(array('ok' => false, 'error' => 'POST only'), 405);
}

$langs->loadLangs(array('convomail@convomail'));

$accountid = GETPOSTINT('accountid');
$folder    = GETPOST('folder', 'restricthtml');
$action    = GETPOST('action', 'aZ09');
$uids      = array_values(array_filter(array_map('intval', GETPOST('uids', 'array'))));
if (empty($uids) && GETPOSTINT('uid') > 0) {
	$uids = array(GETPOSTINT('uid'));
}

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'account', 'read')) {
	convomail_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}
$account = convomail_get_usable_account($db, $user, $accountid);
if (!$account || $folder === '' || empty($uids)) {
	convomail_json(array('ok' => false, 'error' => 'Forbidden'), 403);
}

// Organising a shared mailbox's messages needs that level of access (read and file is enough for the rest)
if (in_array($action, array('flag', 'unflag', 'move', 'archive', 'delete', 'junk', 'notjunk'), true) && !$account->canHandle($user)) {
	convomail_json(array('ok' => false, 'error' => $langs->trans('ConvomailAccessCannotHandle')), 403);
}

$client = new ConvomailHordeMailboxClient($account);
$result = array('ok' => true, 'action' => $action, 'uids' => $uids, 'removed' => array(), 'message' => '');

// Read state or place changes: the top-bar unread count is worked out again
if (in_array($action, array('read', 'unread', 'move', 'archive', 'delete', 'junk', 'notjunk'), true)) {
	dol_include_once('/convomail/class/convomailunread.class.php');
	ConvomailUnread::clearCache($user);
}
switch ($action) {
	case 'read':
	case 'unread':
		$result['ok'] = $client->setFlag($folder, $uids, '\\Seen', $action === 'read');
		break;

	case 'flag':
	case 'unflag':
		$result['ok'] = $client->setFlag($folder, $uids, '\\Flagged', $action === 'flag');
		break;

	case 'move':
	case 'archive':
	case 'delete':
		$folders = convomail_folders($account, $client);
		$names = is_array($folders) ? array_column($folders, 'name') : array();
		if ($action === 'move') {
			$dest = GETPOST('dest', 'restricthtml');
			if (!in_array($dest, $names, true)) {
				convomail_json(array('ok' => false, 'error' => $langs->trans('ConvomailFolderUnknown')), 400);
			}
		} else {
			$role = ($action === 'archive') ? 'archive' : 'trash';
			$dest = $client->findFolderByRole($role, $action === 'archive' ? 'Archive' : 'Trash');
			if ($dest === '') {
				convomail_json(array('ok' => false, 'error' => $client->getError()), 500);
			}
			if ($dest === $folder) {
				// Already in Trash / Archive: emptying is left to the mail client, nothing is destroyed here
				convomail_json(array('ok' => false, 'error' => $langs->trans($action === 'delete' ? 'ConvomailAlreadyInTrash' : 'ConvomailAlreadyArchived')), 400);
			}
			ConvomailAccount::clearSessionCache();	// a folder may have been created
		}
		$result['ok'] = $client->moveMessages($folder, $uids, $dest);
		$result['removed'] = $result['ok'] ? $uids : array();
		$result['message'] = $result['ok'] ? $langs->trans('ConvomailMovedTo', count($uids), $dest) : '';
		break;

	case 'junk':
	case 'notjunk':
		// Spam goes to the Junk folder (made if the server has none); not spam goes back to the Inbox.
		// The keywords are set first so they travel with the message.
		$dest = ($action === 'junk') ? $client->findFolderByRole('junk', 'Junk') : 'INBOX';
		if ($dest === '') {
			convomail_json(array('ok' => false, 'error' => $client->getError()), 500);
		}
		$client->setJunkKeywords($folder, $uids, $action === 'junk');
		if ($dest === $folder) {
			$result['message'] = $langs->trans($action === 'junk' ? 'ConvomailMarkedSpam' : 'ConvomailMarkedNotSpam', count($uids));
			break;
		}
		ConvomailAccount::clearSessionCache();	// a folder may have been created, counts change
		$result['ok'] = $client->moveMessages($folder, $uids, $dest);
		$result['removed'] = $result['ok'] ? $uids : array();
		$result['message'] = $result['ok'] ? $langs->trans($action === 'junk' ? 'ConvomailMovedToSpam' : 'ConvomailMovedToInbox', count($uids)) : '';
		if ($result['ok'] && $dest === ConvomailCombined::FOLDER) {
			ConvomailCombined::clearCache($user);	// back in an Inbox: the Combined Inbox lists it again
		}
		break;

	case 'log':
		$projectid = GETPOSTINT('projectid');
		$socid = GETPOSTINT('socid');
		$contactids = array_values(array_filter(array_map('intval', explode(',', GETPOST('contactids', 'alphanohtml')))));
		$logged = array();
		foreach ($uids as $uid) {
			$message = $client->fetchMessage($folder, $uid, false);
			if ($message === false) {
				continue;
			}
			$eventid = ConvomailView::logMessage($db, $user, $account, $client, $folder, $uid, $message, $socid, $projectid, $contactids, true);
			if ($eventid < 0) {
				convomail_json(array('ok' => false, 'error' => ConvomailView::$error), 500);
			}
			$logged[$uid] = $eventid;
		}
		$result['events'] = $logged;
		$result['message'] = $langs->trans(array_filter($logged) ? 'ConvomailLoggedOk' : 'ConvomailAlreadyLogged');
		break;

	default:
		convomail_json(array('ok' => false, 'error' => 'Unknown action'), 400);
}

$client->close();
if (!$result['ok']) {
	$result['error'] = $client->getError();
	convomail_json($result, 500);
}
// The Combined Inbox shows the change on its next page without reading the mailbox again
if ($folder === ConvomailCombined::FOLDER && ($result['removed'] || in_array($action, array('read', 'unread', 'flag', 'unflag'), true))) {
	ConvomailCombined::patchCache($user, (int) $account->id, $result['removed'] ?: $uids, $result['removed'] ? 'remove' : $action);
}
convomail_json($result);

/**
 * \file    ajax/template.php
 * \ingroup convomail
 * \brief   Subject and body of an email template, with substitutions for the chosen third party / contact / project; or
 *          the list of a mailbox's Templates folder. JSON.
 *
 * GET parameters: id (template), socid, contactid, projectid, origin + originid (the record the email is written from),
 * plain (1: text for the plain editor); or accountid (templates of that mailbox, read only)
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force DoliDB $db';
'@phan-var-force Translate $langs';
'@phan-var-force User $user';

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
dol_include_once('/convomail/lib/convomail.lib.php');
dol_include_once('/convomail/class/convomailrenderer.class.php');

top_httphead('application/json');

if (!isModEnabled('convomail') || !$user->hasRight('convomail', 'message', 'send')) {
	http_response_code(403);
	print json_encode(array('ok' => false));
	exit;
}

// Templates of a mailbox (its Templates folder, as Thunderbird keeps them), for the picker of the compose page: read
// only, nothing is created here
$accountid = GETPOSTINT('accountid');
if ($accountid > 0) {
	dol_include_once('/convomail/class/hordemailboxclient.class.php');
	$account = convomail_get_usable_account($db, $user, $accountid);
	$folder = '';
	$list = array();
	if ($account && $account->canSend($user)) {
		$client = new ConvomailHordeMailboxClient($account);
		$folder = $client->findFolderByRole('templates');
		$found = ($folder !== '') ? $client->listMessages($folder, 0, 100) : false;
		/** @var array<int,array<string,mixed>> $messages */
		$messages = ($found && is_array($found['messages'] ?? null)) ? $found['messages'] : array();
		foreach ($messages as $m) {
			$list[] = array('uid' => is_numeric($m['uid'] ?? null) ? (int) $m['uid'] : 0, 'subject' => convomail_message_text($m, 'subject'));
		}
		$client->close();
	}
	convomail_json(array('ok' => true, 'folder' => $folder, 'templates' => $list));
}

$id = GETPOSTINT('id');
dol_include_once('/convomail/class/convomailtemplates.class.php');
// The record the email is written from (a document's card), if the user may see it: its template type, language and
// substitutions, as its own "Send by email" form
list($origintype, $origin) = convomail_origin_record($db, $user, GETPOST('origin', 'aZ09'), GETPOSTINT('originid'));
$socid = GETPOSTINT('socid');
$outputlangs = ConvomailTemplates::outputLangs($db, $origin, $socid);
$allowed = ConvomailTemplates::listFor($db, $user, ConvomailTemplates::typeFor($origintype), ConvomailTemplates::recipientLang($db, $origin, $socid));
if (!isset($allowed[$id])) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

// Object used for substitutions: the record, else project, then contact, then third party
$object = $origin ?: convomail_substitution_object($db, $user, GETPOSTINT('projectid'), GETPOSTINT('contactid'), $socid);
// The compose page adds the user's signature itself: the template's signature keys stay empty then
$ownsignature = getDolGlobalInt('CONVOMAIL_ADD_SIGNATURE', 1) && !empty($user->signature);
$filled = ConvomailTemplates::fill($db, $user, $id, $object, $outputlangs, !GETPOSTINT('plain'), $ownsignature);
if ($filled === null) {
	http_response_code(404);
	print json_encode(array('ok' => false));
	exit;
}

print json_encode(array('ok' => true, 'subject' => $filled['subject'], 'body' => $filled['body'], 'joinfiles' => $filled['joinfiles']));
$db->close();
