<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/.helpers/ModuleHelper.class.php');

/**
 * Class Oilfox
 * Driver to OilFox API (inofficial)
 *
 * @version     0.1
 * @category    Symcon
 * @package     Oilfox driver
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/com.symcon.oilfox
 *
 */
class Oilfox extends ModuleHelper
{
    private $email;
    private $password;
    private $token;

    public $tanks = [];

    protected $archive_mappings = [
        'currentLiters',
        'currentFillingPercentage',
        'currentPrice',
        'batteryPercentage'
    ];

    protected $profile_mappings = [
        'currentLiters' => '~Water',
        'currentPrice' => 'Price',
        'currentFillingPercentage' => '~Intensity.100',
        'batteryPercentage' => '~Battery.100',
        'maxVolume' => '~Water',
        'volume' => '~Water',
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('email', 'user@email.com');
        $this->RegisterPropertyString('password', '');

        // register private properties
        $this->RegisterPropertyString('token', '');

        // register timer every 6 hours
        $register_timer = 60 * 60 * 6 * 100;
        $this->RegisterTimer('ReadOilfox', $register_timer, 'OF_Update($_IPS[\'TARGET\']);');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // read email & password from config
        $this->email = $this->ReadPropertyString('email');
        $this->password = $this->ReadPropertyString('password');

        // login
        if ($this->email && $this->password) {
            $this->Login();

            // update data
            $this->Update();
        }
    }

    /**
     * read & update tank data
     */
    public function Update()
    {
        // return if service or internet connection is not available
        if (!Sys_Ping('oilfox.io', 1000)) {
            IPS_LogMessage('OilFox', 'Error: Oilfox api or internet connection not available!');
            exit(-1);
        }

        // read access token
        if (!$this->token) {
            $this->token = $this->ReadPropertyString('token');
        }

        // simple error handling
        if (!$this->token) {
            $this->SetStatus(201);
            IPS_LogMessage('OilFox', 'Error: The email address or password of your oilfox account is invalid!');
            exit(-1);
        }

        // everything looks ok, start
        $this->SetStatus(102);

        // get tank data
        $tanks = $this->Api('user/summary');

        // get current prices
        $prices = $this->Api('oil/price');
        $current_price = end($prices);

        // loop each tank
        foreach ($tanks['tanks'] AS &$tank) {
            // get tank history
            $tank_history = $this->Api('tank/' . $tank['id'] . '/fillinghistory');

            // extract current values
            $current = end($tank_history);

            // get battery state
            $tank_battery = $this->Api('oilfox/battery/' . $tank['id']);

            // map data
            $this->tanks[$tank['id']] = [
                'name' => $tank['name'],
                'volume' => (float)$tank['volume'],
                'currentLiters' => (float)$current['liters'],
                'currentFillingPercentage' => (int)$current['fillingpercentage'],
                'distanceFromTankToOilFox' => (int)$tank['distanceFromTankToOilFox'],
                'maxVolume' => (float)$tank['maxVolume'],
                'isUsableVolume' => (bool)$tank['isUsableVolume'],
                'productType' => $tank['productType'],
                'batteryPercentage' => (int)$tank_battery['percentage'],
                'currentPrice' => (float)$current_price['price']
            ];
        }

        // log data
        IPS_LogMessage('OilFox Data', json_encode($this->tanks));

        // save data
        $this->SaveData();
    }

    /**
     * save tank data to variables
     */
    public function SaveData()
    {
        // loop tanks and save data
        foreach ($this->tanks AS $tank_id => $data) {
            // get category id from tank id
            $category_id_tank = $this->CreateCategoryByIdentity($this->InstanceID, $tank_id);

            // loop tank data and add variables to tank category
            foreach ($data AS $key => $value) {
                $this->CreateVariableByIdentity($category_id_tank, $key, $value);
            }
        }
    }

    /**
     * basic api to oilfox (inofficial)
     * @param null $request
     * @return mixed
     */
    public function Api($request = null)
    {
        // build url
        $url = 'https://api.oilfox.io/v1/' . $request;

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Auth-Token: ' . $this->token,
                'Content-Type: application/json',
                'Connection: Keep-Alive',
                'Accept-Encoding: gzip',
                'User-Agent: okhttp/3.2.0'
            ]
        ];

        // call api
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        curl_close($ch);

        // return result
        return json_decode($result, true);
    }

    /**
     * Login to oilfox
     */
    public function Login()
    {
        IPS_LogMessage('OilFox', sprintf('Logging in to oilfox account of %s...', $this->email));

        // login url
        $url = 'https://api.oilfox.io/v1/user/login';

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $this->email,
                'password' => $this->password
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Connection: Keep-Alive',
                'Accept-Encoding: gzip',
                'User-Agent: okhttp/3.2.0'
            ]
        ];

        // login
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        curl_close($ch);

        // extract token
        $json = json_decode($result, true);
        $this->token = isset($json['token']) ? $json['token'] : false;

        // save valid token
        if ($this->token) {
            $this->SetStatus(102);
            IPS_SetProperty($this->InstanceID, 'token', $this->token);
        } // simple error handling
        else {
            $this->SetStatus(201);
            IPS_LogMessage('OilFox', 'Error: The email address or password of your oilfox account is invalid!');
            exit(-1);
        }
    }
}