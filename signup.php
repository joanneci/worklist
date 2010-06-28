<?php
//  vim:ts=4:et

//
//  Copyright (c) 2010, LoveMachine Inc.
//  All Rights Reserved. 
//  http://www.lovemachineinc.com
//

ob_start();
include("config.php");
include("class.session_handler.php");
include_once("send_email.php");
include("timezones.php");
include("countrylist.php");
include("smslist.php");

$phone = $country = $provider = $authtype = "";
$msg="";
$to=1;
mysql_connect(DB_SERVER, DB_USER, DB_PASSWORD);
mysql_select_db(DB_NAME);

$fields_to_htmlescape = array(
        'paypal_email' => '',
        'contactway' => '', 
        'payway' => '', 
        'skills' => '', 
        'timezone' => '', 
        'int_code' => '', 
        'phone' => '', 
        'country' => '', 
        'provider' => '',
        'smsaddr' => '',
        // adds findus field to the sql query
        // 26-APR-2010 <Yani>
        'findus' => ''
      );

$fields_to_not_escape = array(
        'nickname' => '', 
        'username' => '', 
        'password' => '', 
        'confirmpassword' => '', 
        'sign_up' => '', 
        'phone_edit' => '',
        'confirm' => '',
        'paypal' => '',
        'about' => '', 
        'openid' => ''
      );
// Remove html tags from about box
if (isset($_POST['about'])) {
  $_POST['about'] = strip_tags($_POST['about']);
}

$minimal_POST = @array_intersect_key($_POST, $fields_to_not_escape + $fields_to_htmlescape);

// TODO: Code repeated from settings.php. Must be put in a library
// compute smsaddr from phone and provider
//error_log("signup: ".$minimal_POST['country'].';'.$smslist[$minimal_POST['country']][$minimal_POST['provider']]) ;
$prov_address = (isset($minimal_POST['country']) && isset($smslist[$minimal_POST['country']][$minimal_POST['provider']])) ? $smslist[$minimal_POST['country']][$minimal_POST['provider']] : '';
$country = '';
$provider = '';
$int_code = '';
$phone = isset($minimal_POST['phone']) ? preg_replace('/\D/', '', $minimal_POST['phone']) : '';
$sms_flags = 0;
$minimal_POST['smsaddr'] = str_replace('{n}', $phone, $prov_address);

$minimal_POST['username'] = $username = isset($_POST['username']) ? strtolower(trim($_POST['username'])) : '';

if(isset($minimal_POST['sign_up'])){
    require_once ("class/Error.class.php");
    require_once ("class/Utils.class.php");
    require_once ("class/CURLHandler.php");
    $error = new Error();
    if(empty($username) || (! empty($_GET['authtype']) && ($_GET['authtype'] != 'openid') && empty($minimal_POST['password'])) || (! empty($_GET['authtype']) && ($_GET['authtype'] != 'openid') && empty($minimal_POST['confirmpassword']))){
        $error->setError("Please fill all required fields.");
    }
    $about = isset($minimal_POST['about']) ? $minimal_POST['about'] : "";
    if(strlen($about) > 150){
        $error->setError("Text in field can't be more than 150 characters!");
    }

    if(! $error->getErrorFlag()){
        $send_confirm_email = false;
        $minimal_POST = array_merge($minimal_POST, array_map('htmlspecialchars', array_intersect_key($minimal_POST, $fields_to_htmlescape)));
        unset($minimal_POST['confirmpassword']);
        unset($minimal_POST['phone_edit']);
        unset($minimal_POST['sign_up']);
        $minimal_POST['sms_flags'] = (! empty($_POST['journal_alerts']) ? SMS_FLAG_JOURNAL_ALERTS : 0) | (! empty($_POST['bid_alerts']) ? SMS_FLAG_BID_ALERTS : 0);
        if(! isset($minimal_POST['paypal'])){
            $minimal_POST['paypal_email'] = '';
        }
        $params = array("username" => $minimal_POST["username"], "password" => $minimal_POST["password"], "action" => "signup", "confirm_string" => uniqid());
        if(isset($minimal_POST["nickname"])){
            $params["nickname"] = $minimal_POST["nickname"];
        }
        foreach($minimal_POST as $key => $value){
            if(! isset($params[$key])){
                $params[$key] = $value;
            }
        }
        ob_start();
        // send the request
        CURLHandler::Post(SERVER_URL . 'loginApi.php', $params, false, true);
        $result = ob_get_contents();
        ob_end_clean();
        $ret = json_decode($result);
        
        if($ret->error == 1){
            $error->setError($ret->message);
        }else{
            $newUser = array();
            foreach($_POST as $key => $value){
                if(Utils::registerKey($key)){
                    $newUser[$key] = $value;
                }
            }
            $newUser["id"] = $ret->id;
            $newUser["username"] = $ret->username;
            $newUser["nickname"] = $ret->nickname;
            $newUser["added"] = "NOW()";
            $sql = "INSERT INTO ".USERS." ";
            $columns = "(";
            $values = "VALUES(";
            foreach($newUser as $name => $value){
                $columns .= "`".$name."`,";
            	if($name == "added"){
                	$values .= "NOW(),";
                } else {
                	$values .= "'".mysql_real_escape_string($value)."',";
                }
            }
            $columns = substr($columns,0,(strlen($columns)-1));
            $columns .= ")";
            $values = substr($values,0,(strlen($values)-1));
            $values .= ")";
            $sql .= $columns." ".$values;
            $res = mysql_query($sql);
            $user_id = mysql_insert_id();
            // Email user
            $subject = "LoveMachine Worklist Registration Confirmation";
            $link = SECURE_SERVER_URL . "confirmation.php?cs=".$ret->confirm_string."&str=" . base64_encode($ret->username);
            $body = "<p>You are only one click away from completing your registration with the Worklist!</p>";
            $body .= "<p><a href=\"".$link."\">Click here to verify your email address and activate your account.</a></p>";
            $body .= "<p>Love,<br/>The LoveMachine</p>";
            $plain = "You are only one click away from completing your registration!\n\n";
            $plain .= "Click the link below or copy into your browser's window to verify your email address and activate your account.\n";
            $plain .= ${link}."\n\n";
            $plain .= "Love,\n\nThe LoveMachine</p>";
            sl_send_email($ret->username, $subject, $body, $plain);
            
            $confirm_txt = "An email containing a confirmation link was sent to your email address. Please click on that link to verify your email address and activate your account.";
        }
    }
}

