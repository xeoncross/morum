<?php

function c($key)
{
	static $config = array(
		'db_name'		=> 'morum',
		'db_user'		=> 'root',
		'db_pass'		=> '',
		'db_host'		=> 'localhost',
		'akismet_key'	=> '',
		'recaptcha_pri' => '',
		'recaptcha_pub' => '',
	);

	return $config[$key];
}

// Setup some globals
define('SYSTEM_START_TIME', microtime(TRUE));
define('SYSTEM_START_MEMORY', memory_get_usage());
define('DOMAIN', empty($_SERVER['SERVER_NAME']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']);
define('SCRIPT_URL', 'http://'. DOMAIN. $_SERVER['PHP_SELF']);
define('HASH', md5($_SERVER['SCRIPT_FILENAME']. date('D')));

extension_loaded('mbstring') OR die('The <a href="http://php.net/mbstring">mbstring</a> extension is not loaded!');

//Sending UTF-8 HTML data
header('Content-Type: text/html; charset=utf-8');

// Convert a string to UTF-8 encoding
function to_utf8($string)
{
	// If not ASCII
	if ( ! preg_match('/[^\x00-\x7F]/S', $string))
	{
		// Disable notices
		$ER = error_reporting(~E_NOTICE);

		// iconv is expensive, so it is only used when needed
		$string = iconv('UTF-8', 'UTF-8//IGNORE', $string);

		// Turn notices back on
		error_reporting($ER);
	}
	return $string;
}

// Check to see if the text is spam <http://akismet.com>
function is_spam( array $comment, $api_key)
{
	$server = array(
		'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
		'QUERY_STRING' => $_SERVER['QUERY_STRING'],
		'REMOTE_PORT' => $_SERVER['REMOTE_PORT'],
		'HTTP_ACCEPT' => $_SERVER['HTTP_ACCEPT'],
		'user_agent' => $_SERVER['HTTP_USER_AGENT'],
		'referrer' => (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : ''),
		'user_ip' => $_SERVER['REMOTE_ADDR'],
		'blog' => 'http://'. $_SERVER['SERVER_NAME'],
		'comment_type' => 'comment',
		'permalink' => 'http://'. $_SERVER['SERVER_NAME']. $_SERVER['REQUEST_URI']
	);

	// Check for spam
	return (curl_post($api_key.'.rest.akismet.com/1.1/comment-check', $comment + $server) == 'true');
}


// Print the recaptcha HTML widget <http://recaptcha.net>
function recaptcha_html($pubkey, $error = null)
{
	return '<script type="text/javascript" src="http://api.recaptcha.net/challenge?k='
	. $pubkey . ($error ? "&amp;error=$error" : '') . '"></script>

	<noscript>
  		<iframe src="http://api.recaptcha.net/noscript?k=' . $pubkey . ($error ? "&amp;error=$error" : '')
	. '" height="300" width="500" frameborder="0"></iframe><br/>
  		<textarea name="recaptcha_challenge_field" rows="3" cols="40"></textarea>
  		<input type="hidden" name="recaptcha_response_field" value="manual_challenge"/>
	</noscript>';
}


// Check that the recaptcha answer is correct <http://recaptcha.net>
function recaptcha_check($privkey)
{
	if ( ! post('recaptcha_challenge_field') OR ! post('recaptcha_response_field'))
	{
		return 'incorrect-captcha-sol';
	}

	// Compile the post fields
	$post = array(
		'privatekey' => $privkey,
		'remoteip' => $_SERVER["REMOTE_ADDR"],
		'challenge' => post('recaptcha_challenge_field'),
		'response' => post('recaptcha_response_field')
	);

	$result = explode("\n", curl_post('api-verify.recaptcha.net/verify', $post), 2);

	return (t($result[0]) === 'false') ? $result[1] : NULL;
}

// Send a POST requst using cURL
function curl_post($url, array $post = NULL)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_USERAGENT, "Testing/1.0.0 | curl_post/1.0.0");
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	return curl_exec($ch);
}

// Install the system
function install()
{
	mysql_query('CREATE TABLE IF NOT EXISTS `threads` (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `parent_id` int(10) unsigned DEFAULT NULL,
	  `title` varchar(100) CHARACTER SET utf8 NOT NULL,
	  `text` text CHARACTER SET utf8 NOT NULL,
	  `email` varchar(100) CHARACTER SET utf8 NOT NULL,
	  `ip_address` int(10) unsigned NOT NULL,
	  `timestamp` int(10) unsigned NOT NULL,
	  PRIMARY KEY (`id`),
	  KEY `parent_id` (`parent_id`),
	  KEY `ip_address` (`ip_address`)
	) ENGINE=MyISAM  DEFAULT CHARSET=utf8 ', db())
	or die('Install Failed: ' . mysql_error());
}

