<?php
//  Copyright (c) 2009-2010, LoveMachine Inc.
//  All Rights Reserved.
//  http://www.lovemachineinc.com
require_once("config.php");

class session {
    // session-lifetime
    var $lifeTime;
    // mysql-handle
    var $dbHandle;

    static $objSession = null;

    static function init() {
        if (self::$objSession != null) {
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time()-42000, '/');
            }
            session_destroy();
        }

        self::$objSession = new session();
        session_set_save_handler(array(&self::$objSession,"open"),
                                 array(&self::$objSession,"close"),
                                 array(&self::$objSession,"read"),
                                 array(&self::$objSession,"write"),
                                 array(&self::$objSession,"destroy"),
                                 array(&self::$objSession,"gc"));
        session_set_cookie_params(SESSION_EXPIRE);
        session_start();
        $_SESSION['running'] = "true";
    }
    public function establishDbConnection(){
        $link = mysql_connect(DB_SERVER, DB_USER, DB_PASSWORD) or die('Could not connect: ' . mysql_error());
        if($link === null){
            die('Could not connect: ' . mysql_error());
        }
        mysql_select_db(DB_NAME, $link);
        $this->setDbHandle($link);
    }
    public function getDbHandle(){
        if($this->dbHandle === null || get_resource_type($this->dbHandle) != "mysql link"){
            $this->establishDbConnection();
        }
        if(get_resource_type($this->dbHandle) != "mysql link"){
            $this->getDbHandle();
        }
        return $this->dbHandle;
    }
    public function setDbHandle($handle){
        $this->dbHandle = $handle;
        return $this;
    }
    static function check() {
        session_set_cookie_params(SESSION_EXPIRE);
        if(empty($_SESSION['running']))
        {
            // There is no session or session expired
            session::init();
        }
        // Reset the expiration time upon page load
        if (isset($_COOKIE[session_name()]))
        {
          setcookie(session_name(), $_COOKIE[session_name()], time() + SESSION_EXPIRE, "/");
        }
    }

    function open($savePath, $sessName) {
       // get session-lifetime
       $this->lifeTime = SESSION_EXPIRE;
       return true;
    }
    function close() {
        $this->gc(SESSION_EXPIRE);
        if($this->dbHandle){
            mysql_close($this->dbHandle);
        }
        return true;
    }
    function read($sessID) {
        $db = $this->getDbHandle();
        // fetch session-data
        $res = mysql_query("SELECT session_data AS d FROM ".WS_SESSIONS."
                            WHERE session_id = '$sessID'
                            AND session_expires > ".time(),$db);
        // return data or an empty string at failure
        if($res && $row = mysql_fetch_assoc($res))
            return $row['d'];
        return "";
    }
    function write($sessID,$sessData) {
        $db = $this->getDbHandle();
        // new session-expire-time
        $newExp = time() + $this->lifeTime;
        
        // is a session with this id in the database?
        $res = mysql_query("SELECT * FROM ".WS_SESSIONS."
                            WHERE session_id = '$sessID'",$db);
        // if yes,
        if($res && mysql_num_rows($res)) {
            // ...update session-data


            mysql_query("UPDATE ".WS_SESSIONS."
                         SET session_expires = '$newExp',
                         session_data = '$sessData'
                         WHERE session_id = '$sessID'",$db);
            // if something happened, return true
            if(mysql_affected_rows($db))
                return true;
        }
        // if no session-data was found,
        else {
            // create a new row
            mysql_query("INSERT INTO ".WS_SESSIONS." (
                         session_id,
                         session_expires,
                         session_data)
                         VALUES(
                         '$sessID',
                         '$newExp',
                         '$sessData')",$db);
            // if row was created, return true
            if(mysql_affected_rows($db))
                return true;
        }
        // an unknown error occured
        return false;
    }
    function destroy($sessID) {
        $db = $this->getDbHandle();
        // delete session-data
        mysql_query("DELETE FROM ".WS_SESSIONS." WHERE session_id = '$sessID'",$db);
        // if session was deleted, return true,
        if(mysql_affected_rows($db))
            return true;
        // ...else return false
        return false;
    }
    function gc($sessMaxLifeTime) {
        $db = $this->getDbHandle();
        // delete old sessions
        mysql_query("DELETE FROM ".WS_SESSIONS." WHERE session_expires < ".time(),$db);
        // return affected rows
        return mysql_affected_rows($db);
    }
}