// if we have openid authentication there are a few prefilled values
//Add test for GET:authtype to reduce warnings
if (!empty($_GET['authtype']) && $_GET['authtype'] == 'openid') {
  $_POST['nickname'] = rawurldecode($_GET['nickname']);
  $_POST['username'] = rawurldecode($_GET['email']);
  $country = rawurldecode($_GET['country']);
  $_POST['timezone'] = rawurldecode($_GET['timezone']);
  $authtype = 'openid';
}

/*********************************** HTML layout begins here  *************************************/

include("head.html");
?>

<!-- Add page-specific scripts and styles here, see head.html for global scripts and styles  -->

<link href="css/worklist.css" rel="stylesheet" type="text/css">
<script type="text/javascript" src="js/skills.js"></script>
<script type="text/javascript" src="js/userinfo.js"></script>
<script type="text/javascript" src="js/jquery.js"></script>
<script type="text/javascript" src="js/sendlove.js"></script>

<title>Worklist | Sign Up to the Worklist</title>
</head>

<?php if(isset($error)){?>
<?php if($error->getErrorFlag() == 1){?>
<body>
<?php } else {?>
<body onload="openbox('Signup Confirmation', 1)">
<?php }?>
<?php } else {?>
<body>
<?php }?>

<?php include("format.php"); ?>

<!-- ---------------------- BEGIN MAIN CONTENT HERE ---------------------- -->

<!-- Light Box Code Start -->
<div id="filter" onClick="closebox()"></div>
<div id="box">
<p align="center">Email Confirmation</p>
<p><font style="color: #624100; font-size: 12px; font-family: Verdana;"><?php echo isset($confirm_txt)?$confirm_txt:'' ?></font></p>
<p>&nbsp;</p>
<p align="center"><strong><a href="#" onClick="closebox()">Close</a></strong></p>
</div>
<!-- Light Box Code End -->

<h1>Create a New Account</h1>
     <?php if(isset($error)): ?>
            <?php foreach($error->getErrorMessage() as $msg):?>
              <p class="LV_invalid"><?php echo $msg; ?></p>
            <?php endforeach;?>
        <?php endif; ?>       
        <form action="" name="signup" method="post">
        <?php echo(($authtype === 'openid') ? '<input type="hidden" name="openid" value="' . rawurldecode($_GET['id']) . '" />' : '');?>
     <!-- Column containing left part of the fields -->
<div class="left-col">
<div class="LVspace">
<p><label for="username">Email *</label><br />
<input type="text" id="username" name="username" class="text-field"
	size="35"
	value="<?php echo isset($_POST['username']) ? $_POST['username'] : ""; ?>" />
</p>
</div>
<script type="text/javascript">
        var username = new LiveValidation('username', {validMessage: "Valid email address."});
        username.add( Validate.Email );
        username.add(Validate.Length, { minimum: 10, maximum: 50 } );
      </script>
            <?php if (empty($_GET['authtype']) || $_GET['authtype'] != 'openid' ) :?>
            <div class="LVspace">
