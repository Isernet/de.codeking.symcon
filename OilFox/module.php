<?php

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
class Oilfox extends IPSModule
{
    private $email;
    private $password;
    private $token;

    public $tanks = [];

    private $archive_id;
    private $archive_mappings = [
        'currentLiters',
        'currentFillingPercentage',
        'currentPrice',
        'batteryPercentage'
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
        $this->Login();

        // update data
        $this->Update();
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
        $this->token = $this->ReadPropertyString('token');

        // simple error handling
        if (!$this->token) {
            $this->SetStatus(201);
            IPS_LogMessage('OilFox', 'Error: The email address or password of your oilfox account is invalid!');
            exit(-1);
        }

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
                'volume' => (int)$tank['volume'],
                'currentLiters' => (int)$current['liters'],
                'currentFillingPercentage' => (int)$current['fillingpercentage'],
                'distanceFromTankToOilFox' => (int)$tank['distanceFromTankToOilFox'],
                'maxVolume' => (int)$tank['maxVolume'],
                'isUsableVolume' => (bool)$tank['isUsableVolume'],
                'productType' => $tank['productType'],
                'batteryPercentage' => (float)$tank_battery['percentage'],
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
        $token = isset($json['token']) ? $json['token'] : false;

        // save valid token
        if ($token) {
            $this->SetStatus(102);
            IPS_SetProperty($this->InstanceID, 'token', $token);
        } // simple error handling
        else {
            $this->SetStatus(201);
            IPS_LogMessage('OilFox', 'Error: The email address or password of your oilfox account is invalid!');
            exit(-1);
        }
    }

    /**
     * creates a category by itentifier
     * @param $id
     * @param $name
     * @return mixed
     */
    private function CreateCategoryByIdentity($id, $name)
    {
        // set identifier
        $identifier = $this->identifier($name);

        // get category id, if exists
        $category_id = @IPS_GetObjectIDByIdent($identifier, $id);

        // if category doesn't exist, create it!
        if ($category_id === false) {
            $category_id = IPS_CreateCategory();
            IPS_SetParent($category_id, $id);
            IPS_SetName($category_id, $name);
            IPS_SetIdent($category_id, $identifier);
        }

        // return category id
        return $category_id;
    }

    /**
     * creates a category by itentifier or updates its data
     * @param $id
     * @param $name
     * @param $value
     * @return mixed
     */
    private function CreateVariableByIdentity($id, $name, $value)
    {
        // set identifier
        $identifier = $this->identifier($name);

        // get archive id
        if (!$this->archive_id) {
            $this->archive_id = IPS_GetObjectIDByName('Archive', 0);
        }

        // get category id, if exists
        $variable_id = @IPS_GetObjectIDByIdent($identifier, $id);

        // if variable doesn't exist, create it!
        if ($variable_id === false) {
            // set type of variable
            $type = $this->GetVariableType($value);

            // create variable
            $variable_id = IPS_CreateVariable($type);
            IPS_SetParent($variable_id, $id);
            IPS_SetName($variable_id, $name);
            IPS_SetIdent($variable_id, $identifier);

            // enable archive
            if (in_array($name, $this->archive_mappings)) {
                AC_SetLoggingStatus($this->archive_id, $variable_id, true);
                IPS_ApplyChanges($this->archive_id);
            }
        }

        // set value
        SetValue($variable_id, $value);

        // return variable id
        return $variable_id;
    }

    /**
     * replaces special chars for identifier
     * @param $text
     * @return mixed
     */
    private function identifier($text)
    {
        $text = str_replace("-", "_", $text);
        return $text;
    }

    /**
     * get variable type by contents
     * @param $value
     * @return int
     */
    private function GetVariableType($value)
    {
        if (is_bool($value)) {
            $type = 0;
        } else if (is_int($value)) {
            $type = 1;
        } else if (is_float($value)) {
            $type = 2;
        } else {
            $type = 3;
        }

        return $type;
    }
}