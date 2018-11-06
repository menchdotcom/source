<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Bot extends CI_Controller {

    function __construct() {
        parent::__construct();

        //Load our buddies:
        $this->output->enable_profiler(FALSE);
    }


    function aa(){
        echo_json($this->Db_model->c_update_tree(7472, array(
            'c__tree_all_count' => 2,
            'c__tree_max_hours' => 1.5,
        )));
    }


    function url(){
        echo '<div><form action=""><input type="url" name="url" value="'.@$_GET['url'].'" style="width:400px;"> <input type="submit" value="Go"></form></div>';
        $curl = curl_html($_GET['url'],true);
        foreach($curl as $key=>$value){
            echo '<div style="color:'.( $key=='url_is_broken' && intval($value) ? '#FF0000' : '#000000' ).';">'.$key.': <b>'.$value.'</b></div>';
        }
    }


    function menu(){

        $res = array();

        array_push($res , $this->Comm_model->fb_graph('POST', '/me/messenger_profile', array(
            'get_started' => array(
                'payload' => 'GET_STARTED',
            ),
            'whitelisted_domains' => array(
                'http://local.mench.co',
                'https://mench.co',
                'https://mench.com',
            ),
        )));


        //Wait until Facebook pro-pagates changes of our whitelisted_domains setting:
        sleep(2);


        array_push($res , $this->Comm_model->fb_graph('POST', '/me/messenger_profile', array(
            'persistent_menu' => array(
                array(
                    'locale' => 'default',
                    'composer_input_disabled' => false,
                    'disabled_surfaces' => array('CUSTOMER_CHAT_PLUGIN'),
                    'call_to_actions' => array(
                        array(
                            'title' => '🚩 Action Plan',
                            'type' => 'web_url',
                            'url' => 'https://mench.com/my/actionplan',
                            'webview_height_ratio' => 'tall',
                            'webview_share_button' => 'hide',
                            'messenger_extensions' => true,
                        ),
                    ),
                ),
            ),
        )));

        echo_json($res);
    }

    function kr($w_id,$c_id,$fetch_outbound){
        echo_json($this->Db_model->k_recursive_fetch($w_id, $c_id, $fetch_outbound));
    }


    function test($c_id, $w_id=0, $u_id=1){
        echo_json(array(
            'ks' => $this->Db_model->k_fetch(array(
                'w_id' => $w_id,
                'cr_status >=' => 1,
                'c_status >=' => 1,
                'cr_inbound_c_id' => $c_id,
            ), array('w','cr','cr_c_out')),
            'message' => $this->Comm_model->foundation_message(array(
                'e_inbound_u_id' => 2738, //Initiated by PA
                'e_outbound_u_id' => $u_id,
                'e_outbound_c_id' => $c_id,
                'e_w_id' => $w_id,
            )),
        ));
    }

    function step_forward($w_id=0, $u_id=0){

        //The primary function that would pro-actively communicate the subscription to the user
        //If $w_id is provided it would step forward a specific subscription
        //If both $w_id and $u_id are present, it would auto register the user in an idle subscription if they are not part of it yet, and if they are, it would step them forward.

        $bot_settings = array(
            'max_per_run' => 10, //How many subscriptions to server per run (Might include duplicate w_outbound_u_id's that will be excluded)
            'talk_frequency_min' => 10, //How many minutes between each interval of talking?
        );

        //Run even minute by the cron job and determines which users to talk to...
        //Fetch all active subscriptions:
        $user_ids_served = array(); //We use this to ensure we're only service one subscription per user
        $active_subscriptions = $this->Db_model->w_fetch(array(
            'w_status' => 1, //Active subscriptions
            'u_status >=' => 0, //Active entity
            'c_status >=' => 1, //Active intent TODO Update to 2 when new intent statuses are implemented
        ), array('c','u'), array(
            'w_last_served' => 'ASC', //Fetch users who have not been served the longest, so we can pay attention to them...
        ), $bot_settings['max_per_run']);


        if(1){
            echo_json($active_subscriptions);
        } else {
            //Now lets go through the subscriptions:
            foreach($active_subscriptions as $w){

                if(in_array(intval($w['u_id']),$user_ids_served)){
                    //We do not want to handle two subscriptions from the same user, skip this for now:
                    continue;
                }

                //Add this user to the queue:
                array_push($user_ids_served, intval($w['u_id']));

                //See where this user is in their subscription:
                $ks_next = $this->Db_model->k_next_fetch($w['w_id']);

                //Update the serving timestamp:
                $this->Db_model->w_update( $w['w_id'], array(
                    'w_last_served' => date("Y-m-d H:i:s"),
                ));


                if(){
                    $this->Comm_model->send_message(array(
                        array(
                            'e_inbound_u_id' => 2738, //Initiated by PA
                            'e_outbound_u_id' => $u['u_id'],
                            'i_message' => 'I am an automated Personal Assistant so I cannot figure what you said, just yet.',
                        ),
                    ));
                }


                if(count($ks_next)==0){
                    //This should not happen, report and look into it:
                    $this->Db_model->e_create(array(
                        'e_text_value' => 'facebook_webhook() Function missing either [object] or [entry] variable.',
                        'e_json' => $json_data,
                        'e_inbound_c_id' => 8, //Platform Error
                    ));

                    continue;
                }

                //$ks_next[0]['c_outcome']
            }
        }
    }


    function facebook_webhook(){

        /*
         *
         * The master function for all inbound Facebook calls
         *
         * */

        //Facebook Webhook Authentication:
        $challenge = ( isset($_GET['hub_challenge']) ? $_GET['hub_challenge'] : null );
        $verify_token = ( isset($_GET['hub_verify_token']) ? $_GET['hub_verify_token'] : null );
        $fb_settings = $this->config->item('fb_settings');

        if ($verify_token == '722bb4e2bac428aa697cc97a605b2c5a') {
            echo $challenge;
        }

        //Fetch input data:
        $json_data = json_decode(file_get_contents('php://input'), true);

        //This is for local testing only:
        //$json_data = objectToArray(json_decode('{"object":"page","entry":[{"id":"381488558920384","time":1505007977668,"messaging":[{"sender":{"id":"1443101719058431"},"recipient":{"id":"381488558920384"},"timestamp":1505007977521,"message":{"mid":"mid.$cAAFa9hmVoehkmryMMVeaXdGIY9x5","seq":19898,"text":"Yes"}}]}]}'));


        //Do some basic checks:
        if(!isset($json_data['object']) || !isset($json_data['entry'])){
            $this->Db_model->e_create(array(
                'e_text_value' => 'facebook_webhook() Function missing either [object] or [entry] variable.',
                'e_json' => $json_data,
                'e_inbound_c_id' => 8, //Platform Error
            ));
            return false;
        } elseif(!$json_data['object']=='page'){
            $this->Db_model->e_create(array(
                'e_text_value' => 'facebook_webhook() Function call object value is not equal to [page], which is what was expected.',
                'e_json' => $json_data,
                'e_inbound_c_id' => 8, //Platform Error
            ));
            return false;
        } else {
            //Log webhook for now to resolve bug:
            $this->Db_model->e_create(array(
                'e_text_value' => count($json_data['entry']).' Entries / '.count($json_data['entry'][0]['messaging']).' Messages for 1st Entry / First sender ID: '.$json_data['entry'][0]['messaging'][0]['sender']['id'],
                'e_json' => $json_data,
                'e_inbound_c_id' => 7698, //Inboud FB Webhook
            ));
        }


        //Loop through entries:
        foreach($json_data['entry'] as $entry){

            //check the page ID:
            if(!isset($entry['id']) || !($entry['id']==$fb_settings['page_id'])){
                //This can happen for the older webhook that we offered to other FB pages:
                continue;
            } elseif(!isset($entry['messaging'])){
                $this->Db_model->e_create(array(
                    'e_text_value' => 'facebook_webhook() call missing messaging Array().',
                    'e_json' => $json_data,
                    'e_inbound_c_id' => 8, //Platform Error
                ));
                continue;
            }

            //loop though the messages:
            foreach($entry['messaging'] as $im){

                if(isset($im['read'])){

                    $u = $this->Comm_model->fb_identify_activate($im['sender']['id']);

                    //This callback will occur when a message a page has sent has been read by the user.
                    $this->Db_model->e_create(array(
                        'e_json' => $json_data,
                        'e_inbound_c_id' => 1, //Message Read
                        'e_inbound_u_id' => ( isset($u['u_id']) ? $u['u_id'] : 0 ),
                    ));

                } elseif(isset($im['delivery'])) {

                    $u = $this->Comm_model->fb_identify_activate($im['sender']['id']);

                    //This callback will occur when a message a page has sent has been delivered.
                    $this->Db_model->e_create(array(
                        'e_json' => $json_data,
                        'e_inbound_c_id' => 2, //Message Delivered
                        'e_inbound_u_id' => ( isset($u['u_id']) ? $u['u_id'] : 0 ),
                    ));

                } elseif(isset($im['referral']) || isset($im['postback'])) {

                    /*
                     * Simple difference:
                     *
                     * Handle the messaging_postbacks event for new conversations
                     * Handle the messaging_referrals event for existing conversations
                     *
                     * */

                    if(isset($im['postback'])) {

                        //The payload field passed is defined in the above places.
                        $payload = $im['postback']['payload']; //Maybe do something with this later?

                        if(isset($im['postback']['referral']) && count($im['postback']['referral'])>0){

                            $referral_array = $im['postback']['referral'];

                        } elseif($payload=='GET_STARTED') {

                            //The very first payload, set defaults:
                            $referral_array = array(
                                'ref' => 'SUBSCRIBE10_6623',
                            );

                        } else {
                            //Postback without referral!
                            $referral_array = null;
                        }

                    } elseif(isset($im['referral'])) {

                        $referral_array = $im['referral'];

                    }

                    //Did we have a ref from Messenger?
                    $ref = ( $referral_array && isset($referral_array['ref']) && strlen($referral_array['ref'])>0 ? $referral_array['ref'] : null );

                    $u = $this->Comm_model->fb_identify_activate($im['sender']['id'], $ref);

                    /*
                    if($ref){
                        //We have referrer data, see what this is all about!
                        //We expect an integer which is the challenge ID
                        $ref_source = $referral_array['source'];
                        $ref_type = $referral_array['type'];
                        $ad_id = ( isset($referral_array['ad_id']) ? $referral_array['ad_id'] : null ); //Only IF user comes from the Ad

                        //Optional actions that may need to be taken on SOURCE:
                        if(strtoupper($ref_source)=='ADS' && $ad_id){
                            //Ad clicks
                        } elseif(strtoupper($ref_source)=='SHORTLINK'){
                            //Came from m.me short link click
                        } elseif(strtoupper($ref_source)=='MESSENGER_CODE'){
                            //Came from m.me short link click
                        } elseif(strtoupper($ref_source)=='DISCOVER_TAB'){
                            //Came from m.me short link click
                        }
                    }
                    */

                    //Log primary engagement:
                    $this->Db_model->e_create(array(
                        'e_inbound_c_id' => (isset($im['referral']) ? 4 : 3), //Messenger Referral/Postback
                        'e_json' => $json_data,
                        'e_inbound_u_id' => ( isset($u['u_id']) ? $u['u_id'] : 0 ),
                    ));

                } elseif(isset($im['optin'])) {

                    $u = $this->Comm_model->fb_identify_activate($im['sender']['id']);

                    //Note: Never seen this happen yet!
                    //Log engagement:
                    $this->Db_model->e_create(array(
                        'e_json' => $json_data,
                        'e_inbound_c_id' => 5, //Messenger Optin
                        'e_inbound_u_id' => ( isset($u['u_id']) ? $u['u_id'] : 0 ),
                    ));

                } elseif(isset($im['message_request']) && $im['message_request']=='accept') {

                    //This is when we message them and they accept to chat because they had deleted Messenger or something...

                    $u = $this->Comm_model->fb_identify_activate($im['sender']['id']);

                    $this->Db_model->e_create(array(
                        'e_json' => $json_data,
                        'e_inbound_c_id' => 9, //Messenger Optin
                        'e_inbound_u_id' => ( isset($u['u_id']) ? $u['u_id'] : 0 ),
                        'e_text_value' => 'Messenger user accept to chat because they had deleted/unsubscribed before. Welcome them back personally.',
                    ));

                } elseif(isset($im['message'])) {

                    /*
                     * Triggered for both incoming and outgoing messages on behalf of our team
                     *
                     * */


                    //Is this a non loggable inbound message? If so, this has already been logged by Mench:
                    $metadata = ( isset($im['message']['metadata']) ? $im['message']['metadata'] : null ); //Send API custom string [metadata field]
                    if($metadata=='system_logged'){
                        //This is already logged! No need to take further action!
                        echo_json(array('complete'=>'yes'));
                        return false;
                        exit;
                    }


                    //Set variables:
                    $sent_from_us = ( isset($im['message']['is_echo']) ); //Indicates the message sent from the page itself
                    $user_id = ( $sent_from_us ? $im['recipient']['id'] : $im['sender']['id'] );
                    $quick_reply_payload = ( isset($im['message']['quick_reply']['payload']) && strlen($im['message']['quick_reply']['payload'])>0 ? $im['message']['quick_reply']['payload'] : null );
                    $fb_message = ( isset($im['message']['text']) ? $im['message']['text'] : null );

                    $u = $this->Comm_model->fb_identify_activate($user_id, $quick_reply_payload, ( !$sent_from_us ? $fb_message : null ));

                    $eng_data = array(
                        'e_inbound_u_id' => ( $sent_from_us || !isset($u['u_id']) ? 0 : $u['u_id'] ),
                        'e_json' => $json_data,
                        'e_text_value' => $fb_message,
                        'e_inbound_c_id' => ( $sent_from_us ? 7 : 6 ), //Message Sent/Received
                        'e_outbound_u_id' => ( $sent_from_us && isset($u['u_id']) ? $u['u_id'] : 0 ),
                    );

                    //It may also have an attachment
                    if(isset($im['message']['attachments'])){
                        //We have some attachments, lets loops through them:
                        foreach($im['message']['attachments'] as $att){

                            if(in_array($att['type'],array('image','audio','video','file'))){

                                //Indicate that we need to save this file on our servers:
                                $eng_data['e_status'] = 0;
                                //We do not save instantly as we need to respond to facebook's webhook call ASAP or else FB resend attachment!

                            } elseif($att['type']=='location'){

                                //Message with location attachment
                                //TODO test to make sure this works!
                                $loc_lat = $att['payload']['coordinates']['lat'];
                                $loc_long = $att['payload']['coordinates']['long'];
                                $eng_data['e_text_value'] .= (strlen($eng_data['e_text_value'])>0?"\n\n":'').'/attach location:'.$loc_lat.','.$loc_long;

                            } elseif($att['type']=='template'){

                                //Message with template attachment, like a button or something...
                                $template_type = $att['payload']['template_type'];

                            } elseif($att['type']=='fallback'){

                                //A fallback attachment is any attachment not currently recognized or supported by the Message Echo feature.
                                //We can ignore them for now :)

                            } else {
                                //This should really not happen!
                                $this->Db_model->e_create(array(
                                    'e_text_value' => 'facebook_webhook() Received message with unknown attachment type ['.$att['type'].'].',
                                    'e_json' => $json_data,
                                    'e_inbound_c_id' => 8, //Platform Error
                                    'e_outbound_u_id' => $eng_data['e_outbound_u_id'],
                                ));
                            }
                        }
                    }

                    //Log incoming engagement:
                    $this->Db_model->e_create($eng_data);

                } else {

                    //This should really not happen!
                    $this->Db_model->e_create(array(
                        'e_text_value' => 'facebook_webhook() received unrecognized webhook call.',
                        'e_json' => $json_data,
                        'e_inbound_c_id' => 8, //Platform Error
                    ));

                }
            }
        }
    }

}