<p><label>Password *<br />
<input type="password" id="password" name="password" class="text-field"
	size="35" /> </label></p>
</div>
<script type="text/javascript">
                 var password = new LiveValidation('password',{ validMessage: "You have an OK password.", onlyOnBlur: true });
                 password.add(Validate.Length, { minimum: 5, maximum: 12 } ); 
            </script>

<div class="LVspace">
<p><label>Confirm Password *<br />
<input name="confirmpassword" id="confirmpassword" type="password"
	class="text-field" size="35" /> </label></p>
</div>
<script type="text/javascript">
                 var confirmpassword = new LiveValidation('confirmpassword', {validMessage: "Passwords Match."});
                 confirmpassword.add(Validate.Custom1, { match: 'password'} ); 
            </script>
      <?php endif; ?>
            <div class="LVspace">
<p><label for="nickname">Nickname</label><br />
<input type="text" id="nickname" name="nickname" class="text-field"
	size="35"
	value="<?php echo isset($_POST['nickname']) ? $_POST['nickname'] : ""; ?>" />
</p>
</div>
<script type="text/javascript">
        var nickname = new LiveValidation('nickname', {validMessage: "You have an OK Nickname."});                                    
        nickname.add(Validate.Format, {pattern: /[@]/, negate:true});
      </script>
<?php include("sms-inc.php"); ?>

            <div class="LVspacelg" style="height: 88px"><input
	type="checkbox" id="paypal" name="paypal" value="1"
	<?php echo !empty($_POST['paypal']) ? 'checked':''; ?> /><label>&nbsp;Paypal
is available in my country</label><br />
<br />
<label>Paypal Email<br />
<input type="text" id="paypal_email" name="paypal_email"
	class="text-field" size="35"
	value="<?php echo isset($_POST['paypal_email']) ? strip_tags($_POST['paypal_email']) : ""; ?>" />
</label></div>
<script type="text/javascript">
            var username = new LiveValidation('username', {validMessage: "Valid email address."});
            username.add( Validate.Email );
            username.add(Validate.Length, { minimum: 10, maximum: 50 } );
            </script></div>
<!-- end of left-col div -->
<div class="right-col">
<div class="LVspacehg">
<p><label for="about">What do we need to know about you?</label><br />
<textarea id="about" name="about" cols="35" rows="4"><?php echo isset($_POST['about']) ? $_POST['about'] : ""; ?></textarea>
</p>
</div>
<script type="text/javascript">
        var about = new LiveValidation('about');
        about.add(Validate.Length, { minimum: 0, maximum: 150 } ); 
      </script>
<div class="LVspace">
<p><label for="findus">How did you find us?</label><br />
<input type="text" id="findus" name="findus" class="text-field"
	size="35"
	value="<?php echo isset($_POST['findus']) ? strip_tags($_POST['findus']) : ""; ?>" />
</p>
</div>
<div class="LVspace">
<p><label for="contactway">What is the preferred way to contact you?</label><br />
<input type="text" id="contactway" name="contactway" class="text-field"
	size="35"
	value="<?php echo isset($_POST['contactway']) ? strip_tags($_POST['contactway']) : ""; ?>" />
</p>
</div>

<div class="LVspace">
<p><label for="payway">What is the best way to pay you for the work you
will do?</label><br />
<input type="text" id="payway" name="payway" class="text-field"
	size="35"
	value="<?php echo isset($_POST['payway']) ? strip_tags($_POST['payway']) : ""; ?>" />
</p>
</div>

<div class="LVspace">
<p><label for="skills">Pick three skills you think are your strongest</label><br />
<input type="text" id="skills" name="skills" class="text-field"
	size="35"
	value="<?php echo isset($_POST['skills']) ? strip_tags($_POST['skills']) : ""; ?>" />
</p>
</div>

<div class="LVspace">
<p><label for="timezone">What timezone are you in?</label><br />
<select id="timezone" name="timezone">
<?php
  foreach($timezoneTable as $key => $value){
    $selected = '';
    $zone = isset($_POST['timezone']) ? $_POST['timezone'] : date("O");

    if ($key == $zone){
      $selected = 'selected = "selected"';
    }
    echo '
  <option value = "'.$key.'" '.$selected.'>'.$value.'</option>    
  ';
}
?>
        </select></p>
</div>
</div>
<!-- end of right-col div --> <br style="clear: both;" />

<p><input type="submit" value="Sign Up" alt="Sign Up" name="sign_up" /></p>

</form>


<!-- ---------------------- end MAIN CONTENT HERE ---------------------- -->
<?php include("footer.php"); ?>
