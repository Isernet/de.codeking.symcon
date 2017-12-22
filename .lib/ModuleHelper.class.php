<?php

/**
 * Class ModuleHelper
 * Several helper methods
 */
class ModuleHelper extends IPSModule
{
    private $archive_id;
    private $archive_mappings = [];

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
     * @return mixed
     */
    protected function CreateVariableByIdentity($id, $name, $value)
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

            // set profile
            if (isset($this->profile_mappings[$name])) {
                // create profile, if not exists
                if (!IPS_VariableProfileExists($this->profile_mappings[$name])) {
                    $this->CreateCustomVariableProfile($this->profile_mappings[$name]);
                }

                IPS_SetVariableCustomProfile($variable_id, $this->profile_mappings[$name]);
            }
        }

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
            ' ' => '_'
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
                IPS_SetVariableProfileText($name, '', ' €'); // EUR suffix
                break;
        endswitch;
    }
}