<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/ModuleHelper.class.php');

/**
 * Class BatteryMonitor
 * Symcon SSH Module
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class BatteryMonitor extends ModuleHelper
{
    protected $prefix = 'BatteryMonitor';

    private $data = [];
    protected $profile_mappings = [];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register timer once a day
        $register_timer = 60 * 60 * 24 * 100;
        $this->RegisterTimer('ReadBatteryMonitor', $register_timer, $this->prefix . '_Update($_IPS[\'TARGET\']);');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Update
        $this->Update();
    }


    /**
     * Update battery data
     */
    public function Update()
    {
        // get all objects
        $object_ids = IPS_GetObjectList();

        foreach ($object_ids AS $object_id) {
            // continue on own object id
            if ($object_id == $this->InstanceID) {
                continue;
            }

            // get object
            $object = IPS_GetObject($object_id);

            // when object has children, proceed
            if ($object['HasChildren']) {
                // get object variables
                $battery_variables = [];
                foreach ($object['ChildrenIDs'] AS $variable_id) {
                    if ($variable = @IPS_GetVariable($variable_id)) {
                        // get battery values
                        if ($variable['VariableProfile'] == '~Battery' || $variable['VariableCustomProfile'] == '~Battery') {
                            $battery_variables['status'] = $variable['VariableValue'];
                        } else if (in_array($variable['VariableCustomProfile'], ['~Battery.100', '~Intensity.100']) || in_array($variable['VariableProfile'], ['~Battery.100', '~Intensity.100'])) {
                            $battery_variables['intensity'] = $variable['VariableValue'];
                        }

                        // detect battery profile
                        if (strstr($variable['VariableProfile'], '~Battery') || strstr($variable['VariableCustomProfile'], '~Battery')) {
                            $battery_variables['has_battery'] = true;
                        }
                    }
                }
                // when battery variables equals 2, add to data array
                if (isset($battery_variables['has_battery'])) {
                    $this->data[] = [
                        'id' => $object['ObjectID'],
                        'name' => isset($battery_variables['name']) ? $battery_variables['name'] : $object['ObjectName'],
                        'status' => isset($battery_variables['intensity']) ? $battery_variables['intensity'] : $battery_variables['status']
                    ];
                }
            }
        }

        // log data
        IPS_LogMessage('BatteryMonitor', json_encode($this->data));

        // save data
        $this->SaveData();
    }

    /**
     * save battery data to variables
     */
    private function SaveData()
    {
        // loop battery data and save variables
        foreach ($this->data AS $data) {
            $this->profile_mappings[$data['name']] = is_bool($data['status']) ? '~Battery' : '~Battery.100';
            $this->CreateVariableByIdentifier($this->InstanceID, $data['name'], $data['status'], 0, $data['id']);
        }
    }
}