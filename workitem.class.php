<?php
//  vim:ts=4:et

/**
 * Workitem
 *
 * @package Workitem
 * @version $Id$
 */
require_once 'lib/Workitem/Exception.php';
require_once 'twitter.class.php';
/**
 * Workitem
 *
 * @package Workitem
 */
class WorkItem
{
    protected $id;
    protected $summary;
    protected $creatorId;
    protected $runnerId;
    protected $mechanicId;
    protected $status;
    protected $notes;

    protected $origStatus = null;

    public function __construct($id = null)
    {
        if (!mysql_connect(DB_SERVER, DB_USER, DB_PASSWORD)) {
            throw new Exception('Error: ' . mysql_error());
        }
        if (!mysql_select_db(DB_NAME)) {
            throw new Exception('Error: ' . mysql_error());
        }
        if ($id !== null) {
            $this->load($id);
        }
    }

    static public function getById($id)
    {
        $workitem = new WorkItem();
        $workitem->loadById($id);
        return $workitem;
    }

    public function loadById($id)
    {
        return $this->load($id);
    }

    protected function load($id = null)
    {
        if ($id === null && !$this->id) {
            throw new Workitem_Exception('Missing workitem id.');
        } elseif ($id === null) {
            $id = $this->id;
        }
        $query = "
					SELECT
					    w.id,
					    w.summary,
					    w.creator_id,
					    w.runner_id,
					    w.mechanic_id,
					    w.status,
					    w.notes
					FROM  ".WORKLIST. " as w
					WHERE w.id = '" . (int)$id . "'";
        $res = mysql_query($query);
        if (!$res) {
            throw new Workitem_Exception('MySQL error.');
        }
        $row = mysql_fetch_assoc($res);
        if (!$row) {
            throw new Workitem_Exception('Invalid workitem id.');
        }
        $this->setId($row['id'])
             ->setSummary($row['summary'])
             ->setCreatorId($row['creator_id'])
             ->setRunnerId($row['runner_id'])
	     ->setMechanicId($row['mechanic_id'])
             ->setStatus($row['status'])
             ->setNotes($row['notes']);
        return true;
    }

    public function idExists($id)
    {
        $query = '
SELECT COUNT(*)
FROM ' . WORKLIST . '
WHERE id = ' . (int)$id;
        $res = mysql_query($query);
        if (!$res) {
            throw new Workitem_Exception('MySQL error.');
        }
        $row = mysql_fetch_row($res);
        return (boolean)$row[0];
    }

    public function setId($id)
    {
        $this->id = (int)$id;
        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setSummary($summary)
    {
        $this->summary = $summary;
        return $this;
    }

    public function getSummary()
    {
        return $this->summary;
    }

    public function setCreatorId($creatorId)
    {
        $this->creatorId = (int)$creatorId;
        return $this;
    }

    public function getCreatorId()
    {
        return $this->creatorId;
    }

    public function setRunnerId($runnerId)
    {
        $this->runnerId = (int)$runnerId;
        return $this;
    }

    public function getRunnerId()
    {
        return $this->runnerId;
    }

    public function setMechanicId($mechanicId)
    {
        $this->mechanicId = (int)$mechanicId;
        return $this;
    }

    public function getMechanicId()
    {
        return $this->mechanicId;
    }

    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
        return $this;
    }

    public function getNotes()
    {
        return $this->notes;
    }
    
    public static function getStates()
    {
        $states = array();
        $query = 'SELECT DISTINCT `status` FROM `' . WORKLIST . '` LIMIT 0 , 30';
        $result = mysql_query($query);
        if ($result) {
            while ($row = mysql_fetch_assoc($result)) {
                $states[] = $row['status'];
            }
        }
        return $states;
    }

