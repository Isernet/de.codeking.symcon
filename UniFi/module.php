<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/ModuleHelper.class.php');
require_once(__ROOT__ . '/libs/UniFi-API-browser/vendor/autoload.php');

/**
 * Class Unifi
 * Symcon Unifi Module
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class Unifi extends ModuleHelper
{
    protected $prefix = 'UNIFI';

    private $user;
    private $password;
    private $url;

    public $data = [];
    public $devices = [];
    public $presence_enabled = false;
    public $presence_online_time;

    private $data_mapper = [
        'wan_ip' => 'IP',
        'xput_down' => 'Download',
        'xput_up' => 'Upload',
        'num_guest' => 'Guests Online',
        'latency' => 'Latency'
    ];

    protected $profile_mappings = [
        'Latency' => 'Latency',
        'Upload' => 'MBit.Upload',
        'Download' => 'MBit.Download'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('user', '');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyString('url', 'https://192.168.1.10:8443');

        // register presence properties
        $this->RegisterPropertyBoolean('presence_enabled', false);
        $this->RegisterPropertyInteger('presence_online_time', 15);

        $this->RegisterPropertyString('device_1', 'Example iPhone');
        $this->RegisterPropertyString('device_2', '');
        $this->RegisterPropertyString('device_3', '');
        $this->RegisterPropertyString('device_4', '');
        $this->RegisterPropertyString('device_5', '');

        $this->RegisterPropertyString('mac_1', '00:0A:95:9D:68:16');
        $this->RegisterPropertyString('mac_2', '');
        $this->RegisterPropertyString('mac_3', '');
        $this->RegisterPropertyString('mac_4', '');
        $this->RegisterPropertyString('mac_5', '');

        // register data timer every 10 minutes
        $register_timer = 60 * 10 * 100;
        $this->RegisterTimer('ReadUnifi', $register_timer, $this->prefix . '_Update($_IPS[\'TARGET\']);');

        // register presence timer every minute
        $this->RegisterTimer('PresenceUnifi', 30000, $this->prefix . '_UpdatePresence($_IPS[\'TARGET\']);');

        // create and enable guest portal switch
        $this->CreateVariableByIdentifier($this->InstanceID, 'WiFi: Guest Portal', false, 99, 'guest_portal');
        $this->EnableAction('guest_portal');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // read config
        $this->readConfig();

        // update data
        if ($this->user && $this->password && $this->url) {
            $this->Update();
            $this->UpdatePresence();
        }

    }

    /**
     * Request Actions
     * @param string $Ident
     * @param $Value
     * @return bool|void
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident):
            case 'wifi_guest':
                $this->UpdateGuestWifi((int)$Value);
                break;
        endswitch;
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        // controller config
        $this->url = $this->ReadPropertyString('url');
        $this->user = $this->ReadPropertyString('user');
        $this->password = $this->ReadPropertyString('password');

        // presence config
        $this->presence_enabled = $this->ReadPropertyBoolean('presence_enabled');
        $this->presence_online_time = $this->ReadPropertyInteger('presence_online_time');

        // check for configured devices
        $presence_device_found = false;
        for ($i = 1; $i <= 5; $i++) {
            $device = trim($this->ReadPropertyString('device_' . $i));
            $mac_address = strtolower(trim($this->ReadPropertyString('mac_' . $i)));

            // add to presence config
            if ($device && $mac_address && $device != 'Example iPhone' && $mac_address != '00:0A:95:9D:68:16') {
                $presence_device_found = true;
                $this->devices[$mac_address] = [
                    'name' => $device,
                    'is_online' => false
                ];
            }
        }

        // disable presence, when no valid device has been configured
        if (!$presence_device_found) {
            $this->presence_enabled = false;
        }
    }

    /**
     * read & update unifi data
     */
    public function Update()
    {
        // read config
        $this->readConfig();

        // get health data
        $health_data = $this->Api('list_health');

        // extract useful data
        foreach ($health_data AS $data) {
            foreach ($data AS $key => $value) {
                if (isset($this->data_mapper[$key])) {
                    $key = $this->data_mapper[$key];
                    $this->data[$key] = $value;
                }
            }
        }

        // log data
        IPS_LogMessage('UniFi Data', json_encode($this->data));

        // save data
        $this->SaveData();
    }

    /**
     * save tank data to variables
     */
    private function SaveData()
    {
        // loop unifi data and add variables
        $position = 0;
        foreach ($this->data AS $key => $value) {
            $this->CreateVariableByIdentifier($this->InstanceID, $key, $value, $position);
            $position++;
        }
    }

    /**
     * device presence detection
     */
    public function UpdatePresence()
    {
        // read config
        $this->readConfig();

        // check if presence is enabled
        if (!$this->presence_enabled) {
            return false;
        }

        // get clients
        $clients = $this->Api('list_clients');

        // loop clients and check device presence
        foreach ($clients AS $client) {
            $mac_address = strtolower($client->mac);
            if (isset($this->devices[$mac_address]) && $this->is_device_online($client->last_seen)) {
                $this->devices[$mac_address]['is_online'] = true;
            }
        }

        // log data
        IPS_LogMessage('UniFi Presence Data', json_encode($this->devices));

        // save data
        $this->SavePresenceData();
    }

    /**
     * Save Presence Data
     */
    private function SavePresenceData()
    {
        // create folder 'Presence'
        $category_id_presence = $this->CreateCategoryByIdentifier($this->InstanceID, 'Presences', 'Motion');

        // loop devices add variables
        $position = 0;
        foreach ($this->devices AS $mac_address => $device) {
            $this->profile_mappings[$device['name']] = 'Presence';
            $this->CreateVariableByIdentifier($category_id_presence, $device['name'], $device['is_online'], $position, $mac_address);
            $position++;
        }
    }

    /**
     * UniFi API
     * @param null $request
     * @return array
     */
    public function Api($request = null)
    {
        // login to api
        $api = new UniFi_API\Client($this->user, $this->password, $this->url);
        $login_state = $api->login();

        // login failed
        if ($login_state !== true) {
            $this->SetStatus(201);
            IPS_LogMessage('UniFi', 'Error: Could not connect to unifi controller! Please check your credentials!');
            exit(-1);
        }

        // valid request
        $this->SetStatus(102);

        // exec request
        return $api->$request();
    }

    /**
     * check if a presence timestamp - time diff is still 'online'
     * @param $timestamp
     * @return bool
     */
    private function is_device_online($timestamp)
    {
        $diff = time() - $timestamp;
        return ($diff < $this->presence_online_time * 60);
    }

    /**
     * Enables / Disables guest portal
     * @param $value
     */
    private function UpdateGuestWifi($value)
    {

    }

}