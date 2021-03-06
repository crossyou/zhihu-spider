#!/usr/bin/php
<?php
/**
 * 根据话题来抓取知乎问题 索引
 * 
 * @author  Yang,junlong at 2016-03-18 13:33:26 build.
 * @version $Id: question_sprider4topic.php 304 2016-03-19 15:54:29Z sobird $
 */

error_reporting(E_ALL);

if (function_exists( 'date_default_timezone_set' )){
    date_default_timezone_set('UTC');
}

require_once 'Http.class.php';
require_once 'Mysql.class.php';
require_once 'simple_html_dom.php';
require_once 'checkLogin.php';

//checkLogin();

$http = new Http('http://www.zhihu.com/', array(
	'request_headers' => array(
		'Cookie'=>getLoginCookie(),
		'X-Requested-With' => 'XMLHttpRequest'
	)
));
$dom = new simple_html_dom();

$http->setUseragent('Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.93 Safari/537.36');

$moniter_name = dirname(__file__).'/moniter';

$start = 0;
$offset = 0;
$_xsrf = 0;
if(!file_exists($moniter_name)) {
	file_put_contents($moniter_name, 0);
} else {
	$currentmodif = filemtime($moniter_name);
	$breakpoint = file_get_contents($moniter_name);
    $breakpoint = explode('|', $breakpoint);

    // $start = $breakpoint[0];
    // $offset = $breakpoint[1];
    // $_xsrf = $breakpoint[2];

	if((time() - $currentmodif) < 60) {
		return;
	}
}

crawl_question($start, $offset, $_xsrf);


function crawl_question ($start, $offset, $_xsrf) {
    if($offset > 0) {
        sprider_question($start, $offset, $_xsrf);

        return;
    }

    // 第一次
	global $http;
    
    $url = 'https://www.zhihu.com/log/questions';

    $http->get($url, function($body, $headers, $http)  use($offset) {
    	global $dom;

        $html = $dom->load($body);

        $questions_list = $html->find('#zh-global-logs-questions-wrap .zm-item');
        $_xsrf = $html->find('input[name="_xsrf"]', 0)->value;

        if(!$questions_list || count($questions_list) == 0) {
            return;
        }

        if($questions_list) {
        	$q_log_id = '';
            foreach ($questions_list as $question_dom) {
                $logitem_id = $question_dom->getAttribute('id');
                $title = $question_dom->find('.zm-item-title a', 0);
                $href = $title->href;

                $title = $title->text();

                $qid = substr($href, strrpos($href, '/') + 1);

                $user = $question_dom->find('div a', 0);
                $username = $user->href;
                $username = substr($username, strrpos($username, '/') + 1);
                $nickname = $user->text();

                $time = $question_dom->find('.zm-item-meta time', 0);
                $time = $time->getAttribute('datetime');

                $data = array(
                    'id' => $qid,
                    'title' => $title,
                    'username' => $username,
                    'nickname' => $nickname,
                    'ctime' => strtotime($time),
                    'date' => $time
                );

                $q_log_id = $logitem_id;
                save_question_index($data);
            }
        }

        $start = explode('-', $q_log_id);
        $start = trim($start[1]);

        sprider_question($start, $offset, $_xsrf);
    });
}


$repeat_num = 0;

function sprider_question($start, $offset = 0, $_xsrf) {
	global $http;
	global $moniter_name;

	$url = 'https://www.zhihu.com/log/questions';

	$data = array(
		'start' => $start,
		'offset' => $offset,
		'_xsrf' => $_xsrf
	);

	print_r($data);
	$breakpoint = join('|', $data);

    if($start) {
        file_put_contents($moniter_name, $breakpoint);
    }

	$http->post($url, $data, function($body, $headers, $http) use($start, $offset, $_xsrf) {
		global $dom;
        global $repeat_num;
        global $moniter_name;

		$json = json_decode($body, true);

		$msg = $json['msg'];

		$qcount = $msg[0];

		$html = $dom->load($msg[1]);
		$questions_list = $html->find('.zm-item');

		$start_id = '';
        $fail_count = 0;
        $question_count = 0;
		foreach ($questions_list as $question_dom) {
            $start_id = $question_dom->getAttribute('id');
            $title = $question_dom->find('.zm-item-title a', 0);
            $href = $title->href;

            $title = $title->text();

            $qid = substr($href, strrpos($href, '/') + 1);

            $user = $question_dom->find('div a', 0);
            $username = $user->href;
            $username = substr($username, strrpos($username, '/') + 1);
            $nickname = $user->text();

            $time = $question_dom->find('.zm-item-meta time', 0);
            $time = $time->getAttribute('datetime');

            $data = array(
            	'id' => $qid,
                'title' => $title,
                'username' => $username,
                'nickname' => $nickname,
            	'ctime' => strtotime($time),
                'date' => $time
            );

            if(!save_question_index($data)) {
                $fail_count++;
            }
            $question_count++;
        }

        if($fail_count == $question_count){
            $repeat_num++;
        } else {
            $repeat_num = 0;
        }

        // 连续50次 抓不到 就退出程序
        if($repeat_num > 50) {
            unlink($moniter_name);
            return;
        }

        $start = explode('-', $start_id);
        $start = trim($start[1]);

        $dom->clear();
        sprider_question($start, $offset + 20, $_xsrf);
	});

}

function save_question_index($data) {
	$date = $data['date'];

	$data = array(
		'id' => $data['id'],
        'title' => $data['title'],
        'username' => $data['username'],
        'nickname' => $data['nickname'],
        'ctime' => $data['ctime']
	);

	$dbh = get_dbh();
    $sql = "SELECT * FROM `question_index` WHERE `id`=".$data['id'];
    $dbh->query($sql);

    if(($dbh->num_results()) > 0){
		echo "{$data['id']} - {$date} fail...\n";

        return false;
	} else {
		
		$dbh->insert('question_index', $data);

		echo "{$data['id']} - {$date} success...\n";

        return true;
	}
}


function get_dbh() {
    static $instances = array();
    $key = getmypid();
    if (empty($instances[$key])){
        $instances[$key] = new Mysql('127.0.0.1', 'root', 'Yjl&2014', 'zhihu');
        $instances[$key]->set_char();
    }
    return $instances[$key];
}
