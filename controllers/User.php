<?php

require_once ("config.php");
require_once ("models/DataObject.php");
require_once ("models/Review.php");
require_once ("models/Users_Favorite.php");

class UserController extends Controller {
    public function run($id) {
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : false;
        $this->write('tab', isset($_REQUEST['tab']) ? $_REQUEST['tab'] : "");

        $reqUserId = getSessionUserId();
        $this->write('reqUserId', $reqUserId);
        $reqUser = new User();
        if ($reqUserId > 0) {
            $reqUser->findUserById($reqUserId);
            $budget = $reqUser->getBudget();
        }
        $is_runner = isset($_SESSION['is_runner']) ? $_SESSION['is_runner'] : 0;
        $is_payer = isset($_SESSION['is_payer']) ? $_SESSION['is_payer'] : 0;
        $this->write('filter', new Agency_Worklist_Filter($_REQUEST));

        // admin posting data
        if (!empty($_POST) && ($is_runner || $is_payer) && !$action) {

            $user_id = (int) $_POST['user_id'];

            if (!empty($_POST['save-salary'])) {
                $field = 'salary';
                $value = mysql_real_escape_string($_POST['value']);
            } elseif ($_POST['field'] == 'w9status') {
                $field = 'w9status';
                $value = mysql_real_escape_string($_POST['value']);
            } else {
                $field = $_POST['field'];
                $value = (int) $_POST['value'];
            }

            $updateUser = new User();

            if ($updateUser->findUserById($user_id)) {
                switch ($field) {
                    case 'salary':
                        $updateUser->setAnnual_salary($value);
                        sendJournalNotification("A new salary has been set for " . $updateUser->getNickname());
                        break;

                    case 'ispayer':
                        $updateUser->setIs_payer($value);
                        break;

                    case 'isrunner':
                        $updateUser->setIs_runner($value);
                        break;

                    case 'w9status':
                        if ($value) {
                            switch ($value) {
                                case 'approved':
                                    if (! sendTemplateEmail($updateUser->getUsername(), 'w9-approved')) { 
                                        error_log("UserController: send_email failed on w9 approved notification");
                                    }

                                    break;
                                    
                                case 'rejected':
                                    $data = array();
                                    $data['reason'] = strip_tags($_POST['reason']);
                                    
                                    if (! sendTemplateEmail($updateUser->getUsername(), 'w9-rejected', $data)) { 
                                        error_log("UserController: send_email failed on w9 rejected notification");
                                    }
                                    break;
                                
                                default:
                                    break;
                            }
                        }
                        $updateUser->setW9_status($value);
                        break;

                    case 'ispaypalverified':
                        $updateUser->setPaypal_verified($value);
                        if ($value) {
                            $updateUser->setHas_w2(false);
                        }
                        break;

                    case 'isw2employee':
                        $updateUser->setHas_w2($value);
                        if ($value) {
                            $updateUser->setPaypal_verified(false);
                            $updateUser->setw9_status('not-applicable');
                        }
                        break;

                    case 'manager':
                        $updateUser->setManager($value);
                        if ($value) {
                            $manager = new User();
                            $manager->findUserById($value);
                            // Send journal notification
                            sendJournalNotification("The manager for @" . $updateUser->getNickname() . " is now set to @" . $manager->getNickname());
                        } else {
                            sendJournalNotification("The manager for @" . $updateUser->getNickname() . " has been removed");
                        }
                        break;

                    case 'referrer':
                        $updateUser->setReferred_by($value);
                        if ($value) {
                            $referrer = new User();
                            $referrer->findUserById($value);

                            // Send journal notification
                            sendJournalNotification("The referrer for @" . $updateUser->getNickname() . " is now set to @" . $referrer->getNickname());
                        } else {
                            sendJournalNotification("The referrer for @" . $updateUser->getNickname() . " has been removed");
                        }
                        break;

                    case 'isactive':
                        $updateUser->setIs_active($value);
                        break;

                    default:
                        break;
                }

                $updateUser->save();
                $response = array(
                    'succeeded' => true,
                    'message' => 'User details updated successfully'
                );
                echo json_encode($response);
                exit(0);

            } else {
                die(json_encode(array(
                    'succeeded' => false,
                    'message' => 'Error: Could not determine the user_id'
                )));
            }
        }

        if ($id) {
            $userId = (int) $id;
        } else {
            $userId = getSessionUserId(); 
        }
        $this->write('userId', $userId);

        $user = new User();
        $user->findUserById($userId);
        $this->write('Annual_Salary', $user->getAnnual_salary() > 0 ? $user->getAnnual_salary() : '');
        $userStats = new UserStats($userId);
        $this->write('manager', $user->getManager());
        $this->write('referred_by', $user->getReferred_by());
        $hasRunJobs = $userStats->getRunJobsCount();
        $hasBeenMechanic = $userStats->getMechanicJobCount();
        $this->write('userStats', $userStats);

        if ($action =='create-sandbox') {
            $result = array();
            try {
                if (!$is_runner) {
                    throw new Exception("Access Denied");
                }
                $args = array('unixusername', 'projects');
                foreach ($args as $arg) {
                    $$arg = mysql_real_escape_string($_REQUEST[$arg]);
                }

                $projectList = explode(",",str_replace(" ","",$projects));

                // Create sandbox for user
                $sandboxUtil = new SandBoxUtil;
                $sandboxUtil->createSandbox($user -> getUsername(),
                                            $user -> getNickname(),
                                            $unixusername,
                                            $projectList);

                // If sb creation was successful, update users table
                $user->setHas_sandbox(1);
                $user->setUnixusername($unixusername);
                $user->setProjects_checkedout($projects);
                $user->save();
                // add to project_users table
                foreach ($projectList as $project) {
                    $project_id = Project::getIdFromRepo($project);
                    $user->checkoutProject($project_id);
                }
            } catch(Exception $e) {
                $result["error"] = $e->getMessage();
            }
            echo json_encode($result);
            die();
        }

        $reviewee_id = (int) $userId;
        $review = new Review();
        $this->write('reviewsList', $review->getReviews($reviewee_id,$reqUserId));
        $this->write('projects', getProjectList());
        $user_projects = $user->getProjects_checkedout();
        $this->write('has_sandbox', count($user_projects) > 0);
        $users_favorite = new Users_Favorite();
        $favorite_enabled = 1;
        $favorite = $users_favorite->getMyFavoriteForUser($reqUserId, $userId);
        if (isset($favorite['favorite'])) {
            $favorite_enabled = $favorite['favorite'];
        }
        $favorite_count = $users_favorite->getUserFavoriteCount($userId);
        $this->write('favorite_count', $favorite_count);
        $this->write('favorite_enabled', $favorite_enabled);

        $this->write('user', $user);
        $this->write('reqUser', $reqUser);
        parent::run();
    }
}