    protected function insert()
    {
        $query = "INSERT INTO ".WORKLIST." (summary, creator_id, runner_id, status, notes, created ) ".
            "VALUES (".
            "'".mysql_real_escape_string($this->getSummary())."', ".
            "'".mysql_real_escape_string($this->getCreatorId())."', ".
            "'".mysql_real_escape_string($this->getRunnerId())."', ".
            "'".mysql_real_escape_string($this->getStatus())."', ".
            "'".mysql_real_escape_string($this->getNotes())."', ".
            "NOW())";
        $rt = mysql_query($query);

        $this->id = mysql_insert_id();

        /* Keep track of status changes including the initial one */
        $status = mysql_real_escape_string($this->status);
        $query = "INSERT INTO ".STATUS_LOG." (worklist_id, status, user_id, change_date) VALUES (".$this->getId().", '$status', ".$_SESSION['userid'].", NOW())";
        mysql_unbuffered_query($query);

        if($this->getStatus() == 'BIDDING') {
        	$this->tweetNewJob();
        }

        return $rt ? 1 : 0;
    }

    protected function update()
    {
        /* Keep track of status changes */
        if ($this->origStatus != $this->status) {
            if ($this->status == 'BIDDING') {
                $this->tweetNewJob();
            }
            $status = mysql_real_escape_string($this->status);
            $query = "INSERT INTO ".STATUS_LOG." (worklist_id, status, user_id, change_date) VALUES (".$this->getId().", '$status', ".$_SESSION['userid'].", NOW())";
            mysql_unbuffered_query($query);
        }

        $query = 'UPDATE '.WORKLIST.' SET
            summary= "'. mysql_real_escape_string($this->getSummary()).'",
            notes="'.mysql_real_escape_string($this->getNotes()).'",
            status="' .mysql_real_escape_string($this->getStatus()).'",
	    runner_id="' .intval($this->getRunnerId()). '"';

        $query .= ' WHERE id='.$this->getId();
        return mysql_query($query) ? 1 : 0;
    }

    protected function tweetNewJob()
    {
    	//Get the Twitter config
    	$config = Zend_Registry::get('config')->get('twitter', array());
    	if ($config instanceof Zend_Config) {
    		$config = $config->toArray();
    	}
    	if (empty($_SERVER['HTTPS']))
    	{
    		$prefix	= 'http://';
        	$port	= ((int)$_SERVER['SERVER_PORT'] == 80) ? '' :  ":{$_SERVER['SERVER_PORT']}";
    	}
    	else
    	{
        	$prefix	= 'https://';
        	$port	= ((int)$_SERVER['SERVER_PORT'] == 443) ? '' :  ":{$_SERVER['SERVER_PORT']}";
    	}
    	$link = $prefix . $_SERVER['HTTP_HOST'] . $port . '/rw/?' . $this->id;
    	$summary_max_length = 140-strlen('New job: ')-strlen($link)-1;
    	$summary = substr($this->summary, 0, $summary_max_length);
    	//Set the twitter status for a new job
    	$twitter = new Twitter();
    	$twitter->setStatus('New job: ' . $summary . ' ' . $link, $config);
    }

    public function save()
    {
        if ($this->idExists($this->getId())) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }

    /**
     * @param int $worklist_id
     * @return array|null
     */
    public function getWorkItem($worklist_id)
    {
        $query = "SELECT w.id, w.summary,w.creator_id,w.runner_id, w.mechanic_id, u.nickname AS runner_nickname,
			  uc.nickname AS creator_nickname, w.status, w.notes
			  FROM  ".WORKLIST. " as w
			  LEFT JOIN ".USERS." as uc ON w.creator_id = uc.id 
			  LEFT JOIN ".USERS." as u ON w.runner_id = u.id
			  WHERE w.id = '$worklist_id'";
        $result_query = mysql_query($query);
        $row =  $result_query ? mysql_fetch_assoc($result_query) : null;
        return !empty($row) ? $row : null;
    }

