<?php

/**
 * Class ModuleHelper
 * Symcon Helper Methods
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class ModuleHelper extends IPSModule
{
    private $archive_id;

    protected $archive_mappings = [];
    protected $profile_mappings = [];
    protected $hidden_mappings = [];

    /**
     * creates a category by itentifier
     * @param $id
     * @param $name
     * @return mixed
     */
    protected function CreateCategoryByIdentity($id, $name)
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
     * @param $identifier
     * @return mixed
     */
    protected function CreateVariableByIdentity($id, $name, $value, $position = 0, $identifier = false)
    {
        // remove whitespaces
        $name = trim($name);
        if (is_string($value)) {
            $value = trim($value);
        }

        // set identifier
        $has_identifier = true;
        if (!$identifier) {
            $has_identifier = false;
            $identifier = $name;
        }
        $identifier = $this->identifier($identifier);

        // get archive id
        if (!$this->archive_id) {
            $this->archive_id = IPS_GetObjectIDByName('Archive', 0);
        }

        // get variable id, if exists
        $variable_created = false;
        $variable_id = @IPS_GetObjectIDByIdent($identifier, $id);

        // if variable doesn't exist, create it!
        if ($variable_id === false) {
            $variable_created = true;

            // set type of variable
            $type = $this->GetVariableType($value);

            // create variable
            $variable_id = IPS_CreateVariable($type);
            IPS_SetParent($variable_id, $id);
            IPS_SetIdent($variable_id, $identifier);

            // hide visibility
            if (in_array($name, $this->hidden_mappings)) {
                IPS_SetHidden($variable_id, true);
            }

            // enable archive
            if (in_array($name, $this->archive_mappings)) {
                AC_SetLoggingStatus($this->archive_id, $variable_id, true);
                IPS_ApplyChanges($this->archive_id);
            }

            // set profile
            if (isset($this->profile_mappings[$name])) {
                // create profile, if not exists
                if (!IPS_VariableProfileExists($this->profile_mappings[$name])) {
                    $this->CreateCustomVariableProfile($this->profile_mappings[$name]);
                }

                IPS_SetVariableCustomProfile($variable_id, $this->profile_mappings[$name]);
            }
        }

        // set name & position
        if ($variable_created || $has_identifier) {
            IPS_SetName($variable_id, $name);
        }
        IPS_SetPosition($variable_id, $position);

        // set value
        SetValue($variable_id, $value);

        // return variable id
        return $variable_id;
    }

    /**
     * replaces special chars for identifier
     * @param $identifier
     * @return mixed
     */
    private function identifier($identifier)
    {
        $identifier = strtr($identifier, [
            '-' => '_',
            ' ' => '_',
            ':' => '_',
            '(' => '',
            ')' => '',
            '%' => 'p'
        ]);

        return $identifier;
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

    /**
     * create custom variable profile
     * @param $name
     */
    private function CreateCustomVariableProfile($name)
    {
        switch ($name):
            case 'Price':
                IPS_CreateVariableProfile($name, 2); // float
                IPS_SetVariableProfileDigits($name, 2); // 2 decimals
                IPS_SetVariableProfileText($name, '', ' €'); // currency symbol
                break;
            case 'Latency':
                IPS_CreateVariableProfile($name, 1); // integer
                IPS_SetVariableProfileText($name, '', ' ms'); // milliseconds
                break;
            case 'MBit':
                IPS_CreateVariableProfile($name, 2); // float
                IPS_SetVariableProfileDigits($name, 2); // 2 decimals
                IPS_SetVariableProfileText($name, '', ' MBit'); // MBit
                break;
        endswitch;
    }
}