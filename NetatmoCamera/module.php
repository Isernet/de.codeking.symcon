<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/ModuleHelper.class.php');
include_once(__ROOT__ . '/libs/php-simpleNetatmoAPI/class/splNetatmoAPI.php');

/**
 * Class NetatmoCamera
 * IP-Symcon Netatmo Camera Module
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class NetatmoCamera extends ModuleHelper
{
    protected $prefix = 'NC';

    private $email;
    private $password;
    private $client_id;
    private $client_secret;

    private $url;
    private $ip;
    private $refresh_rate;

    private $Netatmo;
    private $after_save = false;

    public $data = [];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('email', 'user@email.com');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyString('client_id', '');
        $this->RegisterPropertyString('client_secret', '');
        $this->RegisterPropertyString('url', $this->_getConnectURL());
        $this->RegisterPropertyString('ip', '');
        $this->RegisterPropertyInteger('refresh_rate', 15);

        // register timer every hour
        $register_timer = 60 * 60 * 100;
        $this->RegisterTimer('Netatmo Camera', $register_timer, $this->prefix . '_Update($_IPS[\'TARGET\']);');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // read config
        $this->ReadConfig();

        // run update
        if ($this->email && $this->password && $this->client_id && $this->client_secret) {
            $this->after_save = true;
            $this->Update();
        }
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        // controller config
        $this->email = $this->ReadPropertyString('email');
        $this->password = $this->ReadPropertyString('password');
        $this->client_id = $this->ReadPropertyString('client_id');
        $this->client_secret = $this->ReadPropertyString('client_secret');

        $this->ip = $this->ReadPropertyString('ip');
        $this->url = $this->ReadPropertyString('url');
        $this->refresh_rate = $this->ReadPropertyInteger('refresh_rate');
    }

    /**
     * read & update netatmo presence data
     */
    public function Update()
    {
        // check config
        $this->CheckConfig();

        // read cameras
        foreach ($this->Netatmo->_cameras AS $camera) {
            $local_snapshot_url = $this->_getLocalSnapshotUrl($camera['snapshot']);

            $this->data[$camera['id']] = [
                'name' => $camera['name'],
                'type' => $camera['type'],
                'status' => $camera['status'],
                'sd_status' => $camera['sd_status'],
                'alim_status' => $camera['alim_status'],
                'light_mode_status' => $camera['light_mode_status'],
                'is_local' => $camera['is_local'],
                'snapshot' => $local_snapshot_url,
                'vpn' => $camera['snapshot']
            ];
        }

        // save data
        $this->SaveData();
    }

    /**
     * save netatmo data to variables
     */
    private function SaveData()
    {
        // loop all data
        foreach ($this->data AS $device_id => $data) {
            // create category by camera id
            $category_id = $this->CreateCategoryByIdentifier($this->InstanceID, $device_id, $data['name'], 'Camera');

            // loop device data and create variables
            $position = 0;
            foreach ($data AS $key => $value) {
                // continue on vpn url
                if ($key == 'vpn') {
                    continue;
                }
                // add image grabber for snapshot
                if ($key == 'snapshot') {
                    $this->_createImageGrabber($category_id, $value, $position, $data['vpn']);
                } // create variable
                else {
                    $this->CreateVariableByIdentifier($category_id, $key, $value, $position);
                }
                $position++;
            }
        }
    }

    /**
     * check & validate config
     * @return bool
     */
    private function CheckConfig()
    {
        // read config
        $this->ReadConfig();

        // netatmo connect data
        if (!$this->email || !$this->password || !$this->client_id || !$this->client_secret) {
            $this->SetStatus(201);
            exit(-1);
        }

        // check ip address
        if (!Sys_Ping($this->ip, 5000)) {
            $this->SetStatus(205);
            exit(-1);
        }

        // check symcon url
        $url = parse_url($this->url);
        if (!$this->url || !isset($url['scheme']) || !isset($url['host'])) {
            $this->SetStatus(202);
            exit(-1);
        }

        // connect to netatmo api
        $this->Netatmo = new splNetatmoAPI($this->email, $this->password, $this->client_id, $this->client_secret);
        $connect = $this->Netatmo->connect();
        if (!$connect) {
            $this->SetStatus(203);
            IPS_LogMessage('Netatmo Camera API', $this->Netatmo->error);
            exit(-1);
        }

        // register webhook
        $hook_url = '/hook/netatmo_presence_' . $this->InstanceID;
        $this->RegisterWebhook($hook_url);
        if ($this->after_save) {
            $this->Netatmo->dropWebhook();
        }
        $webhook = $this->Netatmo->setWebhook($this->url . $hook_url);
        if (!isset($webhook['status']) || $webhook['status'] != 'ok') {
            $this->SetStatus(204);
            exit(-1);
        }

        // config is ok
        $this->SetStatus(102);

        // create webhook folder
        $this->CreateCategoryByIdentifier($this->InstanceID, 'Webhook');

        return true;
    }

    /**
     * handles webhook
     */
    protected function ProcessHookData()
    {
        // read config
        $this->ReadConfig();

        // get json data
        $jsonData = file_get_contents("php://input");

        // log data
        IPS_LogMessage('Netatmo Camera Webhook', $jsonData);

        // process webhook
        if (!is_null($jsonData) && !empty($jsonData)) {
            // check useragent instead of HTTP_X_NETATMO_SECRET, because this won't provided by symcon webhook proxy
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'POST' && stristr($_SERVER['HTTP_USER_AGENT'], 'NetatmoWebhookServer')) {
                // attach event data
                $this->data = json_decode($jsonData, true);

                // save data
                $this->SaveWebhookData();
            }
        }
    }

    /**
     * save netatmo webhook data to variables
     */
    private function SaveWebhookData()
    {
        // webhook category
        $category_id = $this->CreateCategoryByIdentifier($this->InstanceID, 'Webhook');

        // loop data and add variables
        foreach ($this->data AS $key => $value) {
            // loop arrays on arrays
            if (is_array($value)) {
                $sub_category_id = $this->CreateCategoryByIdentifier($category_id, $key);
                foreach ($value AS $k => $v) {
                    if (!is_array($v)) {
                        $this->CreateVariableByIdentifier($sub_category_id, $k, $v);
                    }
                }
            } else {
                $this->CreateVariableByIdentifier($category_id, $key, $value);
            }
        }
    }

    /**
     * extract local snapshot url by vpn url
     * @param string $url
     * @return string|bool
     */
    private function _getLocalSnapshotUrl($url = '')
    {
        $fragments = explode('/', $url);

        // extract token
        $token = false;
        foreach ($fragments AS $fragment) {
            if (strlen($fragment) == 32) {
                $token = $fragment;
                break;
            }
        }

        // return url
        return $token ? 'http://' . $this->ip . '/' . $token . '/live/snapshot_720.jpg' : false;
    }

    /**
     * create image grabb instance
     * @param $category_id
     * @param $url
     */
    private function _createImageGrabber($category_id, $url, $position, $vpn = false)
    {
        $image_grabber_guid = '{5A5D5DBD-53AB-4826-8B09-71E9E4E981E5}';

        $identifier = 'camera_' . $category_id;
        $instance_id = @IPS_GetObjectIDByIdent($identifier, $category_id);

        if ($instance_id === false) {
            $instance_id = IPS_CreateInstance($image_grabber_guid);
            IPS_SetIdent($instance_id, $identifier);
            IPS_SetName($instance_id, 'Snapshot');
            IPS_SetParent($instance_id, $category_id);
        }

        IPS_SetPosition($instance_id, $position);

        IPS_SetProperty($instance_id, 'ImageType', 1);
        IPS_SetProperty($instance_id, 'ImageAddress', $url);
        IPS_SetProperty($instance_id, 'Interval', $this->refresh_rate);
        IPS_ApplyChanges($instance_id);

        // update image manually
        if (!@IG_UpdateImage($instance_id)) {
            // delete media
            foreach (IPS_GetChildrenIDs($instance_id) AS $media_id) {
                if (IPS_MediaExists($media_id)) {
                    IPS_DeleteMedia($media_id, true);
                }
            }

            // delete instance
            IPS_DeleteInstance($instance_id);

            // fallback: try vpn url, if local image fails
            if ($vpn && $url != $vpn) {
                return $this->_createImageGrabber($category_id, $vpn, $position);
            }

            // show & log error
            echo sprintf($this->Translate('Could not find the camera picture at the given ip address %s.'), $this->ip);
            echo "\r\n" . $this->Translate('Show log message for tried url.');
            IPS_LogMessage('NetatmoCamera', sprintf('Could not find the camera picture at %s', $url));
        }
    }
}