    /**
     * @param int $worklist_id
     * @return array|null
     */
    public function getBids($worklist_id)
    {
        $query = "SELECT bids.`id`, bids.`bidder_id`, `email`, u.`nickname`, bids.`bid_amount`,
		bids.`notes`,TIMESTAMPDIFF(SECOND, bids.`bid_created`, NOW()) AS `delta`,
				TIMESTAMPDIFF(SECOND, NOW(), bids.`bid_done`) AS `future_delta`,
				DATE_FORMAT(bids.`bid_done`, '%m/%d/%Y') AS `bid_done`,
                UNIX_TIMESTAMP(`bid_done`) AS `unix_done_by`
				FROM `".BIDS. "` as bids
				INNER JOIN `".USERS."` as u on (u.id = bids.bidder_id)
				WHERE bids.worklist_id=".$worklist_id.
				" and bids.withdrawn = 0 ORDER BY bids.`id` DESC";
        $result_query = mysql_query($query);
        if($result_query) {
            $temp_array = array();
            while($row = mysql_fetch_assoc($result_query)) {
                $row['full_done_by'] = getUserTime($row['unix_done_by']);
                $temp_array[] = $row;
            }
            return $temp_array;
        } else {
            return null;
        }
    }

    public function getFees($worklist_id)
    {
        $query = "SELECT fees.`id`, fees.`amount`, u.`nickname`, fees.`desc`,fees.`user_id`, DATE_FORMAT(fees.`date`, '%m/%d/%Y') as date, fees.`paid`
			FROM `".FEES. "` as fees LEFT OUTER JOIN `".USERS."` u ON u.`id` = fees.`user_id`
			WHERE worklist_id = ".$worklist_id."
			AND fees.withdrawn = 0 ";

        $result_query = mysql_query($query);
        if($result_query){
            $temp_array = array();
            while($row = mysql_fetch_assoc($result_query)) {
                $temp_array[] = $row;
            }
            return $temp_array;
        } else {
            return null;
        }
    }

    public function placeBid($mechanic_id, $username, $itemid, $bid_amount, $done_by, $timezone, $notes)
    {
        $query =  "INSERT INTO `".BIDS."`
				(`id`, `bidder_id`, `email`,`worklist_id`,`bid_amount`,`bid_created`,`bid_done`, `notes`)
			  VALUES
				(NULL, '$mechanic_id', '$username', '$itemid', '$bid_amount', NOW(), FROM_UNIXTIME('".strtotime($done_by." ".$timezone)."'), '$notes')";

        return mysql_query($query) ? mysql_insert_id() : null;
    }
    public function updateBid($bid_id,$bid_amount, $done_by, $timezone, $notes)
    {
       if($bid_id > 0){
	    $query =  "UPDATE `".BIDS."` SET `bid_amount` = '".$bid_amount."',`bid_done` = FROM_UNIXTIME('".strtotime($done_by." ".$timezone)."'),`notes` = '".$notes."' WHERE id = '".$bid_id."'";
       mysql_query($query);
	   }
	   return $bid_id;
    }
    public function getUserDetails($mechanic_id)
    {
        $query = "SELECT nickname, username FROM ".USERS." WHERE id='{$mechanic_id}'";
        $result_query = mysql_query($query);
        return  $result_query ?  mysql_fetch_assoc($result_query) : null;
    }
// look for getOwnerSummary !!!
    public function getRunnerSummary($worklist_id)
    {
        $query = "SELECT `" . USERS . "`.`id` as id, `username`, `summary`"
		  . " FROM `" . USERS . "`, `" . WORKLIST . "`"
		  . " WHERE `" . WORKLIST . "`.`runner_id` = `" . USERS . "`.`id` AND `" . WORKLIST . "`.`id` = ".$worklist_id;
        $result_query = mysql_query($query);
        return $result_query ? mysql_fetch_assoc($result_query) : null ;
    }

    public function getSumOfFee($worklist_id)
    {
        $query = "SELECT SUM(`amount`) FROM `".FEES."` WHERE worklist_id = ".$worklist_id . " and withdrawn = 0 ";
        $result_query = mysql_query($query);
        $row = $result_query ? mysql_fetch_row($result_query) : null;
        return !empty($row) ? $row[0] : 0;
    }

