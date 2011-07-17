<?php
require_once ('config.php');
require_once('class/Session.class.php');
require_once('class/Utils.class.php');
require_once('class/Database.class.php');
require_once("class.session_handler.php");
require_once("classes/Project.class.php");
if (!defined("ALL_ASSETS"))      define("ALL_ASSETS", "all_assets");

# krumch 20110506 #11576 - add getUserStatus to 'non-API_KEY list'
 if(! isset($_REQUEST["api_key"]) && $_REQUEST['action'] != 'getSystemDrawerJobs' && $_REQUEST['action'] != 'getUserStatus'){
    die("No api key defined.");
} else if($_REQUEST['action'] != 'getSystemDrawerJobs' && $_REQUEST['action'] != 'getUserStatus' && strcmp($_REQUEST["api_key"],API_KEY) != 0 ) {  
    die("Wrong api key provided.");
} else if(!isset($_SERVER['HTTPS']) && ($_REQUEST['action'] != 'uploadProfilePicture' && $_REQUEST['action'] != 'getSystemDrawerJobs' && $_REQUEST['action'] != 'getUserStatus')){
    die("Only HTTPS connection is accepted.");
} else if($_SERVER["REQUEST_METHOD"] != "POST"){
    die("Only POST method is allowed.");
} else {
	if(!empty($_REQUEST['action'])){
		mysql_connect (DB_SERVER, DB_USER, DB_PASSWORD);
		mysql_select_db (DB_NAME);
		switch($_REQUEST['action']){
			case 'updateuser':
				updateuser();
				break;
			case 'pushVerifyUser':
				pushVerifyUser();
				break;
			case 'login':
				loginUserIntoSession();
				break;
			case 'uploadProfilePicture':
				uploadProfilePicture();
				break;
            case 'updateProjectList':
                updateProjectList();
                break;
            case 'getSystemDrawerJobs':
                getSystemDrawerJobs();
                break;
            case 'bidNotification':
                sendBidNotification();
                break;
            case 'getUserStatus':
                require_once("update_status.php");
                print get_status(false);
                break;
            case 'processW2Masspay':
                processW2Masspay();
                break;
            case 'version':
                exec('svnversion > ver');
                break;
            default:
                die("Invalid action.");
		}
	}
}
 
/*
* Setting session variables for the user so he is logged in
*/
function loginUserIntoSession(){
    require_once("class/Database.class.php");
    $db = new Database();
    $uid = (int) $_REQUEST['user_id'];
    $sid = $_REQUEST['session_id'];
    $csrf_token = md5(uniqid(rand(), TRUE));
    
    $sql = "SELECT * FROM ".WS_SESSIONS." WHERE session_id = '".mysql_real_escape_string($sid, $db->getLink())."'";
    $res = $db->query($sql); 
	
    $session_data  ="running|s:4:\"true\";";
	$session_data .="userid|s:".strlen($uid).":\"".$uid."\";";
	$session_data .="username|s:".strlen($_REQUEST['username']).":\"".$_REQUEST['username']."\";";
	$session_data .="nickname|s:".strlen($_REQUEST['nickname']).":\"".$_REQUEST['nickname']."\";";
	$session_data .="admin|s:".strlen($_REQUEST['admin']).":\"".$_REQUEST['admin']."\";";
	$session_data .="csrf_token|s:".strlen($csrf_token).":\"".$csrf_token."\";";
		
    if(mysql_num_rows($res) > 0){
		$sql = "UPDATE ".WS_SESSIONS." SET ".
			 "session_data = '".mysql_real_escape_string($session_data,$db->getLink())."' ".
			 "WHERE session_id = '".mysql_real_escape_string($sid, $db->getLink())."';";
		$db->query($sql);
    } else {
		$expires = time() + SESSION_EXPIRE;
		$db->insert(WS_SESSIONS, 
			array("session_id" => $sid, 
				  "session_expires" => $expires,
				  "session_data" => $session_data),
			array("%s","%d","%s")
		);
    }
}

