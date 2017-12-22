<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/.lib/ModuleHelper.class.php');

/**
 * Class SBFspot
 * Bridge to SBFSpot MySQL Database
 *
 * @version     0.1
 * @category    Symcon
 * @package     Oilfox driver
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/com.symcon.oilfox
 *
 */
class SBFspot extends ModuleHelper
{
    private $host;
    private $port;
    private $user;
    private $password;
    private $database;

    private $inverters = [];

    protected $archive_mappings = [
        'Temperature',
        'Earned Today',
        'Power'
    ];

    protected $profile_mappings = [
        'Temperature' => '~Temperature',
        'Power Today' => '~Electricity',
        'Current Power' => '~Power'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('host', '127.0.0.1');
        $this->RegisterPropertyInteger('port', 3306);
        $this->RegisterPropertyString('user', '');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyString('database', 'SBFspot');

        // register timer every 5 minutes
        $register_timer = 60 * 5 * 100;
        //$this->RegisterTimer('ReadSBFspot', $register_timer, 'SBF_Update($_IPS[\'TARGET\']);');
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
        if ($this->host && $this->port && $this->user && $this->password && $this->database) {
            $this->Update();
        }
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        $this->host = $this->ReadPropertyString('host');
        $this->port = $this->ReadPropertyInteger('port');
        $this->user = $this->ReadPropertyString('user');
        $this->password = $this->ReadPropertyString('password');
        $this->database = $this->ReadPropertyString('database');
    }

    /**
     * read & update data
     */
    public function Update()
    {
        // read config
        $this->readConfig();

        // connect to database
        if (!$db = mysqli_connect($this->host, $this->user, $this->password, $this->database, $this->port)) {
            IPS_LogMessage('SBFspot', 'Error: Can not connect to mysql database!');
            exit(-1);
        }

        // everything looks ok, collect data
        $this->SetStatus(102);

        // read inverters
        $query = mysqli_query($db, 'SELECT * FROM `Inverters`');
        $inverters = mysqli_fetch_all($query, MYSQLI_ASSOC);

        // loop inverters and attach data
        foreach ($inverters AS $inverter) {
            // get values from today
            $query = mysqli_query($db, 'SELECT * FROM `MonthData` WHERE `Serial` = "' . $inverter['Serial'] . '" ORDER BY `TimeStamp` DESC LIMIT 1');
            $today = mysqli_fetch_array($query, MYSQLI_ASSOC);

            // get current values
            $query = mysqli_query($db, 'SELECT * FROM `DayData` WHERE `Serial` = "' . $inverter['Serial'] . '" ORDER BY `TimeStamp` DESC LIMIT 1');
            $current = mysqli_fetch_array($query, MYSQLI_ASSOC);

            // build data
            $this->inverters[$inverter['Serial']] = [
                'Software Version' => $inverter['SW_Version'],
                'Operating Time' => $inverter['OperatingTime'],
                'Status' => $inverter['Status'],
                'Temperature' => (float)$inverter['Temperature'],
                'Power Today' => (float)$today['DayYield'] / 1000,
                'Current Power' => (float)$current['Power']
            ];
        }

        // save data
        $this->SaveData();
    }

    /**
     * save tank data to variables
     */
    public function SaveData()
    {
        // loop tanks and save data
        foreach ($this->inverters AS $inverter_id => $data) {
            // get category id from tank id
            $category_id_inverter = $this->CreateCategoryByIdentity($this->InstanceID, $inverter_id);

            // loop tank data and add variables to tank category
            foreach ($data AS $key => $value) {
                $this->CreateVariableByIdentity($category_id_inverter, $key, $value);
            }
        }
    }
}