// Connect to the mysql database
function db()
{
	static $db = NULL;
	if( ! $db)
	{
		$db = mysql_connect(c('db_host'), c('db_user'), c('db_pass'))
		or die('Could not connect: ' . mysql_error());
		mysql_select_db(c('db_name'), $db)
		or die('Could not select database: ' . mysql_error());
		mysql_query('SET NAMES utf8', $db);
	}
	return $db;
}

// Run a query
function query($sql)
{
	//print 'Running: '. $sql. '<br />';
	if($result = mysql_query($sql, db()))
	{
		if(substr($sql, 0, 6) === 'SELECT')
		{
			if(mysql_num_rows($result) > 0)
			{
				return $result;
			}
		}
		else
		{
			return mysql_affected_rows(db());
		}
	}
	mysql_error() ? trigger_error('<b>Invalid Query:</b>' . mysql_error()) : '';
}

// Escape a mysql string
function e($string) { return '\''. mysql_real_escape_string($string, db()). '\''; }

// Run a select query
function select_query($where) { return query('SELECT * FROM `threads` WHERE '. $where); }

function get($name) { return isset($_GET[$name]) ? $_GET[$name] : NULL; }
function post($name) { return isset($_POST[$name]) ? $_POST[$name] : NULL; }
function cookie($name) { return isset($_COOKIE[$name]) ? $_COOKIE[$name] : NULL; }
function create_cookie($name, $value, $time) { setcookie($name, $value, $time, '/', NULL, 0, TRUE); }
function name($email) { return current(explode('@', $email)); }
function on($time) { return date("F j, Y, \a\\t g:i a", $time); }
function redirect($uri) { header("Location: ". $uri, TRUE, 302); exit; }
function h($string) { return htmlspecialchars($string, ENT_QUOTES, 'utf-8'); }
function t($string) { return trim( (string) $string); }
function is_valid_email($email) { return preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}$/i', $email); }
function parse($text) { return preg_replace('/(&lt;(b|i|em|pre|code|blockquote)&gt;)(.{1,5000})(&lt;\/\2&gt;)/is', '<\2>\3</\2>', nl2br(h($text))); }

// Create a gravatar <img> tag
function gravatar($email = '', $size = 80, $alt = 'Gravatar', $r = 'g')
{
	return '<img src="http://www.gravatar.com/avatar/'.md5($email)."?s=$size&d=wavatar&r=$r\" alt=\"$alt\" />";
}

// Get a thread
function get_thread($id)
{
	return ($result = select_query('`parent_id` IS NULL AND `id` = '. (int) $id)) ? mysql_fetch_object($result) : NULL;
}

// Get all posts
function get_posts($thread_id)
{
	$posts = array();
	if($result = select_query('`parent_id` = '. (int) $thread_id))
	{
		while($row = mysql_fetch_object($result)) { $posts[] = $row; }
	}
	return $posts;
}

// Insert a row
function insert($title, $text, $email, $parent_id = NULL)
{
	$sql = 'INSERT INTO `threads` (parent_id, title, text, email, ip_address, timestamp) VALUES (';
	query($sql. ($parent_id ? (int) $parent_id : 'NULL').','.e($title).','.e($text).','.e($email).','.
	e(ip2long($_SERVER['REMOTE_ADDR'])). ', '. time().')');
	return mysql_insert_id(db());
}