function uploadProfilePicture() {
	// check if we have a file
	if (empty($_FILES)) {
		respond(array(
			'success' => false,
			'message' => 'No file uploaded!'
		));
	}
	
	if (empty($_REQUEST['userid'])) {
		respond(array(
			'success' => false,
			'message' => 'No user ID set!'
		));
	}

     $ext = end(explode(".", $_FILES['profile']['name']));
     $tempFile = $_FILES['profile']['tmp_name'];
     $path = UPLOAD_PATH. '/' . $_REQUEST['userid'] . '.' . $ext;

     if (move_uploaded_file($tempFile, $path)) {
        $imgName = strtolower($_REQUEST['userid'] . '.' . $ext);
     	$query = 'UPDATE `'.USERS.'` SET `picture` = "' . mysql_real_escape_string($imgName) . '" WHERE `id` = ' . (int)$_REQUEST['userid'] . ' LIMIT 1;';
     	if (!mysql_query($query)) {
     		respond(array(
     			'success' => false,
     			'message' => SL_DB_FAILURE
     		));
     	} else {
     	       $file = $path;
     	       $rc = null;
               $type = null;
               if ($ext == "JPG" || $ext == "jpg" || $ext == "JPEG" || $ext == "jpeg") {
                    $rc = imagecreatefromjpeg($file);
                    $type = "image/jpeg";
               } else if ($ext == "GIF" || $ext == "gif") {
                    $rc = imagecreatefromgif($file);
                    $type = "image/gif";
               } else if ($ext == "PNG" || $ext == "png") {
                    $rc = imagecreatefrompng($file);
                    $type = "image/png";
               }
               
               // Get original width and height
               $width = imagesx($rc);
               $height = imagesy($rc);
               $cont = addslashes(fread(fopen($file,"r"),filesize($file)));
               $size = filesize($file);
               $sql = "INSERT INTO " . ALL_ASSETS . " (`app`, `content_type`, `content`, `size`, `filename`,`created`, `width`, `height`) " . "VALUES('".WORKLIST."','" . $type . "','" . $cont . "','" . $size . "','" . $imgName . "',NOW()," . $width . "," . $height . ") ".
                      "ON DUPLICATE KEY UPDATE content_type = '".$type."', content = '".$cont."', size = '".$size."', updated = NOW(), width = ".$width.", height = ".$height;
               
               $db = new Database();
               if (! $db->query($sql)) {
                    unlink($file);
                    respond(array('success' => false, 'message' => "Error with: " . $file . " Error message: " . $db->getError()));
               } else {
                    unlink($file);
                    respond(array(
                    	'success' => true, 
                    	'picture' => $imgName
                    ));
               }
     	}
     } else {
     	respond(array(
     		'success' => false,
     		'message' => 'An error occured while uploading the file, please try again!'
     	));
     }
}

function updateuser(){
    $sql = "UPDATE ".USERS." ".
           "SET ";
    $id = (int)$_REQUEST["user_id"];
    foreach($_REQUEST["user_data"] as $key => $value){
        $sql .= $key." = '".mysql_real_escape_string($value)."', ";
    }
    $sql = substr($sql,0,(strlen($sql) - 1));
    $sql .= " ".
            "WHERE id = ".$id;
    mysql_query($sql); 
}

function pushVerifyUser(){
    $user_id = intval($_REQUEST['id']);
    $sql = "UPDATE " . USERS . " SET `confirm` = '1', is_active = '1' WHERE `id` = $user_id";
    mysql_unbuffered_query($sql);
    
    respond(array('success' => false, 'message' => 'User has been confirmed!'));
}

function updateProjectList(){
$repo = basename($_REQUEST['repo']);

$project = new Project();
$project->loadByRepo($repo);
$commit_date = date('Y-m-d H:i:s');
$project->setLastCommit($commit_date);
$project->save();

}

