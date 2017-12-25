<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/ModuleHelper.class.php');

/**
 * Class SBFspot
 * Bridge to SBFSpot MySQL Database
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class SBFspot extends ModuleHelper
{
    protected $prefix = 'SBF';

    private $host;
    private $port;
    private $user;
    private $password;
    private $database;

    private $inverters = [];

    protected $icon_mappings = [
        'Version' => 'Information',
        'Status' => 'Information'
    ];

    protected $archive_mappings = [
        'Temperature',
        'Power Today',
        'Power'
    ];

    protected $profile_mappings = [
        'Temperature' => '~Temperature',
        'Power Today' => '~Electricity',
        'Current Power' => '~Watt.3680',
        'Status' => 'Status'
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
        $this->RegisterTimer('ReadSBFspot', $register_timer, $this->prefix . '_Update($_IPS[\'TARGET\']);');
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

    public function EnableAction($Ident)
    {
        $Ident = $this->prefix . $Ident;
        return parent::EnableAction($Ident);
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
        if (!$db = @mysqli_connect($this->host, $this->user, $this->password, $this->database, $this->port)) {
            $this->SetStatus(201);
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
            // get current inverter data
            $query = mysqli_query($db, 'SELECT * FROM `SpotData` WHERE `Serial` = "' . $inverter['Serial'] . '" AND `OperatingTime` > 0 ORDER BY `TimeStamp` DESC LIMIT 1');
            $current = mysqli_fetch_array($query, MYSQLI_ASSOC);

            // get current power data (within 15 minutes)
            $query = mysqli_query($db, 'SELECT * FROM `DayData` WHERE `Serial` = "' . $inverter['Serial'] . '" AND `Timestamp` > UNIX_TIMESTAMP(TIMESTAMPADD(MINUTE, -15, NOW())) ORDER BY `TimeStamp` DESC LIMIT 1');
            $power = mysqli_fetch_array($query, MYSQLI_ASSOC);

            // build data
            $this->inverters[$inverter['Serial']] = [
                //'Software Version' => $inverter['SW_Version'],
                //'Operating Time' => $current['OperatingTime'],
                'Status' => (bool)$inverter['Status'] == 'OK',
                'Temperature' => (float)$inverter['Temperature'],
                'Power Today' => (float)$current['EToday'] / 1000,
                'Current Power' => (float)$power['Power']
            ];
        }

        // save data
        $this->SaveData();
    }

    /**
     * save data to variables
     */
    private function SaveData()
    {
        // loop data and create categories
        foreach ($this->inverters AS $inverter_id => $data) {
            // get category id from inverter id
            $category_id_inverter = $this->CreateCategoryByIdentifier($this->InstanceID, $this->Translate('Serial:') . ' ' . $inverter_id, 'Sun');

            // loop data and add variables to tank category
            $position = 0;
            foreach ($data AS $key => $value) {
                $this->CreateVariableByIdentifier($category_id_inverter, $key, $value, $position);
                $position++;
            }
        }
    }
}