// Create a thread/post
function create($email, $text)
{
	// Basic error checks
	if(empty($text)) { return 'No text was given.'; }
	if(mb_strlen($email) > 100 OR ! is_valid_email($email)) { return 'Invalid Email'; }
	if(mb_strlen($text) > 60000) { return 'Text is too long!'; }
	if(post('hash') !== HASH) { return 'Invalid Hash, please re-submit the form.'; }
	if( ! get('thread') AND (! post('title') OR mb_strlen(post('title')) > 100)) { return 'Invalid Title';}

	// If we are using recaptcha to block spam
	if(c('recaptcha_pri') AND $error = recaptcha_check(c('recaptcha_pri')))
	{
		$_GET['recaptcha_error'] = $error;
		return 'Sorry, the captcha code you typed was wrong';
	}

	// If we are using akismet to block spam
	if(c('akismet_key'))
	{
		$comment = array('comment_author_email' => $email, 'comment_content' => $text);

		// Check with akismet
		if(is_spam($comment, c('akismet_key')))
		{
			return 'Sorry, the text you entered looks like spam';
		}
	}

	// Remember the users email for next time!
	create_cookie('email', $email, time() + (60 * 60 * 24 * 30));

	// If we are creating a post
	if(get('thread'))
	{
		// Create new Post
		insert(NULL, parse(to_utf8($text)), to_utf8($email), (int) get('thread'));

		// Remove text
		$_POST['text'] = '';
	}
	else
	{
		// Create new Thread
		$id = insert(h(to_utf8(post('title'))), parse(to_utf8($text)), to_utf8($email));

		// If we are creating a new thread - take the user to it!
		redirect(SCRIPT_URL. '?thread='. $id, TRUE, 302);
	}
}


/*
 * Run the script
 */

$form_error = FALSE;
$content = '';

// If posting a new thread or post
if(post('email') AND post('text'))
{
	$form_error = create(t(post('email')), t(post('text')));
}

// If loading a thread
if(get('thread'))
{
	if($thread = get_thread(get('thread')))
	{
		$content = row_html($thread);

		// Also try to get posts
		foreach(get_posts(get('thread')) as $row) $content .= row_html($row);
	}
	else
	{
		$_GET['thread'] = NULL;
		$content = '<h1>Thread not found</h1>';
	}
}
else
{
	if($result = select_query('`parent_id` IS NULL ORDER BY `timestamp` DESC'))
	{
		while($row = mysql_fetch_object($result))
		{
			$content .= '<div class="threads"><a href="?thread='. $row->id. '">'
			. $row->title. '</a> by '. name($row->email). '</div>';
		}
	}
	else
	{
		install(); // Auto-install
		$content = '<h1>No threads created yet!</h1>';
	}
}



/*
 * Editable HTML Section (below)
 */


// Print a thread/post entry
function row_html($row)
{
	// Get email and domain
	list($name, $domain) = explode('@', $row->email);

	return '<div class="post" id="post_'.$row->id.'">'
	. '<i class="meta">posted by <a href="http://'. $domain.'" rel="nofollow">'.$name.'</a>'
	. ' on '.on($row->timestamp). ($row->title ?
	'</i><h1>'.$row->title.'</h1>' : ' <a class="link" href="#post_'.$row->id.'">#</a></i>')
	. '<div class="text">'.$row->text.'</div>'
	. '</div>';
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<title><?php print DOMAIN; ?> Forums</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<link rel="stylesheet" href="style.css" type="text/css"
		media="handheld, all" />
</head>
<body>
<div id="container">

<h1 id="logo"><a href="<?php print SCRIPT_URL; ?>"><?php print DOMAIN; ?> Forums</a></h1>

<div class="content">
	<?php print '<div class="'.($form_error ? "error\">$form_error" : 
	($form_error === NULL ? "success\">Created Post!" : '">')).'</div>'; ?>
	
	<?php print $content; ?>
</div>

<div class="content">
<form method="post">
	<h2>Create new <?php print get('thread') ? 'Post' : 'Thread'; ?></h2>
	<input type="hidden" value="<?php print HASH; ?>" name="hash" />
	
	<?php if( ! get('thread')) { ?>
	<label for="title">Thread Title:</label>
	<input type="text" name="title" value="<?php print h(post('title') ? post('title') : ''); ?>" /> <br />
	<?php } ?>
	
	<label for="email">Your Email:</label>
	<input type="text" name="email" value="<?php print h(cookie('email') ? cookie('email') : post('email')); ?>" />
	<br />
	
	<textarea name="text" cols="80" rows="20"><?php print h(post('text')); ?></textarea><br />
	<?php print recaptcha_html(c('recaptcha_pub'), get('recaptcha_error')); ?><br />
	
	<input type="submit" />
</form>
</div>

<p>Copyright &copy; <?php print date('Y'). ' '. DOMAIN; ?> - <?php print '<i>rendered in '. round((microtime(true) - SYSTEM_START_TIME) * 1000)
. ' ms using '. round((memory_get_usage() - SYSTEM_START_MEMORY) / 1024). 'KB</i>';?>
</p>

</div>
</body>
</html>
