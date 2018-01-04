<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/ModuleHelper.class.php');

/**
 * Class PilightIO
 * IP-Symcon pilight module // I/O Device
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class PilightIO extends ModuleHelper
{
    protected $prefix = 'pilight';

    const guid_device = '{F23A8B74-94F3-41FD-9A26-7C9B17EA86E5}';
    const guid_send = '{97721D61-0F87-4CE8-B10D-9E1963D9563B}';

    private $ip;
    private $port;
    private $use_ssl;
    private $user;
    private $pass;
    private $category_id;

    public $devices = [];
    private $devices_found = 0;

    protected $profile_mappings = [
        'State' => '~Switch',
        'Intensity' => '~Intensity'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('ip', '');
        $this->RegisterPropertyInteger('port', 5001);
        $this->RegisterPropertyBoolean('use_ssl', false);
        $this->RegisterPropertyString('user', '');
        $this->RegisterPropertyString('pass', '');
        $this->RegisterPropertyInteger('category_id', 0);
        $this->RegisterPropertyInteger('interval', 15);

        // register update timer
        $this->RegisterTimer('UpdatePilight', 0, 'PilightIO_UpdateDevices($_IPS[\'TARGET\']);');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // update timer
        $this->SetTimerInterval('UpdatePilight', $this->ReadPropertyInteger('interval') * 1000);

        // simple api validation
        $this->Api();
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        $this->ip = $this->ReadPropertyString('ip');
        $this->port = $this->ReadPropertyInteger('port');
        $this->use_ssl = $this->ReadPropertyBoolean('use_ssl');
        $this->user = $this->ReadPropertyString('user');
        $this->pass = $this->ReadPropertyString('pass');
        $this->category_id = $this->ReadPropertyInteger('category_id');
    }

    /**
     * Read devices from pilight
     */
    public function ReadDevices()
    {
        // get devices
        $this->GetDevices();

        // save / update devices
        $this->SaveDevices();

        // output device info
        echo sprintf($this->Translate($this->devices_found == 1 ? '%d device found!' : '%d devices found!'), $this->devices_found);
    }

    /**
     * Update device variables
     */
    public function UpdateDevices()
    {
        // get devices
        $this->getDevices();

        // build data
        $data = [
            'DataID' => self::guid_send,
            'Devices' => $this->devices
        ];

        // send data to childrens
        $this->SendDataToChildren(json_encode($data));
    }

    /**
     * get all devices from pilight config
     */
    public function GetDevices()
    {
        // get config
        if ($config = $this->Api('config')) {
            // parse devices
            if (isset($config['devices']) && is_array($config['devices'])) {
                // loop devices & attach them
                foreach ($config['devices'] AS $name => $device) {
                    $this->devices[$name] = [
                        'State' => ($device['state'] == 'on')
                    ];
                    $this->devices_found++;
                }
            }
        }
    }

    /**
     * Save devices
     */
    public function SaveDevices()
    {
        foreach ($this->devices AS $id => $variables) {
            // create device instance
            $instance_id = $this->CreateInstanceByIdentifier(self::guid_device, $this->category_id, $id);
            IPS_SetProperty($instance_id, 'Identifier', $id);

            // create device variables
            foreach ($variables AS $key => $value) {
                $identifier = $instance_id . '_' . $key;
                $this->CreateVariableByIdentifier($instance_id, $key, $value, 0, $identifier);
            }

            // apply instance changes
            IPS_ApplyChanges($instance_id);
        }
    }

    /**
     * Pilight API
     * @param string $request
     * @param array params
     * @return array|bool
     */
    private function Api($request = 'check', $params = [])
    {
        // read config
        $this->readConfig();

        // check minimal config
        if (!$this->ip || !$this->port) {
            return false;
        }

        // check api
        $api_check = false;
        if ($request == 'check') {
            $api_check = true;
            $request = 'config';
        }

        // build url
        $url = 'http' . ($this->use_ssl ? 's' : '') . '://';
        $url .= $this->ip . ':' . $this->port . '/';
        $url .= $request;

        // build params
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Connection: Keep-Alive',
                'Accept-Encoding: gzip',
                'User-Agent: IP_Symcon'
            ]
        ];

        // authentication
        if ($this->user && $this->pass) {
            $curlOptions[CURLOPT_USERPWD] = $this->user . ':' . $this->pass;
        }

        // call api
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($params) {
            IPS_LogMessage('pilight response', $response);
        }

        // convert json data to array
        $response = @json_decode($response, true);

        // check api
        if ($api_check) {
            if ($http_code == 200 && json_last_error() == JSON_ERROR_NONE) {
                $this->SetStatus(102); // valid login
                return true;
            } else {
                $this->SetStatus(201); // invalid login
                return false;
            }
        }

        // return response
        return $response;
    }

    /**
     * Update pilight values from children request
     * @param string $JSONString
     * @return string|void
     */
    public function ForwardData($JSONString)
    {
        // convert json data to array
        $data = json_decode($JSONString, true);

        // update pilight value
        $this->Api('control', [
            'device' => $data['Device'],
            'state' => $data['Value'] ? 'on' : 'off'
        ]);

        IPS_LogMessage('pilight request', $JSONString);
    }

}