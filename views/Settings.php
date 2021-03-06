<?php

class SettingsView extends View {
    public $title = 'Account Settings - Worklist';
    public $stylesheets = array(
        'css/settings.css'
    );

    public $scripts = array(
        'js/ajaxupload/ajaxupload.js',
        'js/sendlove.js',
        'js/utils.js',
        'js/settings.js'
    );

    public function render() {
        $this->user = $this->read('user');
        $this->userInfo = $this->read('userInfo');
        $this->userSystems = $this->read('userSystems');

        return parent::render();
    }

    public function timezoneSelectBox() {
        global $timezoneTable;
        $userInfo = $this->read('userInfo');

        $ret = '<select id="timezone" name="timezone">';
        foreach($timezoneTable as $key => $value) {
            $selected = '';
            if ($key == $userInfo['timezone']) {
                $selected = 'selected = "selected"';
            }
            $ret .= '<option value = "'.$key.'" '.$selected.'>'.$value.'</option>';
        }
        $ret .= '</select>';
        return $ret;
    }

    public function countrySelectBox() {
        global $countrylist;
        $userInfo = $this->read('userInfo');

        $ret = '<select id="country" name="country">';
        foreach($countrylist as $code => $name) {
            $selected = '';
            if ($code == $userInfo['country']) {
                $selected = 'selected = "selected"';
            }
            $ret .= '<option value = "'.$code.'" '.$selected.'>'.$name.'</option>';
        }
        $ret .= '</select>';
        return $ret;
    }

    public function picture() {
        $userInfo = $this->read('userInfo');
        return $userInfo['avatar'];

    }
    
    public function receivesBiddingJobsAlerts() {
        $userInfo = $this->read('userInfo');
        $bidding_notif = $userInfo['bidding_notif'];
        return $userInfo['bidding_notif'];
    }

    public function receivesReviewJobsAlerts() {
        $userInfo = $this->read('userInfo');
        $review_notif = $userInfo['review_notif'];
        return $userInfo['review_notif'];
    }

    public function receivesSelfActionsAlerts() {
        $userInfo = $this->read('userInfo');
        $self_notif = $userInfo['self_notif'];
        return $userInfo['self_notif'];
    }

    public function ppConfirmed() {
        return (int) isset($_REQUEST['ppconfirmed']);
    }

    public function emConfirmed() {
        return (int) isset($_REQUEST['emconfirmed']);
    }

    public function uploadApiKey() {
        return API_KEY;
    }

}