function getSystemDrawerJobs(){
	$objectDataReviews= array();
    $sql = " SELECT	w.*, p.name as project " 
		 . " FROM  	". WORKLIST." AS w LEFT JOIN ". PROJECTS. " AS p "
		 . " ON 	(w.project_id = p.project_id) "
		 . " WHERE	w.status = 'REVIEW' "
		 ;

	if ($result = mysql_query($sql)) {
		while ($row = mysql_fetch_assoc($result)) {
			$objectDataReviews[] = $row;
		}
	// Return our data array
	} 
   	mysql_free_result($result);

	$objectDataBidding= array();
    $sql = " SELECT	w.*, p.name as project " 
		 . " FROM  	". WORKLIST." AS w LEFT JOIN ". PROJECTS. " AS p "
		 . " ON 	(w.project_id = p.project_id) "
		 . " WHERE	w.status = 'BIDDING' OR w.status = 'SUGGESTEDwithBID' ";

	if ($result = mysql_query($sql)) {
		while ($row = mysql_fetch_assoc($result)) {
			$objectDataBidding[] = $row;
		}
	// Return our data array
	} 
   	mysql_free_result($result);

    respond(array('success' => true, 'review' => $objectDataReviews, 'bidding' => $objectDataBidding));
}

function sendBidNotification() {
        include('./classes/BidNotification.class.php');
        $notify = new BidNotification();
        $notify->emailExpiredBids();
}

function processW2Masspay() {
    if (!defined('COMMAND_API_KEY') 
        or !array_key_exists('COMMAND_API_KEY',$_POST) 
        or $_POST['COMMAND_API_KEY'] != COMMAND_API_KEY)
        { die('Action Not configured'); }
        
    $con = mysql_connect(DB_SERVER,DB_USER,DB_PASSWORD);
    if (!$con) {
        die('Could not connect: ' . mysql_error());
    }
    mysql_select_db(DB_NAME, $con);
    
    $sql = " UPDATE " . FEES . " AS f, " . WORKLIST . " AS w, " . USERS . " AS u " 
         . " SET f.paid = 1, f.paid_date = NOW() "
         . " WHERE f.paid = 0 AND f.worklist_id = w.id AND w.status = 'DONE' "
         . "   AND f.withdrawn = 0 "
         . "   AND f.user_id = u.id "
         . "   AND u.has_W2 = 1 "
         . "   AND w.status_changed < CAST(DATE_FORMAT(NOW(),'%Y-%m-01') as DATE) "
         . "   AND f.date <  CAST(DATE_FORMAT(NOW() ,'%Y-%m-01') as DATE); ";
     
    // Marks all Fees from the past month as paid (for DONEd jobs)
    if (!$result = mysql_query($sql)) { error_log("mysql error: ".mysql_error()); die("mysql_error: ".mysql_error()); }
    $total = mysql_affected_rows();
    
    if( $total) {
        echo "{$total} fees were processed.";
    } else {
        echo "No fees were found!";
    }

    $sql = " UPDATE " . FEES . " AS f, " . USERS . " AS u " 
         . " SET f.paid = 1, f.paid_date = NOW() "
         . " WHERE f.paid = 0 "
         . "   AND f.bonus = 1 "
         . "   AND f.withdrawn = 0 "
         . "   AND f.user_id = u.id "
         . "   AND u.has_W2 = 1 "
         . "   AND f.date <  CAST(DATE_FORMAT(NOW() ,'%Y-%m-01') as DATE); ";
     
    // Marks all Fees from the past month as paid (for DONEd jobs)
    if (!$result = mysql_query($sql)) { error_log("mysql error: ".mysql_error()); die("mysql_error: ".mysql_error()); }
    $total = mysql_affected_rows();
    
    if( $total) {
        echo "{$total} bonuses were processed.";
    } else {
        echo "No bonuses were found!";
    }

    mysql_close($con);
}

function respond($val){
    exit(json_encode($val));
}
