<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/ModuleHelper.class.php');

/**
 * Class Oilfox
 * Driver to OilFox API (inofficial)
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class Oilfox extends ModuleHelper
{
    protected $prefix = 'OF';

    private $email;
    private $password;
    private $token;

    public $tanks = [];

    protected $archive_mappings = [
        'Current Level (L)',
        'Current Level (%)',
        'Current Price'
    ];

    protected $profile_mappings = [
        'Current Level (L)' => '~Water',
        'Current Level (%)' => '~Intensity.100',
        'Level next month (L)' => '~Water',
        'Level next month (%)' => '~Intensity.100',
        'Current Price' => 'Price',
        'Battery' => '~Battery.100',
        'Volume' => '~Water'
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
        $this->RegisterTimer('ReadOilfox', $register_timer, $this->prefix . '_Update($_IPS[\'TARGET\']);');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Update data
        $this->Update();
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        $this->email = $this->ReadPropertyString('email');
        $this->password = $this->ReadPropertyString('password');
        $this->token = $this->ReadPropertyString('token');
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

        // read config
        $this->ReadConfig();

        // check if email and password are provided
        if (!$this->email || !$this->password) {
            $this->SetStatus(201);
            IPS_LogMessage('OilFox', 'Error: The email address or password of your oilfox account is invalid!');
            exit(-1);
        }

        // read access token
        if (!$this->token) {
            $this->token = $this->ReadPropertyString('token');
        }

        // login if no valid token was provided
        if (!$this->token) {
            $this->Login();
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
            $current = is_array($tank_history) ? end($tank_history) : null;

            // get battery state
            $tank_battery = $this->Api('oilfox/battery/' . $tank['id']);

            // get forecast values
            $forecast = $this->Api('sandy/forecast/' . $tank['id']);
            $forecast = is_array($forecast) ? reset($forecast) : null;

            // map data
            $this->tanks[$tank['id']] = [
                'Name' => $tank['name'],
                'Oil Type' => $tank['productType'],
                'Volume' => (float)$tank['volume'],
                'Current Level (L)' => (float)$current['liters'],
                'Current Level (%)' => (int)$current['fillingpercentage'],
                'Level next month (L)' => (float)$forecast['liters'],
                'Level next month (%)' => (int)$forecast['fillingpercentage'],
                'Battery' => (int)$tank_battery['percentage'],
                'Current Price' => (float)$current_price['price']
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
    private function SaveData()
    {
        // loop tanks and save data
        foreach ($this->tanks AS $tank_id => $data) {
            // get category id from tank id
            $category_id_tank = $this->CreateCategoryByIdentifier($this->InstanceID, $tank_id, $data['Name']);

            // loop tank data and add variables to tank category
            $position = 0;
            foreach ($data AS $key => $value) {
                $this->CreateVariableByIdentifier($category_id_tank, $key, $value, $position);
                $position++;
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