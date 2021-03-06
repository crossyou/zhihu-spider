<?php
/**
 * 抓取用户信息
 * 
 * @author  Yang,junlong at 2016-03-29 16:02:11 build.
 * @version $Id$
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
		'Cookie'=>getLoginCookie()
	)
));
$dom = new simple_html_dom();

$http->setUseragent('Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.93 Safari/537.36');

worker(4);

function worker ($process_count = 8) {
    // 开启8个进程
    for ($i = 1; $i <= $process_count; $i++) {
        try {
            $pid = pcntl_fork();
            if ($pid == -1) {
                echo "Could not fork!\n";
                exit(1);
            }
            if (!$pid) {
                $_pid = getmypid();
                echo "child process $_pid running\n";

                while(true) {
                    sprider_people();
                }

                exit($_pid);
            }
        } catch(Exception $e) {

        }
    }

    while (pcntl_waitpid(0, $status) != -1) {
        $status = pcntl_wexitstatus($status);
        echo "Child $status completed\n";
    }
}

function sprider_people() {
	$dbh = get_dbh();
    $username = get_people_queue();

    if(!$username) {
        return;
    }

    echo "sprider: {$username} start...\n";

    crawl_people($username);
}
//crawl_people('kaifulee');

//crawl_people('yu-ling-nuo');
function crawl_people($username) {
	global $http;

	$url = 'https://www.zhihu.com/people/'.$username.'/about';

	$http->get($url, function($body, $headers, $http) use($username) {
    	global $dom;
        $html = $dom->load($body);
        $data = array();
        $data['username'] = $username;

        $profile_header = $html->find('.zm-profile-header-main', 0);

        if(!$profile_header) {
            return;
        }

        $avatar = $profile_header->find('.Avatar', 0);
        if($avatar) {
            $avatar = $avatar->src;
            $avatar = substr($avatar, strrpos($avatar, '/') + 1);
            $avatar = explode('_', $avatar);
            $avatar = $avatar[0];
            $data['avatar'] = addslashes($avatar);
        }

        $weibo = $profile_header->find('.zm-profile-header-user-weibo', 0);
        if($weibo) {
        	$weibo = $weibo->href;
        	$data['weibo'] = addslashes($weibo);
        }

        $location = $profile_header->find('.location', 0);
        if($location) {
        	$location = $location->title;
        	$data['location'] = addslashes($location);
        }

        $business = $profile_header->find('.business', 0);
        if($business) {
        	$business = $business->title;
        	$data['business'] = addslashes($business);
        }

        $gender_male = $profile_header->find('.gender .icon-profile-male', 0);
        $gender_female = $profile_header->find('.gender .icon-profile-female', 0);
        if($gender_male) {
        	$data['gender'] = addslashes('m');
        } else if($gender_female) {
        	$data['gender'] = addslashes('f');
        } else {
        	$data['gender'] = addslashes('o');
        }

        $employment = $profile_header->find('.employment', 0);
        if($employment) {
        	$employment = $employment->title;
        	$data['employment'] = addslashes($employment);
        }

        $position = $profile_header->find('.position', 0);
        if($position) {
        	$position = $position->title;
        	$data['position'] = addslashes($position);
        }

        $university = $profile_header->find('.education', 0);
        if($university) {
        	$university = $university->title;
        	$data['university'] = addslashes($university);
        }

        $major = $profile_header->find('.education-extra', 0);
        if($major) {
        	$major = $major->title;
        	$data['major'] = addslashes($major);
        }

        $nickname = $profile_header->find('.name', 0);
        if($nickname) {
        	$nickname = $nickname->text();
        	$data['nickname'] = addslashes($nickname);
        }

        $headline = $profile_header->find('.bio', 0);
        if($headline) {
        	$headline = $headline->text();
        	$data['headline'] = addslashes($headline);
        }

        $profile = $profile_header->find('.description .content', 0);
        if($profile) {
        	$profile = $profile->text();
        	$data['profile'] = addslashes($profile);
        }

        $following = $html->find('.zm-profile-side-following', 0);
        $followees = $following->children(0);
        $followers = $following->children(1);
        $followees = $followees->find('strong', 0);
        $followers = $followers->find('strong', 0);

        if($followees) {
        	$followees = $followees->text();
        	$data['followees'] = addslashes($followees);
        }

        if($followers) {
        	$followers = $followers->text();
        	$data['followers'] = addslashes($followers);
        }

        // 关注的 专栏数
        $columns = $html->find('.zm-profile-side-section-title .zg-link-litblue strong', 0);
        if($columns) {
        	$columns = $columns->text();
        	$columns = explode(' ', $columns);
        	$columns = $columns[0];
        	$data['columns'] = addslashes($columns);
        }

        // 关注的 话题数
        $topics = $html->find('.zm-profile-side-section-title .zg-link-litblue strong', 1);
        if($topics) {
        	$topics = $topics->text();
        	$topics = explode(' ', $topics);
        	$topics = $topics[0];
        	$data['topics'] = addslashes($topics);
        }

        // 个人主页 浏览数
        $visits = $html->find('.zm-profile-side-section .zg-gray-normal strong', 0);
        if($visits) {
        	$visits = $visits->text();
        	$data['visits'] = addslashes($visits);
        }

        // 获得的 赞同数
        $agrees = $html->find('.zm-profile-header-user-agree strong', 0);
        if($agrees) {
        	$agrees = $agrees->text();
        	$data['agrees'] = addslashes($agrees);
        }

        // 获得的 感谢数
        $thanks = $html->find('.zm-profile-header-user-thanks strong', 0);
        if($thanks) {
        	$thanks = $thanks->text();
        	$data['thanks'] = addslashes($thanks);
        }

        
        $profile_navbar = $html->find('.profile-navbar .item .num');

        // 提问数
        $asks = $profile_navbar[0];
        if($asks) {
        	$asks = $asks->text();
        	$data['asks'] = addslashes($asks);
        }

        // 回答数
        $answers = $profile_navbar[1];
        if($answers) {
        	$answers = $answers->text();
        	$data['answers'] = addslashes($answers);
        }

        // 文章数
        $posts = $profile_navbar[2];
        if($posts) {
        	$posts = $posts->text();
        	$data['posts'] = addslashes($posts);
        }

        // 收藏数
        $collections = $profile_navbar[3];
        if($collections) {
        	$collections = $collections->text();
        	$data['collections'] = addslashes($collections);
        }

        // 公共编辑数
        $logs = $profile_navbar[4];
        if($logs) {
        	$logs = $logs->text();
        	$data['logs'] = addslashes($logs);
        }

        $details_reputation = $html->find('.zm-profile-details-reputation .zm-profile-module-desc span strong');

        // 被收藏数
        $favorites = $details_reputation[2];
        if($favorites) {
        	$favorites = $favorites->text();
        	$data['favorites'] = addslashes($favorites);
        }

        // 被分享数
        $shares = $details_reputation[3];
        if($shares) {
        	$shares = $shares->text();
        	$data['shares'] = addslashes($shares);
        }

        $marks = $html->find('.zm-profile-header-marked .zg-link-litblue-normal', 0);
        if($marks) {
            $marks = $marks->text();
            $marks = explode(' ', $marks);

            $marks = $marks[0];
            $data['marks'] = addslashes($marks);
        }


        $profile_details = $html->find('.zm-profile-details .zm-profile-module');

        // 职业经历
        $companys = $profile_details[1];
        $companys = $companys->find('.zm-profile-details-items .ProfileItem');
        if($companys) {
        	$_companys = array();
        	foreach ($companys as $company) {
        		$_inf = array();
        		$tmp = $company->find('.ProfileItem-text', 0)->children();

                $i = 0;
                foreach ($tmp as $key => $value) {
                    $value = trim($value->text());
                    if($value == '.') {
                        continue;
                    }

                    if($i == 0) {
                        $_inf['employment'] = $value;
                    } else {
                        $_inf['position'] = $value;
                    }

                    $i++;
                }

        		$_companys[] = $_inf;
        	}

        	$data['companys'] = encode_json($_companys);
        }

        // 居住信息
        $residences = $profile_details[2];
        $residences = $residences->find('.zm-profile-details-items .ProfileItem');
        if($residences) {
            $_residences = array();
            foreach ($residences as $residence) {
                $_inf = array();
                $tmp = $residence->find('.ProfileItem-text', 0)->children();

                $i = 0;
                foreach ($tmp as $key => $value) {
                    $value = trim($value->text());
                    if($value == '.') {
                        continue;
                    }

                    if($i == 0) {
                        $_inf['cityname'] = $value;
                    } else {
                        
                    }

                    $i++;
                }

                $_residences[] = $_inf;
            }

            $data['residences'] = encode_json($_residences);
        }

        // 教育经历
        $educations = $profile_details[3];
        $educations = $educations->find('.zm-profile-details-items .ProfileItem');
        if($educations) {
            $_educations = array();
            foreach ($educations as $education) {
                $_inf = array();
                $tmp = $education->find('.ProfileItem-text', 0)->children();

                $i = 0;
                foreach ($tmp as $key => $value) {
                    $value = trim($value->text());
                    if($value == '.') {
                        continue;
                    }

                    if($i == 0) {
                        $_inf['university'] = $value;
                    } else {
                        $_inf['major'] = $value;
                    }

                    $i++;
                }

                $_educations[] = $_inf;
            }

            $data['educations'] = encode_json($_educations);
        }
        save_people_info($data);
    });
}

function get_people_queue($count = 10000) {
	$dbh = get_dbh();
    $redis = get_redis();

    $redis_key = 'zhihu_people2info_queue';

    // 如果队列为空, 从数据库取一些
    if (!$redis->lsize($redis_key)) {
        if(file_exists($redis_key)) {
            return $redis->lpop($redis_key);
        } else {
            file_put_contents($redis_key, '1');
        }
        
        //$sql = "Select `id`, `index_uptime` From `topic_index` Order By `index_uptime` Asc Limit {$count}";
        $sql = "Select `username` From `people_index` WHERE  `info_uptime`=0  Limit {$count}";
        $result = $dbh->query($sql);
        $rows = $dbh->fetch_all($result);

        if(!$rows) {
            die();
        }

        $rows = array_reverse($rows);

        foreach ($rows as $row) {
            $redis->lpush($redis_key, $row['username']);
        }

        if(file_exists($redis_key)) {
            unlink($redis_key);
        }
    }
    // 从队列中取出一条数据
    return $redis->lpop($redis_key);
}

function save_people_info($data) {
	$dbh = get_dbh();
    $sql = "SELECT * FROM `people` WHERE `username`='".$data['username']."'";
    $dbh->query($sql);

    if(($dbh->num_results()) > 0){
		echo "{$data['username']} fail...\n";

		return false;
	} else {
		$data['ctime'] = time();
		$dbh->insert('people', $data);

		echo "{$data['username']} success...\n";

        $time = time();
        $dbh->update('people_index', array('info_uptime'=>$time), array('username' => $data['username']));
		return true;
	}
}

//util~~

function get_dbh() {
    static $instances = array();
    $key = getmypid();
    if (empty($instances[$key])){
        $instances[$key] = new Mysql('127.0.0.1', 'root', 'Yjl&2014', 'zhihu');
        $instances[$key]->set_char();
    }
    return $instances[$key];
}

function get_redis() {
    static $instances = array();
    $key = getmypid();
    if (empty($instances[$key])){
        $instances[$key] = new Redis();
        $instances[$key]->connect('127.0.0.1', '6379');
    }
    return $instances[$key];
}

function encode_json($str) {
    return urldecode(json_encode(url_encode($str)));
}

function url_encode($str) {
    if(is_array($str)) {
        foreach($str as $key=>$value) {
            $str[urlencode($key)] = url_encode($value);
        }
    } else {
        $str = urlencode($str);
    }

    return $str;
}