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

    public $data = [];
    public $unsupported = [];

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

        // register private properties
        $this->RegisterPropertyString('unsupported', '[]');

        // register timers
        $this->RegisterTimer('SMAValues', 0, $this->prefix . '_UpdateValues($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SMACurrent', 10000, $this->prefix . '_UpdateCurrent($_IPS[\'TARGET\']);');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // update timer
        $this->SetTimerInterval('SMAValues', $this->ReadPropertyInteger('interval') * 1000);

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
        $this->unsupported = json_decode($this->ReadPropertyString('unsupported'), true);

        // check config
        if (!$this->ip || !$this->port) {
            $this->SetStatus(201);
            exit(-1);
        }

        // create modbus instance
        if ($this->ip && $this->port) {
            $this->modbus = new ModbusMaster($this->ip, 'TCP');
            $this->modbus->port = $this->port;

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
        $this->update = 'values';
        $this->ReadData(SMARegister::value_addresses);
    }

    /**
     * update current values, only
     */
    public function UpdateCurrent()
    {
        $this->update = 'current';
        $this->ReadData(SMARegister::current_addresses);
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
    public function ReadData($addresses)
    {
        // read config
        $this->ReadConfig();

        // read data
        foreach ($addresses AS $address => $config) {
            try {
                // continue on unsupported registers
                if (in_array($address, $this->unsupported)) {
                    continue;
                }

                // read register
                $value = $this->modbus->readMultipleRegisters($this->unit_id, (int)$address, $config['count']);

                if (in_array($config['format'], ['ENUM', 'FIX0', 'FIX1', 'FIX2', 'FIX3'])) {
                    // convert signed value
                    if (substr($config['type'], 0, 1) == 'S') {
                        // fix bytes
                        $value = array_chunk($value, 2)[1];

                        // convert to signed int
                        $value = PhpType::bytes2signedInt($value);
                    } // convert unsigned value
                    else if (substr($config['type'], 0, 1) == 'U') {
                        // fix bytes
                        $value = array_chunk($value, 2)[1];

                        // convert to unsigned int
                        $value = PhpType::bytes2unsignedInt($value);
                    }
                }

                // set value to 0 if it is negative
                if ((is_int($value) || is_float($value)) && $value < 0) {
                    $value = (float)0;
                }

                // continue if value is still an array
                if (is_array($value)) {
                    continue;
                }

                // map value
                if (isset($config['mapping'][$value])) {
                    $value = $config['mapping'][$value];
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

                // append data
                $this->data[$config['name']] = $value;
            } catch (Exception $e) {
                // set register as unsupported für current device
                $this->unsupported[] = $address;
            }
        }

        // save unsupported registers
        IPS_SetProperty($this->InstanceID, 'unsupported', json_encode($this->unsupported));
        IPS_ApplyChanges($this->InstanceID);

        // save data
        $this->SaveData();
    }

}