    /**
     * Given a bid_id, get the corresponding worklist_id. If this is loaded compare the two ids
     * and throw an error if the don't match.  If not loaded, load the item.
     *
     * @param int $bidId
     * @return int
     */
    public function conditionalLoadByBidId($bid_id)
    {
        $query = "SELECT `worklist_id` FROM `".BIDS."` WHERE `id` = ".(int)$bid_id;
        $res = mysql_query($query);
        if (!$res || !($row = mysql_fetch_row($res))) {
            throw new Exception('Bid not found.');
        }
        if ($this->id && $this->id != $row[0]) {
            throw new Exception('Bid belongs to another work item.');
        } else if (!$this->id) {
            $this->load($row[0]);
        }
    }

    /**
     * Checks if a workitem has any accepted bids
     *
     * @param int $worklistId
     * @return boolean
     */
    public function hasAcceptedBids()
    {
        $query = "SELECT COUNT(*) FROM `".BIDS."` ".
            "WHERE `worklist_id`=".(int)$this->id." AND `accepted` = 1 AND `withdrawn` = 0";
        $res   = mysql_query($query);
        if (!$res) {
            throw new Exception('Could not retrieve result.');
        }
        $row = mysql_fetch_row($res);
        return ($row[0] > 0);
    }

    /**
     * If a given bid is accepted, the method returns TRUE.
     *
     * @param int $bidId
     * @return boolean
     */
    public function bidAccepted($bidId)
    {
        $query = 'SELECT COUNT(*) FROM `' . BIDS . '` WHERE `id` = ' . (int)$bidId . ' AND `accepted` = 1';
        $res   = mysql_query($query);
        if (!$res) {
            throw new Exception('Could not retrieve result.');
        }
        $row = mysql_fetch_row($res);
        return ($row[0] == 1);
    }

    // Accept a bid given it's Bid id
    public function acceptBid($bid_id)
    {
        $this->conditionalLoadByBidId($bid_id);
        /*if ($this->hasAcceptedBids()) {
            throw new Exception('Can not accept an already accepted bid.');
        }*/
        $res = mysql_query('SELECT * FROM `'.BIDS.'` WHERE `id`='.$bid_id);
        $bid_info = mysql_fetch_assoc($res);

        // Get bidder nickname
        $res = mysql_query("select nickname from ".USERS." where id='{$bid_info['bidder_id']}'");
        if ($res && ($row = mysql_fetch_assoc($res))) {
            $bidder_nickname = $row['nickname'];
        }
        $bid_info['nickname']=$bidder_nickname;
        
        //adjust bid_done date/time
        $prev_start = strtotime($bid_info['bid_created']);
        $new_start = strtotime(date('Y-m-d H:i:s O'));
        $end = strtotime($bid_info['bid_done']);
        $diff = $end - $prev_start;
        $bid_info['bid_done'] = strtotime('+'.$diff.'seconds');
            
        // changing mechanic of the job
        mysql_unbuffered_query("UPDATE `worklist` SET `mechanic_id` =  '".$bid_info['bidder_id']."', `status` = 'WORKING' WHERE `worklist`.`id` = ".$bid_info['worklist_id']);
        // marking bid as "accepted"
        mysql_unbuffered_query("UPDATE `bids` SET `accepted` =  1, `bid_done` = FROM_UNIXTIME('".$bid_info['bid_done']."') WHERE `id` = ".$bid_id);
        // adding bid amount to list of fees
        mysql_unbuffered_query("INSERT INTO `".FEES."` (`id`, `worklist_id`, `amount`, `user_id`, `desc`, `date`, `bid_id`) VALUES (NULL, ".$bid_info['worklist_id'].", '".$bid_info['bid_amount']."', '".$bid_info['bidder_id']."', 'Accepted Bid', NOW(), '$bid_id')");
        $bid_info['summary'] = getWorkItemSummary($bid_info['worklist_id']);
	$this -> setMechanicId($bid_info['bidder_id']);
        return $bid_info;
    }

}// end of the class
