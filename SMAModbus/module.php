<?php

define('__ROOT__', dirname(dirname(__FILE__)));
define('__MODULE__', dirname(__FILE__));

require_once(__ROOT__ . '/libs/ModuleHelper.class.php');
require_once(__ROOT__ . '/libs/phpmodbus/Phpmodbus/ModbusMaster.php');
require_once(__MODULE__ . '/SMARegister.php');

/**
 * Class SMA_Modbus
 * IP-Symcon SMA Modbus Module
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class SMAModbus extends ModuleHelper
{
    protected $prefix = 'SMA';

    private $ip;
    private $port;
    private $unit_id = 3;

    private $modbus;
    private $update = true;
    private $isDay;

    public $data = [];

    protected $profile_mappings = [];
    protected $archive_mappings = [];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('ip', '');
        $this->RegisterPropertyInteger('port', 502);
        $this->RegisterPropertyInteger('unit_id', 3);
        $this->RegisterPropertyInteger('interval', 300);
        $this->RegisterPropertyInteger('interval_current', 30);

        // register timers
        $this->RegisterTimer('SMAValues', 0, $this->prefix . '_UpdateValues($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SMACurrent', 0, $this->prefix . '_UpdateCurrent($_IPS[\'TARGET\']);');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // update timer
        $this->SetTimerInterval('SMAValues', $this->ReadPropertyInteger('interval') * 1000);
        $this->SetTimerInterval('SMACurrent', $this->ReadPropertyInteger('interval_current') * 1000);

        // read & check config
        $this->ReadConfig();
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        // read config
        $this->ip = $this->ReadPropertyString('ip');
        $this->port = $this->ReadPropertyInteger('port');
        $this->unit_id = $this->ReadPropertyInteger('unit_id');

        // check config
        if (!$this->ip || !$this->port) {
            $this->SetStatus(201);
            exit(-1);
        }

        // create modbus instance
        if ($this->ip && $this->port) {
            $this->modbus = new ModbusMaster($this->ip, 'TCP');
            $this->modbus->port = $this->port;
            $this->modbus->endianness = 0;

            // check register on apply changes in configuration
            if ($_IPS['SENDER'] == 'RunScript') {
                try {
                    $this->modbus->readMultipleRegisters($this->unit_id, (int)30051, 2);
                } catch (Exception $e) {
                    $this->SetStatus(202);
                    exit(-1);
                }
            }
        }

        // status ok
        $this->SetStatus(102);
    }

    /**
     * Update everything
     */
    public function Update()
    {
        $this->UpdateDevice();
        $this->UpdateValues();
    }

    /**
     * read & update device registers
     */
    public function UpdateDevice()
    {
        $this->update = 'device';
        $this->ReadData(SMARegister::device_addresses);

        if ($_IPS['SENDER'] == 'RunScript') {
            echo sprintf($this->Translate('%s %s has been detected.'), $this->Translate($this->data['Device class']), $this->data['Device-ID']);
        }
    }

    /**
     * read & update update registers
     */
    public function UpdateValues()
    {
        if ($this->_isDay() || $_IPS['SENDER'] == 'RunScript') {
            $this->update = 'values';
            $this->ReadData(SMARegister::value_addresses);
        }
    }

    /**
     * update current values, only
     */
    public function UpdateCurrent()
    {
        if ($this->_isDay() || $_IPS['SENDER'] == 'RunScript') {
            $this->update = 'current';
            $this->ReadData(SMARegister::current_addresses);
        }
    }

    /**
     * save data to variables
     */
    private function SaveData()
    {
        // loop data and create variables
        $position = ($this->update == 'values') ? count(SMARegister::device_addresses) : 0;
        foreach ($this->data AS $key => $value) {
            $this->CreateVariableByIdentifier($this->InstanceID, $key, $value, $position);

            if ($this->update != 'current') {
                $position++;
            }
        }
    }

    /**
     * read data via modbus
     * @param $addresses
     */
    private function ReadData($addresses)
    {
        IPS_LogMessage('SMA', json_encode($_IPS));

        // read config
        $this->ReadConfig();

        // read data
        foreach ($addresses AS $address => $config) {
            try {
                // wait some time before continue
                if (count($addresses) > 2) {
                    IPS_Sleep(500);
                }

                // read register
                $value = $this->modbus->readMultipleRegisters($this->unit_id, (int)$address, $config['count']);

                // set endianness
                $endianness = ($config['format'] == 'RAW') ? 2 : 0;

                // fix bytes
                $value = $config['format'] == 'RAW'
                    ? array_chunk($value, 4)[0]
                    : array_chunk($value, 2)[1];

                // convert signed value
                if (substr($config['type'], 0, 1) == 'S') {
                    // convert to signed int
                    $value = PhpType::bytes2signedInt($value, $endianness);
                } // convert unsigned value
                else if (substr($config['type'], 0, 1) == 'U') {
                    // convert to unsigned int
                    $value = PhpType::bytes2unsignedInt($value, $endianness);
                }

                // set value to 0 if value is negative or invalid
                if ((is_int($value) || is_float($value)) && $value < 0 || $value == 65535) {
                    $value = (float)0;
                }

                // continue if value is still an array
                if (is_array($value)) {
                    continue;
                }

                // map value
                if (isset($config['mapping'][$value])) {
                    $value = $this->Translate($config['mapping'][$value]);
                } // convert decimals
                elseif ($config['format'] == 'FIX0') {
                    $value = (float)$value;
                } elseif ($config['format'] == 'FIX1') {
                    $value /= (float)10;
                } elseif ($config['format'] == 'FIX2') {
                    $value /= (float)100;
                } elseif ($config['format'] == 'FIX3') {
                    $value /= (float)1000;
                }

                // set profile
                if (isset($config['profile']) && !isset($this->profile_mappings[$config['name']])) {
                    $this->profile_mappings[$config['name']] = $config['profile'];
                }

                // set archive
                if (isset($config['archive']) && $config['archive'] === true) {
                    $this->archive_mappings[] = $config['name'];
                }

                // append data
                $this->data[$config['name']] = $value;
            } catch (Exception $e) {
            }
        }

        // save data
        $this->SaveData();
    }

    /**
     * detect if it's daytime
     * @return bool
     */
    private function _isDay()
    {
        if (is_null($this->isDay)) {
            // default value
            $this->isDay = false;

            // get location module
            $location_instances = IPS_GetInstanceListByModuleID('{45E97A63-F870-408A-B259-2933F7EABF74}');
            $location_id = $location_instances[0];

            // get all location variables
            $location_variables = IPS_GetChildrenIDs($location_id);

            // search for isDay variable
            foreach ($location_variables AS $variable_id) {
                if ($variable = IPS_GetObject($variable_id)) {
                    if ($variable['ObjectID'] == 'isDay') {
                        $this->isDay = GetValue($variable['ObjectID']);
                    }
                }
            }
        }

        // if it's day, enable current values timer, otherwise disable it!
        if ($this->isDay) {
            $this->SetTimerInterval('SMACurrent', $this->ReadPropertyInteger('interval_current') * 1000);
        } else {
            $this->SetTimerInterval('SMACurrent', 0);
        }

        // return value
        return $this->isDay;
    }

}